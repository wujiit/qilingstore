<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\CustomerService;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\OpenGiftService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class OpenGiftController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $triggerType = isset($_GET['trigger_type']) && is_string($_GET['trigger_type']) ? trim($_GET['trigger_type']) : '';

        $sql = 'SELECT id, store_id, trigger_type, gift_name, enabled, created_at, updated_at
                FROM qiling_open_gifts
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND (store_id = 0 OR store_id = :store_id)';
            $params['store_id'] = $scopeStoreId;
        }
        if ($triggerType !== '') {
            $sql .= ' AND trigger_type = :trigger_type';
            $params['trigger_type'] = self::normalizeTriggerType($triggerType);
        }
        $sql .= ' ORDER BY store_id DESC, id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $itemsStmt = Database::pdo()->prepare(
                'SELECT id, item_type, points_value, coupon_name, coupon_type, face_value, min_spend, remain_count, expire_days
                 FROM qiling_open_gift_items
                 WHERE open_gift_id = :open_gift_id
                 ORDER BY id ASC'
            );
            $itemsStmt->execute(['open_gift_id' => (int) ($row['id'] ?? 0)]);
            $row['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        Response::json(['data' => $rows]);
    }

    public static function upsert(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $giftName = Request::str($data, 'gift_name');
        if ($giftName === '') {
            Response::json(['message' => 'gift_name is required'], 422);
            return;
        }

        $triggerType = self::normalizeTriggerType(Request::str($data, 'trigger_type', 'onboard'));
        $enabled = (int) ($data['enabled'] ?? 1) === 1 ? 1 : 0;
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $giftId = Request::int($data, 'id', 0);
            $now = gmdate('Y-m-d H:i:s');

            if ($giftId > 0) {
                $exists = $pdo->prepare(
                    'SELECT id, store_id, trigger_type
                     FROM qiling_open_gifts
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE'
                );
                $exists->execute(['id' => $giftId]);
                $gift = $exists->fetch(PDO::FETCH_ASSOC);
                if (!is_array($gift)) {
                    $pdo->rollBack();
                    Response::json(['message' => 'open gift not found'], 404);
                    return;
                }

                DataScope::assertGlobalStoreAdminOnly($user, (int) ($gift['store_id'] ?? 0));
                $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', (int) ($gift['store_id'] ?? 0)), true);
                DataScope::assertGlobalStoreAdminOnly($user, $storeId);

                $update = $pdo->prepare(
                    'UPDATE qiling_open_gifts
                     SET store_id = :store_id,
                         trigger_type = :trigger_type,
                         gift_name = :gift_name,
                         enabled = :enabled,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'store_id' => $storeId,
                    'trigger_type' => $triggerType,
                    'gift_name' => $giftName,
                    'enabled' => $enabled,
                    'updated_at' => $now,
                    'id' => $giftId,
                ]);
            } else {
                $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0), true);
                DataScope::assertGlobalStoreAdminOnly($user, $storeId);

                $insert = $pdo->prepare(
                    'INSERT INTO qiling_open_gifts
                     (store_id, trigger_type, gift_name, enabled, created_at, updated_at)
                     VALUES
                     (:store_id, :trigger_type, :gift_name, :enabled, :created_at, :updated_at)'
                );
                $insert->execute([
                    'store_id' => $storeId,
                    'trigger_type' => $triggerType,
                    'gift_name' => $giftName,
                    'enabled' => $enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $giftId = (int) $pdo->lastInsertId();
            }

            $deleteItems = $pdo->prepare('DELETE FROM qiling_open_gift_items WHERE open_gift_id = :open_gift_id');
            $deleteItems->execute(['open_gift_id' => $giftId]);

            $insertItem = $pdo->prepare(
                'INSERT INTO qiling_open_gift_items
                 (open_gift_id, item_type, points_value, coupon_name, coupon_type, face_value, min_spend, remain_count, expire_days, created_at, updated_at)
                 VALUES
                 (:open_gift_id, :item_type, :points_value, :coupon_name, :coupon_type, :face_value, :min_spend, :remain_count, :expire_days, :created_at, :updated_at)'
            );

            $savedItems = 0;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemType = trim(strtolower((string) ($item['item_type'] ?? 'points')));
                if (!in_array($itemType, ['points', 'coupon'], true)) {
                    continue;
                }

                $pointsValue = 0;
                $couponName = '';
                $couponType = 'cash';
                $faceValue = 0.0;
                $minSpend = 0.0;
                $remainCount = 1;
                $expireDays = max(1, Request::int($item, 'expire_days', 30));

                if ($itemType === 'points') {
                    $pointsValue = Request::int($item, 'points_value', 0);
                    if ($pointsValue <= 0) {
                        continue;
                    }
                } else {
                    $couponName = Request::str($item, 'coupon_name');
                    if ($couponName === '') {
                        continue;
                    }
                    $couponType = Request::str($item, 'coupon_type', 'cash');
                    if (!in_array($couponType, ['cash', 'discount'], true)) {
                        $couponType = 'cash';
                    }
                    $faceValue = round(max(0.0, (float) ($item['face_value'] ?? 0)), 2);
                    $minSpend = round(max(0.0, (float) ($item['min_spend'] ?? 0)), 2);
                    $remainCount = max(1, Request::int($item, 'remain_count', 1));
                }

                $insertItem->execute([
                    'open_gift_id' => $giftId,
                    'item_type' => $itemType,
                    'points_value' => $pointsValue,
                    'coupon_name' => $couponName,
                    'coupon_type' => $couponType,
                    'face_value' => $faceValue,
                    'min_spend' => $minSpend,
                    'remain_count' => $remainCount,
                    'expire_days' => $expireDays,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $savedItems++;
            }

            Audit::log((int) $user['id'], 'open_gift.upsert', 'open_gift', $giftId, 'Upsert open gift rule', [
                'trigger_type' => $triggerType,
                'items' => $savedItems,
                'enabled' => $enabled,
            ]);

            $pdo->commit();
            Response::json([
                'open_gift_id' => $giftId,
                'trigger_type' => $triggerType,
                'saved_items' => $savedItems,
            ], Request::int($data, 'id', 0) > 0 ? 200 : 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('upsert open gift failed', $e);
        }
    }

    public static function trigger(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $triggerType = self::normalizeTriggerType(Request::str($data, 'trigger_type', 'manual'));
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

            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', $customerStoreId), true);
            if ($customerStoreId > 0 && $storeId !== $customerStoreId) {
                throw new \RuntimeException('customer store mismatch');
            }

            $result = OpenGiftService::trigger(
                $pdo,
                $triggerType,
                (int) $user['id'],
                $customerId,
                $storeId,
                Request::str($data, 'reference_type', 'manual'),
                Request::int($data, 'reference_id', 0) > 0 ? Request::int($data, 'reference_id', 0) : null
            );

            Audit::log((int) $user['id'], 'open_gift.trigger', 'customer', $customerId, 'Trigger open gift', [
                'trigger_type' => $triggerType,
                'triggered' => (int) (($result['triggered'] ?? false) ? 1 : 0),
                'skipped' => (string) ($result['skipped'] ?? ''),
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
            Response::serverError('trigger open gift failed', $e);
        }
    }

    private static function normalizeTriggerType(string $triggerType): string
    {
        $triggerType = trim(strtolower($triggerType));
        if (!in_array($triggerType, ['onboard', 'first_paid', 'manual'], true)) {
            return 'manual';
        }

        return $triggerType;
    }
}
