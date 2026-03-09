<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class InventoryService
{
    /**
     * @return array<string, mixed>
     */
    public static function consumeMaterialsForOrder(PDO $pdo, int $orderId, int $storeId, int $operatorUserId): array
    {
        if ($orderId <= 0 || $storeId < 0) {
            return [
                'skipped' => 1,
                'movement_count' => 0,
                'total_cost' => 0.0,
                'warnings' => ['invalid order/store payload'],
            ];
        }

        $existingStmt = $pdo->prepare(
            'SELECT id
             FROM qiling_inventory_stock_movements
             WHERE reference_type = :reference_type
               AND reference_id = :reference_id
             LIMIT 1'
        );
        $existingStmt->execute([
            'reference_type' => 'order_paid_consume',
            'reference_id' => $orderId,
        ]);
        if ($existingStmt->fetchColumn()) {
            return [
                'skipped' => 1,
                'movement_count' => 0,
                'total_cost' => 0.0,
                'warnings' => ['order materials already consumed'],
            ];
        }

        $itemStmt = $pdo->prepare(
            'SELECT id, item_ref_id, qty, item_name
             FROM qiling_order_items
             WHERE order_id = :order_id
               AND item_type = :item_type
               AND item_ref_id IS NOT NULL
               AND qty > 0
             ORDER BY id ASC'
        );
        $itemStmt->execute([
            'order_id' => $orderId,
            'item_type' => 'service',
        ]);
        $orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($orderItems) || empty($orderItems)) {
            return [
                'skipped' => 1,
                'movement_count' => 0,
                'total_cost' => 0.0,
                'warnings' => ['no service items matched for material consume'],
            ];
        }

        $totalCost = 0.0;
        $movementCount = 0;
        $warnings = [];

        foreach ($orderItems as $orderItem) {
            $serviceId = (int) ($orderItem['item_ref_id'] ?? 0);
            $itemQty = max(0.0, (float) ($orderItem['qty'] ?? 0));
            if ($serviceId <= 0 || $itemQty <= 0) {
                continue;
            }

            $mappings = self::resolveServiceMaterialMappings($pdo, $serviceId, $storeId);
            if (empty($mappings)) {
                continue;
            }

            foreach ($mappings as $mapping) {
                $baseQty = max(0.0, (float) ($mapping['consume_qty'] ?? 0));
                if ($baseQty <= 0.0) {
                    continue;
                }
                $wastageRate = max(0.0, (float) ($mapping['wastage_rate'] ?? 0));
                $consumeQty = round($itemQty * $baseQty * (1 + ($wastageRate / 100)), 3);
                if ($consumeQty <= 0) {
                    continue;
                }

                try {
                    $movement = self::applyMaterialMovement(
                        $pdo,
                        (int) ($mapping['material_id'] ?? 0),
                        $storeId,
                        -$consumeQty,
                        'consume',
                        0.0,
                        'order_paid_consume',
                        $orderId,
                        $operatorUserId,
                        '订单支付自动扣减耗材',
                    );
                    $movementCount++;
                    $totalCost += max(0.0, -1 * (float) ($movement['total_cost'] ?? 0.0));
                    if (($movement['qty_after'] ?? 0) < 0) {
                        $warnings[] = sprintf(
                            'material #%d stock below zero after consume',
                            (int) ($mapping['material_id'] ?? 0)
                        );
                    }
                } catch (\RuntimeException $e) {
                    $warnings[] = sprintf(
                        'material #%d consume failed: %s',
                        (int) ($mapping['material_id'] ?? 0),
                        $e->getMessage()
                    );
                }
            }
        }

        return [
            'skipped' => 0,
            'movement_count' => $movementCount,
            'total_cost' => round($totalCost, 2),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function applyMaterialMovement(
        PDO $pdo,
        int $materialId,
        int $storeId,
        float $qtyDelta,
        string $movementType,
        float $unitCost,
        string $referenceType,
        ?int $referenceId,
        int $operatorUserId,
        string $note = ''
    ): array {
        if ($materialId <= 0) {
            throw new \RuntimeException('material_id is required');
        }
        if (abs($qtyDelta) < 0.00001) {
            throw new \RuntimeException('qty_delta must not be zero');
        }

        $material = self::lockMaterial($pdo, $materialId);
        if (!is_array($material)) {
            throw new \RuntimeException('material not found');
        }

        $materialStoreId = (int) ($material['store_id'] ?? 0);
        if ($storeId > 0 && $materialStoreId > 0 && $materialStoreId !== $storeId) {
            throw new \RuntimeException('material store mismatch');
        }

        $beforeQty = round((float) ($material['current_stock'] ?? 0), 3);
        $afterQty = round($beforeQty + $qtyDelta, 3);
        $avgCostBefore = round((float) ($material['avg_cost'] ?? 0), 4);
        $avgCostAfter = $avgCostBefore;

        $resolvedUnitCost = $unitCost > 0 ? round($unitCost, 4) : $avgCostBefore;
        if ($qtyDelta > 0 && $resolvedUnitCost > 0) {
            $baseQty = max(0.0, $beforeQty);
            $baseValue = $baseQty * $avgCostBefore;
            $inValue = $qtyDelta * $resolvedUnitCost;
            $denominator = $baseQty + $qtyDelta;
            if ($denominator > 0) {
                $avgCostAfter = round(($baseValue + $inValue) / $denominator, 4);
            }
        }

        $updateStmt = $pdo->prepare(
            'UPDATE qiling_inventory_materials
             SET current_stock = :current_stock,
                 avg_cost = :avg_cost,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $now = gmdate('Y-m-d H:i:s');
        $updateStmt->execute([
            'current_stock' => $afterQty,
            'avg_cost' => $avgCostAfter,
            'updated_at' => $now,
            'id' => $materialId,
        ]);

        $movementStoreId = $storeId > 0 ? $storeId : $materialStoreId;
        $totalCost = round($qtyDelta * $resolvedUnitCost, 2);

        $insertStmt = $pdo->prepare(
            'INSERT INTO qiling_inventory_stock_movements
             (store_id, material_id, movement_type, qty_delta, qty_before, qty_after, unit_cost, total_cost, reference_type, reference_id, note, operator_user_id, created_at)
             VALUES
             (:store_id, :material_id, :movement_type, :qty_delta, :qty_before, :qty_after, :unit_cost, :total_cost, :reference_type, :reference_id, :note, :operator_user_id, :created_at)'
        );
        $insertStmt->execute([
            'store_id' => $movementStoreId,
            'material_id' => $materialId,
            'movement_type' => trim($movementType) !== '' ? trim($movementType) : 'adjust',
            'qty_delta' => round($qtyDelta, 3),
            'qty_before' => $beforeQty,
            'qty_after' => $afterQty,
            'unit_cost' => $resolvedUnitCost,
            'total_cost' => $totalCost,
            'reference_type' => trim($referenceType),
            'reference_id' => ($referenceId !== null && $referenceId > 0) ? $referenceId : null,
            'note' => trim($note),
            'operator_user_id' => max(0, $operatorUserId),
            'created_at' => $now,
        ]);

        return [
            'movement_id' => (int) $pdo->lastInsertId(),
            'material_id' => $materialId,
            'store_id' => $movementStoreId,
            'movement_type' => trim($movementType) !== '' ? trim($movementType) : 'adjust',
            'qty_delta' => round($qtyDelta, 3),
            'qty_before' => $beforeQty,
            'qty_after' => $afterQty,
            'unit_cost' => $resolvedUnitCost,
            'total_cost' => $totalCost,
            'avg_cost_after' => $avgCostAfter,
            'low_stock' => $afterQty <= round((float) ($material['safety_stock'] ?? 0), 3),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockMaterial(PDO $pdo, int $materialId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, store_id, material_name, safety_stock, current_stock, avg_cost, status
             FROM qiling_inventory_materials
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['id' => $materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolveServiceMaterialMappings(PDO $pdo, int $serviceId, int $storeId): array
    {
        $stmt = $pdo->prepare(
            'SELECT sm.id,
                    sm.store_id,
                    sm.service_id,
                    sm.material_id,
                    sm.consume_qty,
                    sm.wastage_rate,
                    m.store_id AS material_store_id,
                    m.status AS material_status
             FROM qiling_inventory_service_materials sm
             INNER JOIN qiling_inventory_materials m ON m.id = sm.material_id
             WHERE sm.service_id = :service_id
               AND sm.enabled = 1
               AND sm.store_id IN (0, :store_id)
             ORDER BY sm.store_id DESC, sm.id DESC'
        );
        $stmt->execute([
            'service_id' => $serviceId,
            'store_id' => $storeId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $picked = [];
        foreach ($rows as $row) {
            $materialId = (int) ($row['material_id'] ?? 0);
            if ($materialId <= 0 || isset($picked[$materialId])) {
                continue;
            }
            $materialStoreId = (int) ($row['material_store_id'] ?? 0);
            $materialStatus = (string) ($row['material_status'] ?? 'inactive');
            if ($materialStatus !== 'active') {
                continue;
            }
            if ($materialStoreId > 0 && $materialStoreId !== $storeId) {
                continue;
            }
            $picked[$materialId] = $row;
        }

        return array_values($picked);
    }
}
