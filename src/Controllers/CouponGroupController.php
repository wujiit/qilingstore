<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\AssetService;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CouponGroupController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $enabled = isset($_GET['enabled']) && is_numeric($_GET['enabled']) ? (int) $_GET['enabled'] : null;

        $sql = 'SELECT *
                FROM qiling_coupon_groups
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND (store_id = 0 OR store_id = :store_id)';
            $params['store_id'] = $scopeStoreId;
        }
        if ($enabled !== null) {
            $sql .= ' AND enabled = :enabled';
            $params['enabled'] = $enabled === 1 ? 1 : 0;
        }

        $sql .= ' ORDER BY store_id DESC, id DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function upsert(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $groupName = Request::str($data, 'group_name');
        $couponName = Request::str($data, 'coupon_name');
        if ($groupName === '' || $couponName === '') {
            Response::json(['message' => 'group_name and coupon_name are required'], 422);
            return;
        }

        $couponType = self::normalizeCouponType(Request::str($data, 'coupon_type', 'cash'));
        $faceValue = round(max(0.0, (float) ($data['face_value'] ?? 0)), 2);
        $minSpend = round(max(0.0, (float) ($data['min_spend'] ?? 0)), 2);
        $perUserLimit = max(1, Request::int($data, 'per_user_limit', 1));
        $totalLimit = max(0, Request::int($data, 'total_limit', 0));
        $expireDays = max(1, Request::int($data, 'expire_days', 30));
        $enabled = (int) ($data['enabled'] ?? 1) === 1 ? 1 : 0;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $groupId = Request::int($data, 'id', 0);
            $now = gmdate('Y-m-d H:i:s');

            if ($groupId > 0) {
                $exists = $pdo->prepare(
                    'SELECT id, store_id, group_code, sent_total
                     FROM qiling_coupon_groups
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE'
                );
                $exists->execute(['id' => $groupId]);
                $group = $exists->fetch(PDO::FETCH_ASSOC);
                if (!is_array($group)) {
                    $pdo->rollBack();
                    Response::json(['message' => 'coupon group not found'], 404);
                    return;
                }

                DataScope::assertGlobalStoreAdminOnly($user, (int) ($group['store_id'] ?? 0));
                $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', (int) ($group['store_id'] ?? 0)), true);
                DataScope::assertGlobalStoreAdminOnly($user, $storeId);

                $groupCode = strtoupper(Request::str($data, 'group_code', (string) ($group['group_code'] ?? '')));
                if ($groupCode === '') {
                    $groupCode = (string) ($group['group_code'] ?? '');
                }

                $update = $pdo->prepare(
                    'UPDATE qiling_coupon_groups
                     SET store_id = :store_id,
                         group_code = :group_code,
                         group_name = :group_name,
                         coupon_name = :coupon_name,
                         coupon_type = :coupon_type,
                         face_value = :face_value,
                         min_spend = :min_spend,
                         per_user_limit = :per_user_limit,
                         total_limit = :total_limit,
                         expire_days = :expire_days,
                         enabled = :enabled,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'store_id' => $storeId,
                    'group_code' => $groupCode,
                    'group_name' => $groupName,
                    'coupon_name' => $couponName,
                    'coupon_type' => $couponType,
                    'face_value' => $faceValue,
                    'min_spend' => $minSpend,
                    'per_user_limit' => $perUserLimit,
                    'total_limit' => $totalLimit,
                    'expire_days' => $expireDays,
                    'enabled' => $enabled,
                    'updated_at' => $now,
                    'id' => $groupId,
                ]);

                Audit::log((int) $user['id'], 'coupon_group.update', 'coupon_group', $groupId, 'Update coupon group', [
                    'group_code' => $groupCode,
                    'store_id' => $storeId,
                ]);

                $pdo->commit();
                Response::json([
                    'group_id' => $groupId,
                    'group_code' => $groupCode,
                    'store_id' => $storeId,
                ]);
                return;
            }

            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0), true);
            DataScope::assertGlobalStoreAdminOnly($user, $storeId);
            $groupCode = strtoupper(Request::str($data, 'group_code'));
            if ($groupCode === '') {
                $groupCode = 'QLCG' . random_int(10000, 99999);
            }

            $insert = $pdo->prepare(
                'INSERT INTO qiling_coupon_groups
                 (store_id, group_code, group_name, coupon_name, coupon_type, face_value, min_spend, per_user_limit, total_limit, sent_total, expire_days, enabled, created_at, updated_at)
                 VALUES
                 (:store_id, :group_code, :group_name, :coupon_name, :coupon_type, :face_value, :min_spend, :per_user_limit, :total_limit, 0, :expire_days, :enabled, :created_at, :updated_at)'
            );
            $insert->execute([
                'store_id' => $storeId,
                'group_code' => $groupCode,
                'group_name' => $groupName,
                'coupon_name' => $couponName,
                'coupon_type' => $couponType,
                'face_value' => $faceValue,
                'min_spend' => $minSpend,
                'per_user_limit' => $perUserLimit,
                'total_limit' => $totalLimit,
                'expire_days' => $expireDays,
                'enabled' => $enabled,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $newId = (int) $pdo->lastInsertId();

            Audit::log((int) $user['id'], 'coupon_group.create', 'coupon_group', $newId, 'Create coupon group', [
                'group_code' => $groupCode,
                'store_id' => $storeId,
            ]);

            $pdo->commit();
            Response::json([
                'group_id' => $newId,
                'group_code' => $groupCode,
                'store_id' => $storeId,
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
            Response::serverError('upsert coupon group failed', $e);
        }
    }

    public static function sends(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $groupId = isset($_GET['group_id']) && is_numeric($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT s.*, g.group_code, g.group_name, cp.coupon_code, cp.coupon_name, c.customer_no, c.name AS customer_name, c.mobile AS customer_mobile
                FROM qiling_coupon_group_sends s
                INNER JOIN qiling_coupon_groups g ON g.id = s.group_id
                INNER JOIN qiling_coupons cp ON cp.id = s.coupon_id
                INNER JOIN qiling_customers c ON c.id = s.customer_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND cp.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($groupId > 0) {
            $sql .= ' AND s.group_id = :group_id';
            $params['group_id'] = $groupId;
        }
        $sql .= ' ORDER BY s.id DESC LIMIT ' . $limit;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function send(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $groupId = Request::int($data, 'group_id', 0);
        if ($groupId <= 0) {
            Response::json(['message' => 'group_id is required'], 422);
            return;
        }

        $customerIds = [];
        $customerIdPayload = $data['customer_ids'] ?? [];
        if (is_array($customerIdPayload)) {
            foreach ($customerIdPayload as $item) {
                if (!is_numeric($item)) {
                    continue;
                }
                $id = (int) $item;
                if ($id > 0) {
                    $customerIds[] = $id;
                }
            }
        }

        $customerMobiles = [];
        $mobilePayload = $data['customer_mobiles'] ?? [];
        if (is_array($mobilePayload)) {
            foreach ($mobilePayload as $mobile) {
                if (!is_string($mobile)) {
                    continue;
                }
                $mobile = trim($mobile);
                if ($mobile !== '') {
                    $customerMobiles[] = $mobile;
                }
            }
        }

        if (empty($customerIds) && empty($customerMobiles)) {
            Response::json(['message' => 'customer_ids or customer_mobiles is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            if (!empty($customerMobiles)) {
                $mobileIds = self::resolveCustomerIdsByMobiles($pdo, $customerMobiles);
                $customerIds = array_merge($customerIds, $mobileIds);
            }
            $customerIds = array_values(array_unique(array_filter($customerIds, static fn (int $id): bool => $id > 0)));

            if (empty($customerIds)) {
                throw new \RuntimeException('no valid customers found');
            }

            $groupStmt = $pdo->prepare(
                'SELECT *
                 FROM qiling_coupon_groups
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $groupStmt->execute(['id' => $groupId]);
            $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($group)) {
                $pdo->rollBack();
                Response::json(['message' => 'coupon group not found'], 404);
                return;
            }

            $groupStoreId = (int) ($group['store_id'] ?? 0);
            DataScope::assertGlobalStoreAdminOnly($user, $groupStoreId);
            if ((int) ($group['enabled'] ?? 0) !== 1) {
                throw new \RuntimeException('coupon group is disabled');
            }

            $totalLimit = max(0, (int) ($group['total_limit'] ?? 0));
            $sentTotal = max(0, (int) ($group['sent_total'] ?? 0));
            $perUserLimit = max(1, (int) ($group['per_user_limit'] ?? 1));
            $batchNo = Request::str($data, 'batch_no');
            if ($batchNo === '') {
                $batchNo = 'QLGB' . gmdate('ymd') . random_int(100000, 999999);
            }
            $now = gmdate('Y-m-d H:i:s');

            $ok = [];
            $skipped = [];

            foreach ($customerIds as $customerId) {
                if ($totalLimit > 0 && $sentTotal >= $totalLimit) {
                    $skipped[] = [
                        'customer_id' => $customerId,
                        'reason' => 'total_limit_reached',
                    ];
                    continue;
                }

                $customerStmt = $pdo->prepare(
                    'SELECT id, customer_no, name, mobile, store_id
                     FROM qiling_customers
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE'
                );
                $customerStmt->execute(['id' => $customerId]);
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($customer)) {
                    $skipped[] = [
                        'customer_id' => $customerId,
                        'reason' => 'customer_not_found',
                    ];
                    continue;
                }

                $customerStoreId = (int) ($customer['store_id'] ?? 0);
                DataScope::assertStoreAccess($user, $customerStoreId);

                if ($groupStoreId > 0 && $customerStoreId !== $groupStoreId) {
                    $skipped[] = [
                        'customer_id' => $customerId,
                        'reason' => 'store_mismatch',
                    ];
                    continue;
                }

                $countStmt = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM qiling_coupon_group_sends
                     WHERE group_id = :group_id
                       AND customer_id = :customer_id'
                );
                $countStmt->execute([
                    'group_id' => $groupId,
                    'customer_id' => $customerId,
                ]);
                $alreadySent = (int) $countStmt->fetchColumn();
                if ($alreadySent >= $perUserLimit) {
                    $skipped[] = [
                        'customer_id' => $customerId,
                        'reason' => 'per_user_limit_reached',
                    ];
                    continue;
                }

                $coupon = AssetService::issueCoupon(
                    $pdo,
                    $customerId,
                    $customerStoreId,
                    [
                        'coupon_name' => (string) ($group['coupon_name'] ?? ''),
                        'coupon_type' => (string) ($group['coupon_type'] ?? 'cash'),
                        'face_value' => (float) ($group['face_value'] ?? 0),
                        'min_spend' => (float) ($group['min_spend'] ?? 0),
                        'remain_count' => 1,
                        'expire_days' => max(1, (int) ($group['expire_days'] ?? 30)),
                    ],
                    (int) $user['id'],
                    'coupon_group',
                    'coupon_group_send',
                    '券包发放',
                    $now
                );

                $sendInsert = $pdo->prepare(
                    'INSERT INTO qiling_coupon_group_sends
                     (group_id, customer_id, coupon_id, batch_no, operator_user_id, status, created_at)
                     VALUES
                     (:group_id, :customer_id, :coupon_id, :batch_no, :operator_user_id, :status, :created_at)'
                );
                $sendInsert->execute([
                    'group_id' => $groupId,
                    'customer_id' => $customerId,
                    'coupon_id' => (int) ($coupon['coupon_id'] ?? 0),
                    'batch_no' => $batchNo,
                    'operator_user_id' => (int) $user['id'],
                    'status' => 'success',
                    'created_at' => $now,
                ]);

                $sentTotal++;
                $ok[] = [
                    'customer_id' => $customerId,
                    'customer_name' => (string) ($customer['name'] ?? ''),
                    'coupon_id' => (int) ($coupon['coupon_id'] ?? 0),
                    'coupon_code' => (string) ($coupon['coupon_code'] ?? ''),
                ];
            }

            $updateGroup = $pdo->prepare(
                'UPDATE qiling_coupon_groups
                 SET sent_total = :sent_total,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateGroup->execute([
                'sent_total' => $sentTotal,
                'updated_at' => $now,
                'id' => $groupId,
            ]);

            Audit::log((int) $user['id'], 'coupon_group.send', 'coupon_group', $groupId, 'Send coupon group', [
                'batch_no' => $batchNo,
                'success' => count($ok),
                'skipped' => count($skipped),
            ]);

            $pdo->commit();
            Response::json([
                'group_id' => $groupId,
                'batch_no' => $batchNo,
                'requested' => count($customerIds),
                'success' => count($ok),
                'skipped' => count($skipped),
                'results' => $ok,
                'skip_details' => $skipped,
                'sent_total' => $sentTotal,
            ]);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('send coupon group failed', $e);
        }
    }

    /**
     * @param array<int, string> $mobiles
     * @return array<int, int>
     */
    private static function resolveCustomerIdsByMobiles(PDO $pdo, array $mobiles): array
    {
        $mobiles = array_values(array_unique(array_filter($mobiles, static fn (string $v): bool => $v !== '')));
        if (empty($mobiles)) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($mobiles as $idx => $mobile) {
            $key = 'm' . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $mobile;
        }

        $sql = 'SELECT id
                FROM qiling_customers
                WHERE mobile IN (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map('intval', $rows));
    }

    private static function normalizeCouponType(string $couponType): string
    {
        $couponType = trim(strtolower($couponType));
        if (!in_array($couponType, ['cash', 'discount'], true)) {
            return 'cash';
        }
        return $couponType;
    }
}
