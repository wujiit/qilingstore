<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\PrintService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class PrintController
{
    public static function printers(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $enabled = isset($_GET['enabled']) && is_numeric($_GET['enabled']) ? (int) $_GET['enabled'] : null;

        $sql = 'SELECT id, store_id, printer_code, printer_name, provider, endpoint, enabled, last_status, last_error, created_at, updated_at
                FROM qiling_printers
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

    public static function upsertPrinter(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $printerName = Request::str($data, 'printer_name');
        if ($printerName === '') {
            Response::json(['message' => 'printer_name is required'], 422);
            return;
        }

        $provider = Request::str($data, 'provider', 'manual');
        $enabled = (int) ($data['enabled'] ?? 1) === 1 ? 1 : 0;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $printerId = Request::int($data, 'id', 0);
            $now = gmdate('Y-m-d H:i:s');

            if ($printerId > 0) {
                $exists = $pdo->prepare(
                    'SELECT id, store_id, printer_code
                     FROM qiling_printers
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE'
                );
                $exists->execute(['id' => $printerId]);
                $printer = $exists->fetch(PDO::FETCH_ASSOC);
                if (!is_array($printer)) {
                    $pdo->rollBack();
                    Response::json(['message' => 'printer not found'], 404);
                    return;
                }

                DataScope::assertGlobalStoreAdminOnly($user, (int) ($printer['store_id'] ?? 0));
                $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', (int) ($printer['store_id'] ?? 0)), true);
                DataScope::assertGlobalStoreAdminOnly($user, $storeId);

                $printerCode = strtoupper(Request::str($data, 'printer_code', (string) ($printer['printer_code'] ?? '')));
                if ($printerCode === '') {
                    $printerCode = (string) ($printer['printer_code'] ?? '');
                }

                $update = $pdo->prepare(
                    'UPDATE qiling_printers
                     SET store_id = :store_id,
                         printer_code = :printer_code,
                         printer_name = :printer_name,
                         provider = :provider,
                         endpoint = :endpoint,
                         api_key = :api_key,
                         enabled = :enabled,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'store_id' => $storeId,
                    'printer_code' => $printerCode,
                    'printer_name' => $printerName,
                    'provider' => $provider,
                    'endpoint' => Request::str($data, 'endpoint'),
                    'api_key' => Request::str($data, 'api_key'),
                    'enabled' => $enabled,
                    'updated_at' => $now,
                    'id' => $printerId,
                ]);

                Audit::log((int) $user['id'], 'printer.update', 'printer', $printerId, 'Update printer', [
                    'store_id' => $storeId,
                    'printer_code' => $printerCode,
                ]);

                $pdo->commit();
                Response::json([
                    'printer_id' => $printerId,
                    'printer_code' => $printerCode,
                    'store_id' => $storeId,
                ]);
                return;
            }

            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0), true);
            DataScope::assertGlobalStoreAdminOnly($user, $storeId);
            $printerCode = strtoupper(Request::str($data, 'printer_code'));
            if ($printerCode === '') {
                $printerCode = 'QLPT' . random_int(10000, 99999);
            }

            $insert = $pdo->prepare(
                'INSERT INTO qiling_printers
                 (store_id, printer_code, printer_name, provider, endpoint, api_key, enabled, last_status, last_error, created_at, updated_at)
                 VALUES
                 (:store_id, :printer_code, :printer_name, :provider, :endpoint, :api_key, :enabled, :last_status, :last_error, :created_at, :updated_at)'
            );
            $insert->execute([
                'store_id' => $storeId,
                'printer_code' => $printerCode,
                'printer_name' => $printerName,
                'provider' => $provider,
                'endpoint' => Request::str($data, 'endpoint'),
                'api_key' => Request::str($data, 'api_key'),
                'enabled' => $enabled,
                'last_status' => '',
                'last_error' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $newId = (int) $pdo->lastInsertId();

            Audit::log((int) $user['id'], 'printer.create', 'printer', $newId, 'Create printer', [
                'store_id' => $storeId,
                'printer_code' => $printerCode,
            ]);

            $pdo->commit();
            Response::json([
                'printer_id' => $newId,
                'printer_code' => $printerCode,
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
            Response::serverError('upsert printer failed', $e);
        }
    }

    public static function jobs(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        $printerId = isset($_GET['printer_id']) && is_numeric($_GET['printer_id']) ? (int) $_GET['printer_id'] : 0;
        $businessType = isset($_GET['business_type']) && is_string($_GET['business_type']) ? trim($_GET['business_type']) : '';
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT j.*, p.printer_code, p.printer_name
                FROM qiling_print_jobs j
                LEFT JOIN qiling_printers p ON p.id = j.printer_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND j.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($status !== '') {
            $sql .= ' AND j.status = :status';
            $params['status'] = $status;
        }
        if ($printerId > 0) {
            $sql .= ' AND j.printer_id = :printer_id';
            $params['printer_id'] = $printerId;
        }
        if ($businessType !== '') {
            $sql .= ' AND j.business_type = :business_type';
            $params['business_type'] = $businessType;
        }
        $sql .= ' ORDER BY j.id DESC LIMIT ' . $limit;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function createJob(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $businessType = Request::str($data, 'business_type', 'manual');
        $businessId = Request::int($data, 'business_id', 0);
        $printerId = Request::int($data, 'printer_id', 0);

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            if ($businessType === 'order_receipt') {
                if ($businessId <= 0) {
                    throw new \RuntimeException('business_id is required for order_receipt');
                }

                $orderStmt = $pdo->prepare(
                    'SELECT id, store_id
                     FROM qiling_orders
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE'
                );
                $orderStmt->execute(['id' => $businessId]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($order)) {
                    throw new \RuntimeException('order not found');
                }

                $storeId = (int) ($order['store_id'] ?? 0);
                DataScope::assertStoreAccess($user, $storeId);
                $job = PrintService::createOrderReceiptJob(
                    $pdo,
                    (int) ($order['id'] ?? 0),
                    $storeId,
                    (int) $user['id'],
                    $printerId > 0 ? $printerId : null
                );
                if (!is_array($job)) {
                    throw new \RuntimeException('create print job failed, maybe no available printer');
                }

                Audit::log((int) $user['id'], 'print_job.create', 'print_job', (int) ($job['print_job_id'] ?? 0), 'Create print job', [
                    'business_type' => 'order_receipt',
                    'business_id' => $businessId,
                ]);

                $pdo->commit();
                Response::json($job, 201);
                return;
            }

            $content = Request::str($data, 'content');
            if ($content === '') {
                throw new \RuntimeException('content is required for manual print job');
            }

            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0), false);
            $job = PrintService::createManualJob(
                $pdo,
                $storeId,
                (int) $user['id'],
                $content,
                $businessType,
                $businessId,
                $printerId > 0 ? $printerId : null
            );
            $jobId = (int) ($job['print_job_id'] ?? 0);

            Audit::log((int) $user['id'], 'print_job.create', 'print_job', $jobId, 'Create print job', [
                'business_type' => $businessType,
                'business_id' => $businessId,
            ]);

            $pdo->commit();
            Response::json($job, 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create print job failed', $e);
        }
    }

    public static function dispatch(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $requestedStoreId = Request::int($data, 'store_id', 0);
        $filterStoreId = DataScope::resolveFilterStoreId($user, $requestedStoreId > 0 ? $requestedStoreId : null);
        $printerId = Request::int($data, 'printer_id', 0);
        $limit = Request::int($data, 'limit', 20);

        $result = PrintService::dispatchPending(
            Database::pdo(),
            (int) $user['id'],
            $limit,
            $filterStoreId,
            $printerId > 0 ? $printerId : null
        );

        Audit::log((int) $user['id'], 'print_job.dispatch', 'print_job', 0, 'Dispatch print jobs', [
            'store_id' => $filterStoreId,
            'printer_id' => $printerId > 0 ? $printerId : null,
            'total' => (int) ($result['total'] ?? 0),
            'success' => (int) ($result['success'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
        ]);

        Response::json($result);
    }
}
