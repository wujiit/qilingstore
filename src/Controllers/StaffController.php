<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class StaffController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);

        $sql = 'SELECT s.*, u.username, u.email, u.role_key AS user_role_key, st.store_name
                FROM qiling_staff s
                INNER JOIN qiling_users u ON u.id = s.user_id
                LEFT JOIN qiling_stores st ON st.id = s.store_id';

        $params = [];
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE s.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }

        $sql .= ' ORDER BY s.id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(['data' => $rows]);
    }

    public static function create(): void
    {
        $actor = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($actor);
        $data = Request::jsonBody();

        $username = Request::str($data, 'username');
        $email = Request::str($data, 'email');
        $password = Request::str($data, 'password');

        if ($username === '' || $email === '' || $password === '') {
            Response::json(['message' => 'username, email, password are required'], 422);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['message' => 'email format is invalid'], 422);
            return;
        }
        if (strlen($password) < 6) {
            Response::json(['message' => 'password must be at least 6 chars'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $roleKey = Request::str($data, 'role_key', 'consultant');
            if (!self::isSupportedRoleKey($roleKey)) {
                $pdo->rollBack();
                Response::json(['message' => 'invalid role_key'], 422);
                return;
            }
            if (!DataScope::isAdmin($actor) && self::isPrivilegedRoleKey($roleKey)) {
                $pdo->rollBack();
                Response::json(['message' => 'forbidden: cannot create privileged account'], 403);
                return;
            }

            $exists = $pdo->prepare('SELECT id FROM qiling_users WHERE username = :username OR email = :email LIMIT 1 FOR UPDATE');
            $exists->execute(['username' => $username, 'email' => $email]);
            if ($exists->fetchColumn()) {
                $pdo->rollBack();
                Response::json(['message' => 'username or email already exists'], 409);
                return;
            }

            $now = gmdate('Y-m-d H:i:s');

            $insertUser = $pdo->prepare(
                'INSERT INTO qiling_users (username, email, password_hash, role_key, status, wp_user_id, created_at, updated_at)
                 VALUES (:username, :email, :password_hash, :role_key, :status, :wp_user_id, :created_at, :updated_at)'
            );
            $insertUser->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role_key' => $roleKey,
                'status' => 'active',
                'wp_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $userId = (int) $pdo->lastInsertId();

            $insertStaff = $pdo->prepare(
                'INSERT INTO qiling_staff (user_id, store_id, role_key, staff_no, phone, title, status, created_at, updated_at)
                 VALUES (:user_id, :store_id, :role_key, :staff_no, :phone, :title, :status, :created_at, :updated_at)'
            );
            $storeId = DataScope::resolveInputStoreId($actor, Request::int($data, 'store_id', 0));
            $insertStaff->execute([
                'user_id' => $userId,
                'store_id' => $storeId,
                'role_key' => $roleKey,
                'staff_no' => Request::str($data, 'staff_no'),
                'phone' => Request::str($data, 'phone'),
                'title' => Request::str($data, 'title'),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $staffId = (int) $pdo->lastInsertId();

            Audit::log((int) $actor['id'], 'staff.create', 'staff', $staffId, 'Create staff', ['user_id' => $userId]);

            $pdo->commit();
            Response::json(['staff_id' => $staffId, 'user_id' => $userId], 201);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create staff failed', $e);
        }
    }

    public static function update(): void
    {
        $actor = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($actor);
        $data = Request::jsonBody();

        $staffId = Request::int($data, 'id', 0);
        if ($staffId <= 0) {
            Response::json(['message' => 'id is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT s.*, u.id AS user_id, u.role_key AS user_role_key
             FROM qiling_staff s
             INNER JOIN qiling_users u ON u.id = s.user_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            Response::json(['message' => 'staff not found'], 404);
            return;
        }

        $currentStoreId = (int) ($row['store_id'] ?? 0);
        DataScope::assertStoreAccess($actor, $currentStoreId);

        $storeIdInput = array_key_exists('store_id', $data)
            ? Request::int($data, 'store_id', $currentStoreId)
            : $currentStoreId;
        $storeId = DataScope::resolveInputStoreId($actor, $storeIdInput, true);
        if ($storeId <= 0) {
            $storeId = $currentStoreId;
        }

        $status = Request::str($data, 'status', (string) ($row['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            Response::json(['message' => 'status must be active or inactive'], 422);
            return;
        }

        $staffRoleKeyInput = Request::str($data, 'role_key');
        $staffRoleKey = $staffRoleKeyInput !== '' ? $staffRoleKeyInput : (string) ($row['role_key'] ?? 'consultant');
        $userRoleKeyInput = Request::str($data, 'user_role_key');
        $userRoleKey = $userRoleKeyInput !== '' ? $userRoleKeyInput : (string) ($row['user_role_key'] ?? 'consultant');
        if (!self::isSupportedRoleKey($staffRoleKey) || !self::isSupportedRoleKey($userRoleKey)) {
            Response::json(['message' => 'invalid role_key'], 422);
            return;
        }

        $actorIsAdmin = DataScope::isAdmin($actor);
        $currentUserRole = (string) ($row['user_role_key'] ?? '');
        if (!$actorIsAdmin) {
            if (self::isPrivilegedRoleKey($currentUserRole)) {
                Response::json(['message' => 'forbidden: cannot edit privileged account'], 403);
                return;
            }
            if (self::isPrivilegedRoleKey($staffRoleKey) || self::isPrivilegedRoleKey($userRoleKey)) {
                Response::json(['message' => 'forbidden: cannot assign privileged role'], 403);
                return;
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE qiling_staff
                 SET store_id = :store_id,
                     role_key = :role_key,
                     staff_no = :staff_no,
                     phone = :phone,
                     title = :title,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $staffId,
                'store_id' => $storeId,
                'role_key' => $staffRoleKey,
                'staff_no' => Request::str($data, 'staff_no', (string) ($row['staff_no'] ?? '')),
                'phone' => Request::str($data, 'phone', (string) ($row['phone'] ?? '')),
                'title' => Request::str($data, 'title', (string) ($row['title'] ?? '')),
                'status' => $status,
                'updated_at' => $now,
            ]);

            if ($userRoleKey !== '') {
                $updateUser = $pdo->prepare('UPDATE qiling_users SET role_key = :role_key, updated_at = :updated_at WHERE id = :id');
                $updateUser->execute([
                    'id' => (int) ($row['user_id'] ?? 0),
                    'role_key' => $userRoleKey,
                    'updated_at' => $now,
                ]);
            }

            Audit::log((int) $actor['id'], 'staff.update', 'staff', $staffId, 'Update staff', [
                'store_id' => $storeId,
                'status' => $status,
            ]);

            $pdo->commit();
            Response::json(['id' => $staffId, 'updated' => true]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('update staff failed', $e);
        }
    }

    private static function isSupportedRoleKey(string $roleKey): bool
    {
        return in_array($roleKey, ['admin', 'manager', 'consultant', 'therapist', 'reception'], true);
    }

    private static function isPrivilegedRoleKey(string $roleKey): bool
    {
        return in_array($roleKey, ['admin', 'manager'], true);
    }
}
