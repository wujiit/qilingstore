<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\CustomerService;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\PointsService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class PointsController
{
    public static function grades(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);

        $sql = 'SELECT id, store_id, grade_code, grade_name, threshold_points, discount_rate, enabled, created_at, updated_at
                FROM qiling_customer_grades
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND (store_id = 0 OR store_id = :store_id)';
            $params['store_id'] = $scopeStoreId;
        }

        $sql .= ' ORDER BY store_id DESC, threshold_points ASC, id ASC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function upsertGrade(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $gradeName = Request::str($data, 'grade_name');
        if ($gradeName === '') {
            Response::json(['message' => 'grade_name is required'], 422);
            return;
        }

        $thresholdPoints = max(0, Request::int($data, 'threshold_points', 0));
        $discountRate = round((float) ($data['discount_rate'] ?? 100), 2);
        if ($discountRate < 0) {
            $discountRate = 0;
        }
        if ($discountRate > 100) {
            $discountRate = 100;
        }
        $enabled = (int) ($data['enabled'] ?? 1) === 1 ? 1 : 0;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $gradeId = Request::int($data, 'id', 0);
            $now = gmdate('Y-m-d H:i:s');

            if ($gradeId > 0) {
                $exists = $pdo->prepare(
                    'SELECT id, store_id, grade_code
                     FROM qiling_customer_grades
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE'
                );
                $exists->execute(['id' => $gradeId]);
                $grade = $exists->fetch(PDO::FETCH_ASSOC);
                if (!is_array($grade)) {
                    $pdo->rollBack();
                    Response::json(['message' => 'grade not found'], 404);
                    return;
                }

                self::assertGradeStoreAccess($user, (int) ($grade['store_id'] ?? 0));
                $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', (int) ($grade['store_id'] ?? 0)), true);
                self::assertGradeStoreAccess($user, $storeId);

                $gradeCode = strtoupper(Request::str($data, 'grade_code', (string) ($grade['grade_code'] ?? '')));
                if ($gradeCode === '') {
                    $gradeCode = (string) ($grade['grade_code'] ?? '');
                }

                $update = $pdo->prepare(
                    'UPDATE qiling_customer_grades
                     SET store_id = :store_id,
                         grade_code = :grade_code,
                         grade_name = :grade_name,
                         threshold_points = :threshold_points,
                         discount_rate = :discount_rate,
                         enabled = :enabled,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'store_id' => $storeId,
                    'grade_code' => $gradeCode,
                    'grade_name' => $gradeName,
                    'threshold_points' => $thresholdPoints,
                    'discount_rate' => $discountRate,
                    'enabled' => $enabled,
                    'updated_at' => $now,
                    'id' => $gradeId,
                ]);

                Audit::log((int) $user['id'], 'points.grade.update', 'customer_grade', $gradeId, 'Update customer grade', [
                    'store_id' => $storeId,
                    'grade_code' => $gradeCode,
                ]);

                $pdo->commit();
                Response::json([
                    'grade_id' => $gradeId,
                    'store_id' => $storeId,
                    'grade_code' => $gradeCode,
                ]);
                return;
            }

            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0), true);
            self::assertGradeStoreAccess($user, $storeId);
            $gradeCode = strtoupper(Request::str($data, 'grade_code'));
            if ($gradeCode === '') {
                $gradeCode = 'QLGR' . random_int(10000, 99999);
            }

            $insert = $pdo->prepare(
                'INSERT INTO qiling_customer_grades
                 (store_id, grade_code, grade_name, threshold_points, discount_rate, enabled, created_at, updated_at)
                 VALUES
                 (:store_id, :grade_code, :grade_name, :threshold_points, :discount_rate, :enabled, :created_at, :updated_at)'
            );
            $insert->execute([
                'store_id' => $storeId,
                'grade_code' => $gradeCode,
                'grade_name' => $gradeName,
                'threshold_points' => $thresholdPoints,
                'discount_rate' => $discountRate,
                'enabled' => $enabled,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $newId = (int) $pdo->lastInsertId();
            Audit::log((int) $user['id'], 'points.grade.create', 'customer_grade', $newId, 'Create customer grade', [
                'store_id' => $storeId,
                'grade_code' => $gradeCode,
            ]);

            $pdo->commit();
            Response::json([
                'grade_id' => $newId,
                'store_id' => $storeId,
                'grade_code' => $gradeCode,
            ], 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('upsert grade failed', $e);
        }
    }

    public static function account(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $pdo = Database::pdo();
        $customerIdInput = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        $customerMobileInput = isset($_GET['customer_mobile']) && is_string($_GET['customer_mobile']) ? trim($_GET['customer_mobile']) : '';
        if ($customerIdInput <= 0 && $customerMobileInput === '') {
            Response::json(['message' => 'customer_id or customer_mobile is required'], 422);
            return;
        }

        $customer = CustomerService::findByIdOrMobile($pdo, $customerIdInput, $customerMobileInput);
        if (!is_array($customer)) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }

        DataScope::assertStoreAccess($user, (int) ($customer['store_id'] ?? 0));
        $account = PointsService::account($pdo, (int) $customer['id']);

        Response::json([
            'customer' => [
                'id' => (int) ($customer['id'] ?? 0),
                'customer_no' => (string) ($customer['customer_no'] ?? ''),
                'name' => (string) ($customer['name'] ?? ''),
                'mobile' => (string) ($customer['mobile'] ?? ''),
                'store_id' => (int) ($customer['store_id'] ?? 0),
            ],
            'account' => $account,
        ]);
    }

    public static function logs(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $pdo = Database::pdo();

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $changeType = isset($_GET['change_type']) && is_string($_GET['change_type']) ? trim($_GET['change_type']) : '';
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min($limit, 1000));

        $customerId = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        $customerMobile = isset($_GET['customer_mobile']) && is_string($_GET['customer_mobile']) ? trim($_GET['customer_mobile']) : '';
        if ($customerId <= 0 && $customerMobile !== '') {
            $customer = CustomerService::findByIdOrMobile($pdo, 0, $customerMobile);
            if (is_array($customer)) {
                $customerId = (int) ($customer['id'] ?? 0);
            }
        }

        $sql = 'SELECT l.*, c.customer_no, c.name AS customer_name, c.mobile AS customer_mobile, u.username AS operator_username
                FROM qiling_customer_point_logs l
                INNER JOIN qiling_customers c ON c.id = l.customer_id
                LEFT JOIN qiling_users u ON u.id = l.operator_user_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND l.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($customerId > 0) {
            $sql .= ' AND l.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($changeType !== '') {
            $sql .= ' AND l.change_type = :change_type';
            $params['change_type'] = $changeType;
        }

        $sql .= ' ORDER BY l.id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function change(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $deltaPoints = Request::int($data, 'delta_points', 0);
        if ($deltaPoints === 0) {
            Response::json(['message' => 'delta_points cannot be zero'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $customerIdInput = Request::int($data, 'customer_id', 0);
            $customerMobileInput = Request::str($data, 'customer_mobile');
            if ($customerIdInput <= 0 && $customerMobileInput === '') {
                throw new \RuntimeException('customer_id or customer_mobile is required');
            }

            $customer = CustomerService::findByIdOrMobile(
                $pdo,
                $customerIdInput,
                $customerMobileInput,
                true
            );
            if (!is_array($customer)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $customerId = (int) ($customer['id'] ?? 0);
            $customerStoreId = (int) ($customer['store_id'] ?? 0);
            DataScope::assertStoreAccess($user, $customerStoreId);

            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', $customerStoreId));
            if ($customerStoreId > 0 && $storeId !== $customerStoreId) {
                throw new \RuntimeException('customer store mismatch');
            }

            $changeType = Request::str($data, 'change_type', 'manual_adjust');
            if ($changeType === '') {
                $changeType = 'manual_adjust';
            }

            $relatedType = Request::str($data, 'related_type');
            $relatedId = Request::int($data, 'related_id', 0);
            $note = Request::str($data, 'note', '后台手工调整积分');

            $result = PointsService::change(
                $pdo,
                (int) $user['id'],
                $customerId,
                $storeId,
                $deltaPoints,
                $changeType,
                $note,
                $relatedType,
                $relatedId > 0 ? $relatedId : null
            );

            Audit::log((int) $user['id'], 'points.change', 'customer_point', $customerId, 'Manual points change', [
                'delta_points' => $deltaPoints,
                'change_type' => $changeType,
                'store_id' => $storeId,
            ]);

            $pdo->commit();
            Response::json($result);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('change points failed', $e);
        }
    }

    private static function assertGradeStoreAccess(array $user, int $storeId): void
    {
        if ($storeId === 0) {
            DataScope::requireAdmin($user);
            return;
        }

        DataScope::assertStoreAccess($user, $storeId);
    }
}
