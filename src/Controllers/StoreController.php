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

final class StoreController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        if (DataScope::isAdmin($user)) {
            $stmt = Database::pdo()->query('SELECT * FROM qiling_stores ORDER BY id DESC');
        } else {
            $storeId = DataScope::resolveFilterStoreId($user, null);
            $stmt = Database::pdo()->prepare('SELECT * FROM qiling_stores WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $storeId]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json(['data' => $rows]);
    }

    public static function create(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);
        $data = Request::jsonBody();

        $storeName = Request::str($data, 'store_name');
        if ($storeName === '') {
            Response::json(['message' => 'store_name is required'], 422);
            return;
        }

        $storeCode = strtoupper(Request::str($data, 'store_code'));
        if ($storeCode === '') {
            $storeCode = 'QLS' . random_int(10000, 99999);
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO qiling_stores (store_code, store_name, contact_name, contact_phone, address, open_time, close_time, status, created_at, updated_at)
             VALUES (:store_code, :store_name, :contact_name, :contact_phone, :address, :open_time, :close_time, :status, :created_at, :updated_at)'
        );

        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'store_code' => $storeCode,
            'store_name' => $storeName,
            'contact_name' => Request::str($data, 'contact_name'),
            'contact_phone' => Request::str($data, 'contact_phone'),
            'address' => Request::str($data, 'address'),
            'open_time' => Request::str($data, 'open_time'),
            'close_time' => Request::str($data, 'close_time'),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) Database::pdo()->lastInsertId();

        Audit::log((int) $user['id'], 'store.create', 'store', $id, 'Create store', ['store_code' => $storeCode]);

        Response::json(['id' => $id, 'store_code' => $storeCode], 201);
    }

    public static function update(): void
    {
        $actor = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($actor);
        $data = Request::jsonBody();

        $storeId = Request::int($data, 'id', 0);
        if ($storeId <= 0) {
            Response::json(['message' => 'id is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM qiling_stores WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $storeId]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($store)) {
            Response::json(['message' => 'store not found'], 404);
            return;
        }

        $storeName = Request::str($data, 'store_name', (string) ($store['store_name'] ?? ''));
        if ($storeName === '') {
            Response::json(['message' => 'store_name is required'], 422);
            return;
        }

        $status = Request::str($data, 'status', (string) ($store['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            Response::json(['message' => 'status must be active or inactive'], 422);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $update = $pdo->prepare(
            'UPDATE qiling_stores
             SET store_name = :store_name,
                 contact_name = :contact_name,
                 contact_phone = :contact_phone,
                 address = :address,
                 open_time = :open_time,
                 close_time = :close_time,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'id' => $storeId,
            'store_name' => $storeName,
            'contact_name' => Request::str($data, 'contact_name', (string) ($store['contact_name'] ?? '')),
            'contact_phone' => Request::str($data, 'contact_phone', (string) ($store['contact_phone'] ?? '')),
            'address' => Request::str($data, 'address', (string) ($store['address'] ?? '')),
            'open_time' => Request::str($data, 'open_time', (string) ($store['open_time'] ?? '')),
            'close_time' => Request::str($data, 'close_time', (string) ($store['close_time'] ?? '')),
            'status' => $status,
            'updated_at' => $now,
        ]);

        Audit::log((int) $actor['id'], 'store.update', 'store', $storeId, 'Update store', [
            'status' => $status,
        ]);

        Response::json(['id' => $storeId, 'updated' => true]);
    }
}
