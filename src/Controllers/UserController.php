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

final class UserController
{
    public static function index(): void
    {
        $actor = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($actor, $storeId);

        $sql = 'SELECT u.id, u.username, u.email, u.role_key, u.status, u.wp_user_id, u.created_at, u.updated_at,
                       s.id AS staff_id, s.store_id, s.role_key AS staff_role_key, s.staff_no, s.phone, s.title, s.status AS staff_status,
                       st.store_name
                FROM qiling_users u
                LEFT JOIN qiling_staff s ON s.user_id = u.id
                LEFT JOIN qiling_stores st ON st.id = s.store_id';
        $params = [];
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE s.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY u.id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json(['data' => $rows]);
    }

    public static function update(): void
    {
        $actor = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($actor);
        $data = Request::jsonBody();

        $userId = Request::int($data, 'user_id', 0);
        if ($userId <= 0) {
            Response::json(['message' => 'user_id is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare('SELECT * FROM qiling_users WHERE id = :id LIMIT 1 FOR UPDATE');
            $userStmt->execute(['id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) {
                $pdo->rollBack();
                Response::json(['message' => 'user not found'], 404);
                return;
            }

            $username = Request::str($data, 'username', (string) ($user['username'] ?? ''));
            $email = Request::str($data, 'email', (string) ($user['email'] ?? ''));
            $roleKeyInput = Request::str($data, 'role_key');
            $roleKey = $roleKeyInput !== '' ? $roleKeyInput : (string) ($user['role_key'] ?? 'consultant');

            if ($username === '' || $email === '') {
                $pdo->rollBack();
                Response::json(['message' => 'username and email are required'], 422);
                return;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $pdo->rollBack();
                Response::json(['message' => 'email format is invalid'], 422);
                return;
            }
            if (!self::roleExists($pdo, $roleKey)) {
                $pdo->rollBack();
                Response::json(['message' => 'role_key is invalid'], 422);
                return;
            }

            $dupStmt = $pdo->prepare(
                'SELECT id
                 FROM qiling_users
                 WHERE id <> :id AND (username = :username OR email = :email)
                 LIMIT 1'
            );
            $dupStmt->execute([
                'id' => $userId,
                'username' => $username,
                'email' => $email,
            ]);
            if ($dupStmt->fetchColumn()) {
                $pdo->rollBack();
                Response::json(['message' => 'username or email already exists'], 409);
                return;
            }

            $statusInput = Request::str($data, 'status');
            $status = $statusInput !== '' ? $statusInput : (string) ($user['status'] ?? 'active');
            if (!in_array($status, ['active', 'inactive'], true)) {
                $pdo->rollBack();
                Response::json(['message' => 'status must be active or inactive'], 422);
                return;
            }

            $updateUser = $pdo->prepare(
                'UPDATE qiling_users
                 SET username = :username,
                     email = :email,
                     role_key = :role_key,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateUser->execute([
                'id' => $userId,
                'username' => $username,
                'email' => $email,
                'role_key' => $roleKey,
                'status' => $status,
                'updated_at' => $now,
            ]);

            $staffStmt = $pdo->prepare('SELECT * FROM qiling_staff WHERE user_id = :user_id LIMIT 1 FOR UPDATE');
            $staffStmt->execute(['user_id' => $userId]);
            $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

            $targetStoreId = Request::int($data, 'store_id', is_array($staff) ? (int) ($staff['store_id'] ?? 0) : 0);
            $targetStoreId = DataScope::resolveInputStoreId($actor, $targetStoreId, true);

            $staffRoleKeyInput = Request::str($data, 'staff_role_key');
            $staffRoleKey = $staffRoleKeyInput !== '' ? $staffRoleKeyInput : (is_array($staff) ? (string) ($staff['role_key'] ?? 'consultant') : $roleKey);
            if (!self::roleExists($pdo, $staffRoleKey)) {
                $pdo->rollBack();
                Response::json(['message' => 'staff_role_key is invalid'], 422);
                return;
            }
            $staffNo = Request::str($data, 'staff_no', is_array($staff) ? (string) ($staff['staff_no'] ?? '') : '');
            $phone = Request::str($data, 'phone', is_array($staff) ? (string) ($staff['phone'] ?? '') : '');
            $title = Request::str($data, 'title', is_array($staff) ? (string) ($staff['title'] ?? '') : '');
            $staffStatusInput = Request::str($data, 'staff_status');
            $staffStatus = $staffStatusInput !== '' ? $staffStatusInput : (is_array($staff) ? (string) ($staff['status'] ?? 'active') : 'active');
            if (!in_array($staffStatus, ['active', 'inactive'], true)) {
                $staffStatus = 'active';
            }

            if (is_array($staff)) {
                $updateStaff = $pdo->prepare(
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
                $updateStaff->execute([
                    'id' => (int) ($staff['id'] ?? 0),
                    'store_id' => $targetStoreId,
                    'role_key' => $staffRoleKey,
                    'staff_no' => $staffNo,
                    'phone' => $phone,
                    'title' => $title,
                    'status' => $staffStatus,
                    'updated_at' => $now,
                ]);
            } elseif ($targetStoreId > 0) {
                $insertStaff = $pdo->prepare(
                    'INSERT INTO qiling_staff (user_id, store_id, role_key, staff_no, phone, title, status, created_at, updated_at)
                     VALUES (:user_id, :store_id, :role_key, :staff_no, :phone, :title, :status, :created_at, :updated_at)'
                );
                $insertStaff->execute([
                    'user_id' => $userId,
                    'store_id' => $targetStoreId,
                    'role_key' => $staffRoleKey,
                    'staff_no' => $staffNo,
                    'phone' => $phone,
                    'title' => $title,
                    'status' => $staffStatus,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Audit::log((int) $actor['id'], 'user.update', 'user', $userId, 'Update user', [
                'role_key' => $roleKey,
                'status' => $status,
            ]);

            $pdo->commit();
            Response::json(['user_id' => $userId, 'updated' => true]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('update user failed', $e);
        }
    }

    public static function setStatus(): void
    {
        $actor = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($actor);
        $data = Request::jsonBody();

        $userId = Request::int($data, 'user_id', 0);
        $status = Request::str($data, 'status');
        if ($userId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            Response::json(['message' => 'user_id and status(active|inactive) are required'], 422);
            return;
        }

        if ($userId === (int) ($actor['id'] ?? 0) && $status !== 'active') {
            Response::json(['message' => 'cannot disable current login account'], 422);
            return;
        }

        $pdo = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare('UPDATE qiling_users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'id' => $userId,
            'status' => $status,
            'updated_at' => $now,
        ]);

        $staffStmt = $pdo->prepare('UPDATE qiling_staff SET status = :status, updated_at = :updated_at WHERE user_id = :user_id');
        $staffStmt->execute([
            'user_id' => $userId,
            'status' => $status,
            'updated_at' => $now,
        ]);

        Audit::log((int) $actor['id'], 'user.status', 'user', $userId, 'Update user status', [
            'status' => $status,
        ]);

        Response::json(['user_id' => $userId, 'status' => $status]);
    }

    public static function resetPassword(): void
    {
        $actor = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($actor);
        $data = Request::jsonBody();

        $userId = Request::int($data, 'user_id', 0);
        $newPassword = Request::str($data, 'new_password');
        if ($userId <= 0 || $newPassword === '') {
            Response::json(['message' => 'user_id and new_password are required'], 422);
            return;
        }
        if (strlen($newPassword) < 8) {
            Response::json(['message' => 'new_password must be at least 8 chars'], 422);
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.role_key, COALESCE(s.store_id, 0) AS store_id
             FROM qiling_users u
             LEFT JOIN qiling_staff s ON s.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($target)) {
            Response::json(['message' => 'user not found'], 404);
            return;
        }

        DataScope::assertStoreAccess($actor, (int) ($target['store_id'] ?? 0));

        $now = gmdate('Y-m-d H:i:s');
        $update = $pdo->prepare(
            'UPDATE qiling_users
             SET password_hash = :password_hash,
                 login_failed_attempts = 0,
                 login_lock_until = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'id' => $userId,
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'updated_at' => $now,
        ]);

        Audit::log((int) $actor['id'], 'user.reset_password', 'user', $userId, 'Reset user password', []);

        Response::json(['user_id' => $userId, 'password_reset' => true]);
    }

    private static function roleExists(PDO $pdo, string $roleKey): bool
    {
        $roleKey = trim($roleKey);
        if ($roleKey === '') {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT id
             FROM qiling_roles
             WHERE role_key = :role_key
               AND status = :status
             LIMIT 1'
        );
        $stmt->execute([
            'role_key' => $roleKey,
            'status' => 'active',
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
