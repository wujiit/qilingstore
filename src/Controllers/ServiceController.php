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

final class ServiceController
{
    private static bool $categoryTableReady = false;
    private static bool $serviceSchemaReady = false;

    public static function ensureServiceSchema(PDO $pdo): void
    {
        if (self::$serviceSchemaReady) {
            return;
        }

        self::ensureCategoryTable($pdo);

        $columnStmt = $pdo->prepare("SHOW COLUMNS FROM qiling_services LIKE 'supports_online_booking'");
        $columnStmt->execute();
        $hasColumn = $columnStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($hasColumn)) {
            $pdo->exec('ALTER TABLE qiling_services ADD COLUMN supports_online_booking TINYINT(1) NOT NULL DEFAULT 0 AFTER category');
        }

        self::$serviceSchemaReady = true;
    }

    private static function ensureCategoryTable(PDO $pdo): void
    {
        if (self::$categoryTableReady) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS qiling_service_categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                category_name VARCHAR(60) NOT NULL,
                sort_order INT NOT NULL DEFAULT 100,
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_qiling_service_categories_store_name (store_id, category_name),
                KEY idx_qiling_service_categories_store_id (store_id),
                KEY idx_qiling_service_categories_status (status),
                KEY idx_qiling_service_categories_sort (sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        self::$categoryTableReady = true;
    }

    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        self::ensureServiceSchema(Database::pdo());

        $sql = 'SELECT * FROM qiling_services';
        $params = [];
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(['data' => $rows]);
    }

    public static function create(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $serviceName = Request::str($data, 'service_name');
        if ($serviceName === '') {
            Response::json(['message' => 'service_name is required'], 422);
            return;
        }

        $serviceCode = strtoupper(Request::str($data, 'service_code'));
        if ($serviceCode === '') {
            $serviceCode = 'QLSV' . random_int(10000, 99999);
        }

        $pdo = Database::pdo();
        self::ensureServiceSchema($pdo);

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_services (service_code, store_id, service_name, category, supports_online_booking, duration_minutes, list_price, status, created_at, updated_at)
             VALUES (:service_code, :store_id, :service_name, :category, :supports_online_booking, :duration_minutes, :list_price, :status, :created_at, :updated_at)'
        );
        $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0));
        $category = self::normalizeCategoryName(Request::str($data, 'category'));

        $pdo->beginTransaction();
        try {
            if ($category !== '') {
                self::upsertCategory($pdo, $storeId, $category, 100, 'active');
            }

            $stmt->execute([
                'service_code' => $serviceCode,
                'store_id' => $storeId,
                'service_name' => $serviceName,
                'category' => $category,
                'supports_online_booking' => Request::int($data, 'supports_online_booking', 0) === 1 ? 1 : 0,
                'duration_minutes' => max(5, Request::int($data, 'duration_minutes', 60)),
                'list_price' => max(0, (float) ($data['list_price'] ?? 0)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create service failed', $e);
            return;
        }

        Audit::log((int) $user['id'], 'service.create', 'service', $id, 'Create service', ['service_code' => $serviceCode]);

        Response::json(['id' => $id, 'service_code' => $serviceCode], 201);
    }

    public static function categoryIndex(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) {
            Response::json(['message' => 'invalid status'], 422);
            return;
        }

        $pdo = Database::pdo();
        self::ensureServiceSchema($pdo);
        $sql = 'SELECT sc.id, sc.store_id, sc.category_name, sc.sort_order, sc.status, sc.created_at, sc.updated_at,
                       s.store_name
                FROM qiling_service_categories sc
                LEFT JOIN qiling_stores s ON s.id = sc.store_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND sc.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($status !== '') {
            $sql .= ' AND sc.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY sc.sort_order ASC, sc.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(['data' => is_array($rows) ? $rows : []]);
    }

    public static function createCategory(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $categoryName = self::normalizeCategoryName(Request::str($data, 'category_name'));
        if ($categoryName === '') {
            Response::json(['message' => 'category_name is required'], 422);
            return;
        }
        $status = strtolower(Request::str($data, 'status', 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0));
        $sortOrder = max(0, min(Request::int($data, 'sort_order', 100), 9999));

        $pdo = Database::pdo();
        self::ensureServiceSchema($pdo);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_service_categories
             (store_id, category_name, sort_order, status, created_at, updated_at)
             VALUES
             (:store_id, :category_name, :sort_order, :status, :created_at, :updated_at)'
        );
        try {
            $stmt->execute([
                'store_id' => $storeId,
                'category_name' => $categoryName,
                'sort_order' => $sortOrder,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $e) {
            if (self::isDuplicateError($e)) {
                Response::json(['message' => 'service category already exists'], 409);
                return;
            }
            throw $e;
        }

        $id = (int) $pdo->lastInsertId();
        Audit::log((int) $user['id'], 'service_category.create', 'service_category', $id, 'Create service category', [
            'store_id' => $storeId,
            'category_name' => $categoryName,
        ]);

        Response::json(['id' => $id], 201);
    }

    public static function updateCategory(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $id = Request::int($data, 'id', 0);
        if ($id <= 0) {
            Response::json(['message' => 'id is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        self::ensureServiceSchema($pdo);
        $currentStmt = $pdo->prepare(
            'SELECT id, store_id, category_name, sort_order, status
             FROM qiling_service_categories
             WHERE id = :id
             LIMIT 1'
        );
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            Response::json(['message' => 'service category not found'], 404);
            return;
        }

        DataScope::assertStoreAccess($user, (int) ($current['store_id'] ?? 0));
        $categoryName = self::normalizeCategoryName(Request::str($data, 'category_name', (string) ($current['category_name'] ?? '')));
        if ($categoryName === '') {
            Response::json(['message' => 'category_name is required'], 422);
            return;
        }
        $status = strtolower(Request::str($data, 'status', (string) ($current['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        $sortOrder = max(0, min(Request::int($data, 'sort_order', (int) ($current['sort_order'] ?? 100)), 9999));

        $stmt = $pdo->prepare(
            'UPDATE qiling_service_categories
             SET category_name = :category_name,
                 sort_order = :sort_order,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        try {
            $stmt->execute([
                'id' => $id,
                'category_name' => $categoryName,
                'sort_order' => $sortOrder,
                'status' => $status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            if (self::isDuplicateError($e)) {
                Response::json(['message' => 'service category already exists'], 409);
                return;
            }
            throw $e;
        }

        Audit::log((int) $user['id'], 'service_category.update', 'service_category', $id, 'Update service category', [
            'store_id' => (int) ($current['store_id'] ?? 0),
            'category_name' => $categoryName,
            'status' => $status,
        ]);

        Response::json(['id' => $id, 'updated' => true]);
    }

    public static function packageIndex(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        self::ensureServiceSchema(Database::pdo());

        $sql = 'SELECT p.*, s.service_name
                FROM qiling_service_packages p
                LEFT JOIN qiling_services s ON s.id = p.service_id';
        $params = [];
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE p.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY p.id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(['data' => $rows]);
    }

    public static function createPackage(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        self::ensureServiceSchema(Database::pdo());

        $packageName = Request::str($data, 'package_name');
        if ($packageName === '') {
            Response::json(['message' => 'package_name is required'], 422);
            return;
        }

        $packageCode = strtoupper(Request::str($data, 'package_code'));
        if ($packageCode === '') {
            $packageCode = 'QLPK' . random_int(10000, 99999);
        }

        $totalSessions = Request::int($data, 'total_sessions', 1);
        if ($totalSessions <= 0) {
            Response::json(['message' => 'total_sessions must be positive'], 422);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::pdo()->prepare(
            'INSERT INTO qiling_service_packages (package_code, store_id, package_name, service_id, total_sessions, sale_price, valid_days, status, created_at, updated_at)
             VALUES (:package_code, :store_id, :package_name, :service_id, :total_sessions, :sale_price, :valid_days, :status, :created_at, :updated_at)'
        );
        $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0));

        $stmt->execute([
            'package_code' => $packageCode,
            'store_id' => $storeId,
            'package_name' => $packageName,
            'service_id' => Request::int($data, 'service_id', 0) ?: null,
            'total_sessions' => $totalSessions,
            'sale_price' => max(0, (float) ($data['sale_price'] ?? 0)),
            'valid_days' => max(1, Request::int($data, 'valid_days', 365)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        Audit::log((int) $user['id'], 'package.create', 'service_package', $id, 'Create service package', ['package_code' => $packageCode]);

        Response::json(['id' => $id, 'package_code' => $packageCode], 201);
    }

    private static function normalizeCategoryName(string $name): string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($raw === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return (string) mb_substr($raw, 0, 60);
        }
        return substr($raw, 0, 60);
    }

    private static function upsertCategory(PDO $pdo, int $storeId, string $categoryName, int $sortOrder, string $status): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_service_categories
             (store_id, category_name, sort_order, status, created_at, updated_at)
             VALUES
             (:store_id, :category_name, :sort_order, :status, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 updated_at = VALUES(updated_at)'
        );
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'store_id' => $storeId,
            'category_name' => $categoryName,
            'sort_order' => $sortOrder,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function isDuplicateError(\PDOException $e): bool
    {
        $message = strtolower((string) $e->getMessage());
        return str_contains($message, '1062') || str_contains($message, 'duplicate');
    }
}
