<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\InventoryService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class InventoryController
{
    public static function dashboard(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $requestedStoreId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $storeId = $storeId !== null ? $storeId : 0;
        $pdo = Database::pdo();

        $materials = self::queryMaterials($pdo, $storeId, '', 'active', false, 200);
        $recentMovements = self::queryMovements($pdo, $storeId, 0, '', '', '', '', 120);
        $purchases = self::queryPurchases($pdo, $storeId, '', 80);

        $totalStockValue = 0.0;
        $lowStockCount = 0;
        foreach ($materials as $row) {
            $stockValue = round((float) ($row['stock_value'] ?? 0), 2);
            $totalStockValue += $stockValue;
            if ((int) ($row['is_low_stock'] ?? 0) === 1) {
                $lowStockCount++;
            }
        }

        Response::json([
            'store_id' => $storeId,
            'summary' => [
                'material_count' => count($materials),
                'low_stock_count' => $lowStockCount,
                'total_stock_value' => round($totalStockValue, 2),
                'movement_count' => count($recentMovements),
                'purchase_count' => count($purchases),
            ],
            'materials' => $materials,
            'movements' => $recentMovements,
            'purchases' => $purchases,
        ]);
    }

    public static function materials(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $requestedStoreId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $storeId = $storeId !== null ? $storeId : 0;

        $keyword = isset($_GET['keyword']) && is_string($_GET['keyword']) ? trim($_GET['keyword']) : '';
        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        $lowStockOnly = isset($_GET['low_stock_only']) && ((string) $_GET['low_stock_only'] === '1' || strtolower((string) $_GET['low_stock_only']) === 'true');
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min(1000, $limit));

        $rows = self::queryMaterials(Database::pdo(), $storeId, $keyword, $status, $lowStockOnly, $limit);
        Response::json([
            'store_id' => $storeId,
            'total' => count($rows),
            'data' => $rows,
        ]);
    }

    public static function upsertMaterial(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        $pdo = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');

        $id = Request::int($data, 'id', 0);
        $storeIdInput = Request::int($data, 'store_id', 0);
        $storeId = DataScope::resolveInputStoreId($user, $storeIdInput, true);
        $materialName = Request::str($data, 'material_name');
        $materialCode = Request::str($data, 'material_code');
        $category = Request::str($data, 'category');
        $unit = Request::str($data, 'unit', '个');
        $safetyStock = round(max(0.0, (float) ($data['safety_stock'] ?? 0)), 3);
        $status = Request::str($data, 'status', 'active');
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        $note = Request::str($data, 'note');
        $currentStockInput = round((float) ($data['current_stock'] ?? 0), 3);
        $avgCostInput = round(max(0.0, (float) ($data['avg_cost'] ?? 0)), 4);

        if ($materialName === '') {
            Response::json(['message' => 'material_name is required'], 422);
            return;
        }
        if ($materialCode === '') {
            $materialCode = self::generateMaterialCode();
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $row = self::lockMaterial($pdo, $id);
                if (!is_array($row)) {
                    $pdo->rollBack();
                    Response::json(['message' => 'material not found'], 404);
                    return;
                }

                DataScope::assertGlobalStoreAdminOnly($user, (int) ($row['store_id'] ?? 0));
                $targetStoreId = (int) ($row['store_id'] ?? 0);
                $update = $pdo->prepare(
                    'UPDATE qiling_inventory_materials
                     SET material_code = :material_code,
                         material_name = :material_name,
                         category = :category,
                         unit = :unit,
                         safety_stock = :safety_stock,
                         status = :status,
                         note = :note,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'material_code' => $materialCode,
                    'material_name' => $materialName,
                    'category' => $category,
                    'unit' => $unit === '' ? '个' : $unit,
                    'safety_stock' => $safetyStock,
                    'status' => $status,
                    'note' => $note,
                    'updated_at' => $now,
                    'id' => $id,
                ]);

                $currentStockBefore = round((float) ($row['current_stock'] ?? 0), 3);
                if (abs($currentStockInput - $currentStockBefore) >= 0.001) {
                    InventoryService::applyMaterialMovement(
                        $pdo,
                        $id,
                        $targetStoreId,
                        round($currentStockInput - $currentStockBefore, 3),
                        'adjust',
                        $avgCostInput,
                        'material_edit',
                        $id,
                        (int) ($user['id'] ?? 0),
                        '编辑物料时调整库存',
                    );
                }

                Audit::log((int) ($user['id'] ?? 0), 'inventory.material.update', 'inventory_material', $id, 'Update inventory material', [
                    'material_code' => $materialCode,
                    'store_id' => $targetStoreId,
                ]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO qiling_inventory_materials
                     (store_id, material_code, material_name, category, unit, safety_stock, current_stock, avg_cost, status, note, created_at, updated_at)
                     VALUES
                     (:store_id, :material_code, :material_name, :category, :unit, :safety_stock, :current_stock, :avg_cost, :status, :note, :created_at, :updated_at)'
                );
                $insert->execute([
                    'store_id' => $storeId,
                    'material_code' => $materialCode,
                    'material_name' => $materialName,
                    'category' => $category,
                    'unit' => $unit === '' ? '个' : $unit,
                    'safety_stock' => $safetyStock,
                    'current_stock' => 0,
                    'avg_cost' => $avgCostInput,
                    'status' => $status,
                    'note' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = (int) $pdo->lastInsertId();

                if (abs($currentStockInput) >= 0.001) {
                    InventoryService::applyMaterialMovement(
                        $pdo,
                        $id,
                        $storeId,
                        $currentStockInput,
                        'init',
                        $avgCostInput,
                        'material_init',
                        $id,
                        (int) ($user['id'] ?? 0),
                        '新增物料初始化库存',
                    );
                }

                Audit::log((int) ($user['id'] ?? 0), 'inventory.material.create', 'inventory_material', $id, 'Create inventory material', [
                    'material_code' => $materialCode,
                    'store_id' => $storeId,
                ]);
            }

            $pdo->commit();
            Response::json([
                'id' => $id,
                'store_id' => $storeId,
                'material_code' => $materialCode,
                'material_name' => $materialName,
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
            Response::serverError('upsert inventory material failed', $e);
        }
    }

    public static function serviceMappings(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $requestedStoreId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $storeId = $storeId !== null ? $storeId : 0;
        $serviceId = isset($_GET['service_id']) && is_numeric($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 300;
        $limit = max(1, min(1000, $limit));

        $sql = 'SELECT sm.*,
                       s.service_name,
                       m.material_name,
                       m.unit
                FROM qiling_inventory_service_materials sm
                INNER JOIN qiling_services s ON s.id = sm.service_id
                INNER JOIN qiling_inventory_materials m ON m.id = sm.material_id
                WHERE 1 = 1';
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND sm.store_id IN (0, :store_id)';
            $params['store_id'] = $storeId;
        }
        if ($serviceId > 0) {
            $sql .= ' AND sm.service_id = :service_id';
            $params['service_id'] = $serviceId;
        }
        $sql .= ' ORDER BY sm.service_id ASC, sm.store_id DESC, sm.id DESC LIMIT ' . $limit;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json([
            'store_id' => $storeId,
            'total' => is_array($rows) ? count($rows) : 0,
            'data' => is_array($rows) ? $rows : [],
        ]);
    }

    public static function upsertServiceMapping(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        $pdo = Database::pdo();

        $id = Request::int($data, 'id', 0);
        $storeIdInput = Request::int($data, 'store_id', 0);
        $storeId = DataScope::resolveInputStoreId($user, $storeIdInput, true);
        $serviceId = Request::int($data, 'service_id', 0);
        $materialId = Request::int($data, 'material_id', 0);
        $consumeQty = round(max(0.001, (float) ($data['consume_qty'] ?? 1)), 3);
        $wastageRate = round(max(0.0, (float) ($data['wastage_rate'] ?? 0)), 2);
        $enabled = Request::int($data, 'enabled', 1) === 1 ? 1 : 0;
        if ($serviceId <= 0 || $materialId <= 0) {
            Response::json(['message' => 'service_id and material_id are required'], 422);
            return;
        }

        $pdo->beginTransaction();
        try {
            $serviceStmt = $pdo->prepare('SELECT id, store_id FROM qiling_services WHERE id = :id LIMIT 1');
            $serviceStmt->execute(['id' => $serviceId]);
            $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($service)) {
                throw new \RuntimeException('service not found');
            }
            $serviceStoreId = (int) ($service['store_id'] ?? 0);
            if ($serviceStoreId > 0 && $serviceStoreId !== $storeId) {
                throw new \RuntimeException('service store mismatch');
            }

            $material = self::lockMaterial($pdo, $materialId);
            if (!is_array($material)) {
                throw new \RuntimeException('material not found');
            }
            $materialStoreId = (int) ($material['store_id'] ?? 0);
            if ($materialStoreId > 0 && $materialStoreId !== $storeId) {
                throw new \RuntimeException('material store mismatch');
            }

            $now = gmdate('Y-m-d H:i:s');
            if ($id > 0) {
                $rowStmt = $pdo->prepare('SELECT id, store_id FROM qiling_inventory_service_materials WHERE id = :id LIMIT 1 FOR UPDATE');
                $rowStmt->execute(['id' => $id]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new \RuntimeException('mapping not found');
                }
                DataScope::assertGlobalStoreAdminOnly($user, (int) ($row['store_id'] ?? 0));

                $update = $pdo->prepare(
                    'UPDATE qiling_inventory_service_materials
                     SET service_id = :service_id,
                         material_id = :material_id,
                         consume_qty = :consume_qty,
                         wastage_rate = :wastage_rate,
                         enabled = :enabled,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'service_id' => $serviceId,
                    'material_id' => $materialId,
                    'consume_qty' => $consumeQty,
                    'wastage_rate' => $wastageRate,
                    'enabled' => $enabled,
                    'updated_at' => $now,
                    'id' => $id,
                ]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO qiling_inventory_service_materials
                     (store_id, service_id, material_id, consume_qty, wastage_rate, enabled, created_at, updated_at)
                     VALUES
                     (:store_id, :service_id, :material_id, :consume_qty, :wastage_rate, :enabled, :created_at, :updated_at)
                     ON DUPLICATE KEY UPDATE
                        consume_qty = VALUES(consume_qty),
                        wastage_rate = VALUES(wastage_rate),
                        enabled = VALUES(enabled),
                        updated_at = VALUES(updated_at)'
                );
                $insert->execute([
                    'store_id' => $storeId,
                    'service_id' => $serviceId,
                    'material_id' => $materialId,
                    'consume_qty' => $consumeQty,
                    'wastage_rate' => $wastageRate,
                    'enabled' => $enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($pdo->lastInsertId() > 0) {
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $idStmt = $pdo->prepare(
                        'SELECT id
                         FROM qiling_inventory_service_materials
                         WHERE store_id = :store_id
                           AND service_id = :service_id
                           AND material_id = :material_id
                         LIMIT 1'
                    );
                    $idStmt->execute([
                        'store_id' => $storeId,
                        'service_id' => $serviceId,
                        'material_id' => $materialId,
                    ]);
                    $id = (int) $idStmt->fetchColumn();
                }
            }

            Audit::log((int) ($user['id'] ?? 0), 'inventory.service_mapping.upsert', 'inventory_service_mapping', $id, 'Upsert service material mapping', [
                'store_id' => $storeId,
                'service_id' => $serviceId,
                'material_id' => $materialId,
                'enabled' => $enabled,
            ]);

            $pdo->commit();
            Response::json([
                'id' => $id,
                'store_id' => $storeId,
                'service_id' => $serviceId,
                'material_id' => $materialId,
                'consume_qty' => $consumeQty,
                'wastage_rate' => $wastageRate,
                'enabled' => $enabled,
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
            Response::serverError('upsert inventory service mapping failed', $e);
        }
    }

    public static function purchases(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $requestedStoreId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $storeId = $storeId !== null ? $storeId : 0;
        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min(1000, $limit));

        $rows = self::queryPurchases(Database::pdo(), $storeId, $status, $limit);
        Response::json([
            'store_id' => $storeId,
            'total' => count($rows),
            'data' => $rows,
        ]);
    }

    public static function createPurchase(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        $storeIdInput = Request::int($data, 'store_id', 0);
        $storeId = DataScope::resolveInputStoreId($user, $storeIdInput, true);
        $supplierName = Request::str($data, 'supplier_name');
        $expectedAt = Request::str($data, 'expected_at');
        $note = Request::str($data, 'note');
        $autoReceive = Request::int($data, 'auto_receive', 0) === 1;
        $items = $data['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            Response::json(['message' => 'items are required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $normalizedItems = self::normalizePurchaseItems($pdo, $storeId, $items);
            if (empty($normalizedItems)) {
                throw new \RuntimeException('valid items are required');
            }

            $totalAmount = 0.0;
            foreach ($normalizedItems as $item) {
                $totalAmount += (float) ($item['line_amount'] ?? 0);
            }
            $totalAmount = round($totalAmount, 2);
            $now = gmdate('Y-m-d H:i:s');
            $purchaseNo = self::generatePurchaseNo();

            $insertOrder = $pdo->prepare(
                'INSERT INTO qiling_inventory_purchase_orders
                 (store_id, purchase_no, supplier_name, status, total_amount, expected_at, note, created_by, created_at, updated_at)
                 VALUES
                 (:store_id, :purchase_no, :supplier_name, :status, :total_amount, :expected_at, :note, :created_by, :created_at, :updated_at)'
            );
            $insertOrder->execute([
                'store_id' => $storeId,
                'purchase_no' => $purchaseNo,
                'supplier_name' => $supplierName,
                'status' => 'draft',
                'total_amount' => $totalAmount,
                'expected_at' => $expectedAt !== '' ? $expectedAt : null,
                'note' => $note,
                'created_by' => (int) ($user['id'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $purchaseId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO qiling_inventory_purchase_items
                 (purchase_id, material_id, qty, received_qty, unit_cost, line_amount, created_at, updated_at)
                 VALUES
                 (:purchase_id, :material_id, :qty, :received_qty, :unit_cost, :line_amount, :created_at, :updated_at)'
            );
            foreach ($normalizedItems as $item) {
                $insertItem->execute([
                    'purchase_id' => $purchaseId,
                    'material_id' => (int) $item['material_id'],
                    'qty' => round((float) $item['qty'], 3),
                    'received_qty' => 0,
                    'unit_cost' => round((float) $item['unit_cost'], 4),
                    'line_amount' => round((float) $item['line_amount'], 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $receiveResult = null;
            if ($autoReceive) {
                $receiveResult = self::receivePurchaseInternal(
                    $pdo,
                    $purchaseId,
                    (int) ($user['id'] ?? 0),
                    '采购单创建后自动入库'
                );
            }

            Audit::log((int) ($user['id'] ?? 0), 'inventory.purchase.create', 'inventory_purchase', $purchaseId, 'Create purchase order', [
                'purchase_no' => $purchaseNo,
                'store_id' => $storeId,
                'total_amount' => $totalAmount,
                'auto_receive' => $autoReceive ? 1 : 0,
            ]);

            $pdo->commit();
            Response::json([
                'purchase_id' => $purchaseId,
                'purchase_no' => $purchaseNo,
                'store_id' => $storeId,
                'total_amount' => $totalAmount,
                'status' => $autoReceive ? 'received' : 'draft',
                'receive' => $receiveResult,
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
            Response::serverError('create purchase order failed', $e);
        }
    }

    public static function receivePurchase(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        $purchaseId = Request::int($data, 'purchase_id', 0);
        if ($purchaseId <= 0) {
            Response::json(['message' => 'purchase_id is required'], 422);
            return;
        }
        $note = Request::str($data, 'note');

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $purchaseStmt = $pdo->prepare(
                'SELECT id, store_id
                 FROM qiling_inventory_purchase_orders
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $purchaseStmt->execute(['id' => $purchaseId]);
            $purchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($purchase)) {
                $pdo->rollBack();
                Response::json(['message' => 'purchase order not found'], 404);
                return;
            }
            DataScope::assertGlobalStoreAdminOnly($user, (int) ($purchase['store_id'] ?? 0));

            $result = self::receivePurchaseInternal($pdo, $purchaseId, (int) ($user['id'] ?? 0), $note);

            Audit::log((int) ($user['id'] ?? 0), 'inventory.purchase.receive', 'inventory_purchase', $purchaseId, 'Receive purchase order', [
                'received_items' => (int) ($result['received_items'] ?? 0),
                'received_amount' => (float) ($result['received_amount'] ?? 0),
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
            Response::serverError('receive purchase order failed', $e);
        }
    }

    public static function adjustStock(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $materialId = Request::int($data, 'material_id', 0);
        $storeIdInput = Request::int($data, 'store_id', 0);
        $storeId = DataScope::resolveInputStoreId($user, $storeIdInput, true);
        $qtyDelta = round((float) ($data['qty_delta'] ?? 0), 3);
        $unitCost = round(max(0.0, (float) ($data['unit_cost'] ?? 0)), 4);
        $movementType = Request::str($data, 'movement_type', 'adjust');
        $note = Request::str($data, 'note');
        if ($materialId <= 0) {
            Response::json(['message' => 'material_id is required'], 422);
            return;
        }
        if (abs($qtyDelta) < 0.001) {
            Response::json(['message' => 'qty_delta must not be zero'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $movement = InventoryService::applyMaterialMovement(
                $pdo,
                $materialId,
                $storeId,
                $qtyDelta,
                $movementType,
                $unitCost,
                'manual_adjust',
                $materialId,
                (int) ($user['id'] ?? 0),
                $note
            );

            Audit::log((int) ($user['id'] ?? 0), 'inventory.stock.adjust', 'inventory_stock_movement', (int) ($movement['movement_id'] ?? 0), 'Manual stock adjustment', [
                'material_id' => $materialId,
                'store_id' => $storeId,
                'qty_delta' => $qtyDelta,
                'unit_cost' => $unitCost,
                'movement_type' => $movementType,
            ]);

            $pdo->commit();
            Response::json($movement);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('adjust stock failed', $e);
        }
    }

    public static function stockMovements(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $requestedStoreId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $storeId = $storeId !== null ? $storeId : 0;
        $materialId = isset($_GET['material_id']) && is_numeric($_GET['material_id']) ? (int) $_GET['material_id'] : 0;
        $movementType = isset($_GET['movement_type']) && is_string($_GET['movement_type']) ? trim($_GET['movement_type']) : '';
        $referenceType = isset($_GET['reference_type']) && is_string($_GET['reference_type']) ? trim($_GET['reference_type']) : '';
        $dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 300;
        $limit = max(1, min(1000, $limit));

        $rows = self::queryMovements(Database::pdo(), $storeId, $materialId, $movementType, $referenceType, $dateFrom, $dateTo, $limit);
        Response::json([
            'store_id' => $storeId,
            'total' => count($rows),
            'data' => $rows,
        ]);
    }

    public static function costSummary(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $requestedStoreId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $storeId = $storeId !== null ? $storeId : 0;

        $dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';
        if ($dateFrom === '') {
            $dateFrom = gmdate('Y-m-d', strtotime('-29 days'));
        }
        if ($dateTo === '') {
            $dateTo = gmdate('Y-m-d');
        }
        $fromAt = $dateFrom . ' 00:00:00';
        $toAt = (new \DateTimeImmutable($dateTo . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');

        $pdo = Database::pdo();
        $summarySql = 'SELECT
                         COALESCE(SUM(CASE WHEN movement_type = \'purchase_in\' THEN total_cost ELSE 0 END), 0) AS purchase_cost,
                         COALESCE(SUM(CASE WHEN movement_type = \'consume\' THEN ABS(total_cost) ELSE 0 END), 0) AS consume_cost,
                         COALESCE(SUM(CASE WHEN movement_type = \'adjust\' THEN total_cost ELSE 0 END), 0) AS adjust_cost
                       FROM qiling_inventory_stock_movements
                       WHERE created_at >= :from_at
                         AND created_at < :to_at';
        $summaryParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId > 0) {
            $summarySql .= ' AND store_id = :store_id';
            $summaryParams['store_id'] = $storeId;
        }

        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($summaryParams);
        $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($summaryRow)) {
            $summaryRow = [];
        }

        $detailSql = 'SELECT
                        sm.material_id,
                        m.material_name,
                        m.unit,
                        COALESCE(SUM(ABS(sm.qty_delta)), 0) AS consume_qty,
                        COALESCE(SUM(ABS(sm.total_cost)), 0) AS consume_cost
                      FROM qiling_inventory_stock_movements sm
                      INNER JOIN qiling_inventory_materials m ON m.id = sm.material_id
                      WHERE sm.movement_type = :movement_type
                        AND sm.created_at >= :from_at
                        AND sm.created_at < :to_at';
        $detailParams = [
            'movement_type' => 'consume',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId > 0) {
            $detailSql .= ' AND sm.store_id = :store_id';
            $detailParams['store_id'] = $storeId;
        }
        $detailSql .= ' GROUP BY sm.material_id, m.material_name, m.unit
                        ORDER BY consume_cost DESC
                        LIMIT 100';

        $detailStmt = $pdo->prepare($detailSql);
        $detailStmt->execute($detailParams);
        $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($details)) {
            $details = [];
        }

        $stockValueSql = 'SELECT COALESCE(SUM(current_stock * avg_cost), 0) AS stock_value
                          FROM qiling_inventory_materials
                          WHERE status = :status';
        $stockValueParams = ['status' => 'active'];
        if ($storeId > 0) {
            $stockValueSql .= ' AND store_id IN (0, :store_id)';
            $stockValueParams['store_id'] = $storeId;
        }
        $stockValueStmt = $pdo->prepare($stockValueSql);
        $stockValueStmt->execute($stockValueParams);
        $stockValue = round((float) $stockValueStmt->fetchColumn(), 2);

        Response::json([
            'store_id' => $storeId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'purchase_cost' => round((float) ($summaryRow['purchase_cost'] ?? 0), 2),
                'consume_cost' => round((float) ($summaryRow['consume_cost'] ?? 0), 2),
                'adjust_cost' => round((float) ($summaryRow['adjust_cost'] ?? 0), 2),
                'stock_value' => $stockValue,
            ],
            'consume_top' => $details,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function queryMaterials(PDO $pdo, int $storeId, string $keyword, string $status, bool $lowStockOnly, int $limit): array
    {
        $sql = 'SELECT
                    m.*,
                    ROUND(m.current_stock * m.avg_cost, 2) AS stock_value,
                    CASE WHEN m.current_stock <= m.safety_stock THEN 1 ELSE 0 END AS is_low_stock
                FROM qiling_inventory_materials m
                WHERE 1 = 1';
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND m.store_id IN (0, :store_id)';
            $params['store_id'] = $storeId;
        }
        if ($keyword !== '') {
            $sql .= ' AND (m.material_name LIKE :keyword OR m.material_code LIKE :keyword)';
            $params['keyword'] = '%' . $keyword . '%';
        }
        if ($status !== '') {
            $sql .= ' AND m.status = :status';
            $params['status'] = $status;
        }
        if ($lowStockOnly) {
            $sql .= ' AND m.current_stock <= m.safety_stock';
        }
        $sql .= ' ORDER BY is_low_stock DESC, m.updated_at DESC, m.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function queryMovements(
        PDO $pdo,
        int $storeId,
        int $materialId,
        string $movementType,
        string $referenceType,
        string $dateFrom,
        string $dateTo,
        int $limit
    ): array {
        $sql = 'SELECT
                    sm.*,
                    m.material_code,
                    m.material_name,
                    m.unit
                FROM qiling_inventory_stock_movements sm
                INNER JOIN qiling_inventory_materials m ON m.id = sm.material_id
                WHERE 1 = 1';
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND sm.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        if ($materialId > 0) {
            $sql .= ' AND sm.material_id = :material_id';
            $params['material_id'] = $materialId;
        }
        if ($movementType !== '') {
            $sql .= ' AND sm.movement_type = :movement_type';
            $params['movement_type'] = $movementType;
        }
        if ($referenceType !== '') {
            $sql .= ' AND sm.reference_type = :reference_type';
            $params['reference_type'] = $referenceType;
        }
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
            $sql .= ' AND sm.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
            $sql .= ' AND sm.created_at < :date_to';
            $params['date_to'] = (new \DateTimeImmutable($dateTo . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
        }
        $sql .= ' ORDER BY sm.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function queryPurchases(PDO $pdo, int $storeId, string $status, int $limit): array
    {
        $sql = 'SELECT
                    p.*,
                    u.username AS created_by_username,
                    (SELECT COUNT(1) FROM qiling_inventory_purchase_items i WHERE i.purchase_id = p.id) AS item_count
                FROM qiling_inventory_purchase_orders p
                LEFT JOIN qiling_users u ON u.id = p.created_by
                WHERE 1 = 1';
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND p.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        if ($status !== '') {
            $sql .= ' AND p.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY p.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, float|int>>
     */
    private static function normalizePurchaseItems(PDO $pdo, int $storeId, array $items): array
    {
        $result = [];
        foreach ($items as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $materialId = isset($rawItem['material_id']) && is_numeric($rawItem['material_id']) ? (int) $rawItem['material_id'] : 0;
            if ($materialId <= 0) {
                continue;
            }
            $qty = round(max(0.0, (float) ($rawItem['qty'] ?? 0)), 3);
            if ($qty <= 0) {
                continue;
            }
            $unitCost = round(max(0.0, (float) ($rawItem['unit_cost'] ?? 0)), 4);

            $material = self::lockMaterial($pdo, $materialId);
            if (!is_array($material)) {
                throw new \RuntimeException('material not found');
            }
            $materialStoreId = (int) ($material['store_id'] ?? 0);
            if ($materialStoreId > 0 && $materialStoreId !== $storeId) {
                throw new \RuntimeException('material store mismatch');
            }

            $result[] = [
                'material_id' => $materialId,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'line_amount' => round($qty * $unitCost, 2),
            ];
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function receivePurchaseInternal(PDO $pdo, int $purchaseId, int $operatorUserId, string $note): array
    {
        $orderStmt = $pdo->prepare(
            'SELECT id, store_id, status, purchase_no
             FROM qiling_inventory_purchase_orders
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $orderStmt->execute(['id' => $purchaseId]);
        $purchase = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($purchase)) {
            throw new \RuntimeException('purchase order not found');
        }
        $status = (string) ($purchase['status'] ?? '');
        if ($status === 'received') {
            return [
                'purchase_id' => $purchaseId,
                'purchase_no' => (string) ($purchase['purchase_no'] ?? ''),
                'status' => 'received',
                'received_items' => 0,
                'received_amount' => 0.0,
                'skipped' => 1,
            ];
        }

        $itemStmt = $pdo->prepare(
            'SELECT id, material_id, qty, received_qty, unit_cost
             FROM qiling_inventory_purchase_items
             WHERE purchase_id = :purchase_id
             ORDER BY id ASC
             FOR UPDATE'
        );
        $itemStmt->execute(['purchase_id' => $purchaseId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items) || empty($items)) {
            throw new \RuntimeException('purchase items not found');
        }

        $storeId = (int) ($purchase['store_id'] ?? 0);
        $receivedItems = 0;
        $receivedAmount = 0.0;
        foreach ($items as $item) {
            $qty = round((float) ($item['qty'] ?? 0), 3);
            $receivedQty = round((float) ($item['received_qty'] ?? 0), 3);
            $pendingQty = round(max(0.0, $qty - $receivedQty), 3);
            if ($pendingQty <= 0) {
                continue;
            }
            $unitCost = round(max(0.0, (float) ($item['unit_cost'] ?? 0)), 4);
            InventoryService::applyMaterialMovement(
                $pdo,
                (int) ($item['material_id'] ?? 0),
                $storeId,
                $pendingQty,
                'purchase_in',
                $unitCost,
                'purchase',
                $purchaseId,
                $operatorUserId,
                $note !== '' ? $note : '采购入库'
            );

            $updateItem = $pdo->prepare(
                'UPDATE qiling_inventory_purchase_items
                 SET received_qty = :received_qty,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateItem->execute([
                'received_qty' => round($receivedQty + $pendingQty, 3),
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => (int) ($item['id'] ?? 0),
            ]);

            $receivedItems++;
            $receivedAmount += $pendingQty * $unitCost;
        }

        $now = gmdate('Y-m-d H:i:s');
        $updateOrder = $pdo->prepare(
            'UPDATE qiling_inventory_purchase_orders
             SET status = :status,
                 received_at = :received_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateOrder->execute([
            'status' => 'received',
            'received_at' => $now,
            'updated_at' => $now,
            'id' => $purchaseId,
        ]);

        return [
            'purchase_id' => $purchaseId,
            'purchase_no' => (string) ($purchase['purchase_no'] ?? ''),
            'status' => 'received',
            'received_items' => $receivedItems,
            'received_amount' => round($receivedAmount, 2),
            'skipped' => 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockMaterial(PDO $pdo, int $materialId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, store_id, current_stock, avg_cost
             FROM qiling_inventory_materials
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['id' => $materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function generateMaterialCode(): string
    {
        return 'MAT' . gmdate('ymd') . random_int(10000, 99999);
    }

    private static function generatePurchaseNo(): string
    {
        return 'QLPO' . gmdate('ymd') . random_int(100000, 999999);
    }
}
