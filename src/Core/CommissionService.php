<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class CommissionService
{
    public static function resolveRate(
        PDO $pdo,
        int $storeId,
        string $itemType,
        ?int $itemRefId,
        string $staffRoleKey
    ): float {
        $itemType = trim(strtolower($itemType));
        if (!in_array($itemType, ['all', 'service', 'package', 'custom'], true)) {
            $itemType = 'custom';
        }

        $staffRoleKey = trim($staffRoleKey);
        $itemRefId = $itemRefId !== null && $itemRefId > 0 ? $itemRefId : null;

        $stmt = $pdo->prepare(
            'SELECT id, store_id, target_type, target_ref_id, staff_role_key, rate_percent
             FROM qiling_commission_rules
             WHERE enabled = 1
               AND store_id IN (0, :store_id)
               AND (target_type = :item_type OR target_type = :target_all)
               AND (target_ref_id IS NULL OR target_ref_id = 0 OR target_ref_id = :item_ref_id)
               AND (staff_role_key = :staff_role_key OR staff_role_key = :staff_role_empty)
             ORDER BY id ASC'
        );
        $stmt->execute([
            'store_id' => $storeId,
            'item_type' => $itemType,
            'target_all' => 'all',
            'item_ref_id' => $itemRefId ?? 0,
            'staff_role_key' => $staffRoleKey,
            'staff_role_empty' => '',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || empty($rows)) {
            return 0.0;
        }

        $bestScore = -1;
        $bestRate = 0.0;

        foreach ($rows as $row) {
            $score = 0;
            if ((int) ($row['store_id'] ?? 0) === $storeId) {
                $score += 8;
            }
            if ((string) ($row['target_type'] ?? '') === $itemType) {
                $score += 4;
            }

            $rowRefId = isset($row['target_ref_id']) ? (int) $row['target_ref_id'] : 0;
            if ($itemRefId !== null && $rowRefId === $itemRefId) {
                $score += 2;
            }

            $rowRole = (string) ($row['staff_role_key'] ?? '');
            if ($staffRoleKey !== '' && $rowRole === $staffRoleKey) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRate = (float) ($row['rate_percent'] ?? 0);
            }
        }

        if ($bestRate < 0) {
            return 0.0;
        }

        if ($bestRate > 100) {
            return 100.0;
        }

        return round($bestRate, 2);
    }
}
