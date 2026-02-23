<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class OpenGiftService
{
    /**
     * @return array<string, mixed>
     */
    public static function trigger(
        PDO $pdo,
        string $triggerType,
        int $operatorUserId,
        int $customerId,
        int $storeId,
        string $referenceType = '',
        ?int $referenceId = null
    ): array {
        $triggerType = self::normalizeTriggerType($triggerType);
        if ($customerId <= 0) {
            throw new \RuntimeException('customer_id is required');
        }

        $customer = self::lockCustomer($pdo, $customerId);
        if (!is_array($customer)) {
            throw new \RuntimeException('customer not found');
        }

        $storeId = $storeId > 0 ? $storeId : (int) ($customer['store_id'] ?? 0);
        $now = gmdate('Y-m-d H:i:s');

        $existing = $pdo->prepare(
            'SELECT id, open_gift_id
             FROM qiling_open_gift_records
             WHERE trigger_type = :trigger_type
               AND customer_id = :customer_id
             LIMIT 1
             FOR UPDATE'
        );
        $existing->execute([
            'trigger_type' => $triggerType,
            'customer_id' => $customerId,
        ]);
        $record = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($record)) {
            return [
                'triggered' => false,
                'trigger_type' => $triggerType,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'skipped' => 'already_triggered',
                'gift_record_id' => (int) ($record['id'] ?? 0),
            ];
        }

        $rule = self::resolveRule($pdo, $storeId, $triggerType);
        if (!is_array($rule)) {
            return [
                'triggered' => false,
                'trigger_type' => $triggerType,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'skipped' => 'rule_not_found',
            ];
        }

        $itemsStmt = $pdo->prepare(
            'SELECT id, item_type, points_value, coupon_name, coupon_type, face_value, min_spend, remain_count, expire_days
             FROM qiling_open_gift_items
             WHERE open_gift_id = :open_gift_id
             ORDER BY id ASC'
        );
        $itemsStmt->execute(['open_gift_id' => (int) $rule['id']]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items) || empty($items)) {
            return [
                'triggered' => false,
                'trigger_type' => $triggerType,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'open_gift_id' => (int) $rule['id'],
                'skipped' => 'empty_items',
            ];
        }

        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemType = trim(strtolower((string) ($item['item_type'] ?? '')));
            if ($itemType === 'points') {
                $points = max(0, (int) ($item['points_value'] ?? 0));
                if ($points <= 0) {
                    continue;
                }
                $pointResult = PointsService::change(
                    $pdo,
                    $operatorUserId,
                    $customerId,
                    $storeId,
                    $points,
                    'open_gift',
                    '开门礼赠送积分',
                    $referenceType,
                    $referenceId
                );
                $results[] = [
                    'item_id' => (int) ($item['id'] ?? 0),
                    'item_type' => 'points',
                    'points' => $points,
                    'result' => $pointResult,
                ];
                continue;
            }

            if ($itemType === 'coupon') {
                $coupon = AssetService::issueCoupon(
                    $pdo,
                    $customerId,
                    $storeId,
                    [
                        'coupon_name' => (string) ($item['coupon_name'] ?? ''),
                        'coupon_type' => (string) ($item['coupon_type'] ?? 'cash'),
                        'face_value' => (float) ($item['face_value'] ?? 0),
                        'min_spend' => (float) ($item['min_spend'] ?? 0),
                        'remain_count' => max(1, (int) ($item['remain_count'] ?? 1)),
                        'expire_days' => max(1, (int) ($item['expire_days'] ?? 30)),
                    ],
                    $operatorUserId,
                    'open_gift',
                    'open_gift',
                    '开门礼赠送优惠券',
                    $now
                );
                $results[] = [
                    'item_id' => (int) ($item['id'] ?? 0),
                    'item_type' => 'coupon',
                    'result' => $coupon,
                ];
            }
        }

        $insertRecord = $pdo->prepare(
            'INSERT INTO qiling_open_gift_records
             (open_gift_id, trigger_type, customer_id, store_id, reference_type, reference_id, operator_user_id, result_json, created_at)
             VALUES
             (:open_gift_id, :trigger_type, :customer_id, :store_id, :reference_type, :reference_id, :operator_user_id, :result_json, :created_at)'
        );
        $insertRecord->execute([
            'open_gift_id' => (int) $rule['id'],
            'trigger_type' => $triggerType,
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'operator_user_id' => $operatorUserId,
            'result_json' => json_encode($results, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ]);
        $giftRecordId = (int) $pdo->lastInsertId();

        return [
            'triggered' => true,
            'trigger_type' => $triggerType,
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'open_gift_id' => (int) $rule['id'],
            'gift_record_id' => $giftRecordId,
            'gift_name' => (string) ($rule['gift_name'] ?? ''),
            'items_count' => count($results),
            'items' => $results,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockCustomer(PDO $pdo, int $customerId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, store_id
             FROM qiling_customers
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveRule(PDO $pdo, int $storeId, string $triggerType): ?array
    {
        if ($storeId > 0) {
            $storeStmt = $pdo->prepare(
                'SELECT id, store_id, trigger_type, gift_name
                 FROM qiling_open_gifts
                 WHERE store_id = :store_id
                   AND trigger_type = :trigger_type
                   AND enabled = 1
                 LIMIT 1'
            );
            $storeStmt->execute([
                'store_id' => $storeId,
                'trigger_type' => $triggerType,
            ]);
            $storeRule = $storeStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($storeRule)) {
                return $storeRule;
            }
        }

        $globalStmt = $pdo->prepare(
            'SELECT id, store_id, trigger_type, gift_name
             FROM qiling_open_gifts
             WHERE store_id = 0
               AND trigger_type = :trigger_type
               AND enabled = 1
             LIMIT 1'
        );
        $globalStmt->execute(['trigger_type' => $triggerType]);
        $globalRule = $globalStmt->fetch(PDO::FETCH_ASSOC);

        return is_array($globalRule) ? $globalRule : null;
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
