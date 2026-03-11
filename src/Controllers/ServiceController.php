<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use RuntimeException;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class ServiceController
{
    private static bool $serviceSchemaReady = false;

    public static function ensureServiceSchema(PDO $pdo): void
    {
        if (self::$serviceSchemaReady) {
            return;
        }

        if (!self::hasTable($pdo, 'qiling_service_categories')) {
            throw new RuntimeException('数据库结构未升级：缺少 qiling_service_categories，请先执行系统升级。');
        }
        if (!self::hasColumn($pdo, 'qiling_services', 'supports_online_booking')) {
            throw new RuntimeException('数据库结构未升级：缺少 qiling_services.supports_online_booking，请先执行系统升级。');
        }

        self::$serviceSchemaReady = true;
    }

    private static function hasTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $stmt->execute([
            'table_name' => $table,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
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

    public static function update(): void
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
        $stmt = $pdo->prepare('SELECT * FROM qiling_services WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            Response::json(['message' => 'service not found'], 404);
            return;
        }

        $currentStoreId = (int) ($current['store_id'] ?? 0);
        DataScope::assertStoreAccess($user, $currentStoreId);

        $storeId = $currentStoreId;
        if (array_key_exists('store_id', $data)) {
            $storeInput = Request::int($data, 'store_id', $currentStoreId);
            $storeId = DataScope::resolveInputStoreId($user, $storeInput, true);
            if ($storeId <= 0) {
                $storeId = $currentStoreId;
            }
        }

        $serviceName = Request::str($data, 'service_name', (string) ($current['service_name'] ?? ''));
        if ($serviceName === '') {
            Response::json(['message' => 'service_name is required'], 422);
            return;
        }
        $category = self::normalizeCategoryName(Request::str($data, 'category', (string) ($current['category'] ?? '')));
        $status = Request::str($data, 'status', (string) ($current['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            Response::json(['message' => 'status must be active or inactive'], 422);
            return;
        }

        $supportsOnlineBooking = Request::int($data, 'supports_online_booking', (int) ($current['supports_online_booking'] ?? 0)) === 1 ? 1 : 0;
        $durationMinutes = max(5, Request::int($data, 'duration_minutes', (int) ($current['duration_minutes'] ?? 60)));
        $listPriceRaw = $data['list_price'] ?? ($current['list_price'] ?? 0);
        $listPrice = max(0, (float) $listPriceRaw);
        $now = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            if ($category !== '') {
                self::upsertCategory($pdo, $storeId, $category, 100, 'active');
            }

            $update = $pdo->prepare(
                'UPDATE qiling_services
                 SET store_id = :store_id,
                     service_name = :service_name,
                     category = :category,
                     supports_online_booking = :supports_online_booking,
                     duration_minutes = :duration_minutes,
                     list_price = :list_price,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $id,
                'store_id' => $storeId,
                'service_name' => $serviceName,
                'category' => $category,
                'supports_online_booking' => $supportsOnlineBooking,
                'duration_minutes' => $durationMinutes,
                'list_price' => $listPrice,
                'status' => $status,
                'updated_at' => $now,
            ]);

            Audit::log((int) $user['id'], 'service.update', 'service', $id, 'Update service', [
                'store_id' => $storeId,
                'status' => $status,
            ]);

            $pdo->commit();
            Response::json(['id' => $id, 'updated' => true]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('update service failed', $e);
        }
    }

    public static function remove(): void
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
        $stmt = $pdo->prepare('SELECT id, store_id, status FROM qiling_services WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            Response::json(['message' => 'service not found'], 404);
            return;
        }
        DataScope::assertStoreAccess($user, (int) ($current['store_id'] ?? 0));

        $update = $pdo->prepare('UPDATE qiling_services SET status = :status, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            'id' => $id,
            'status' => 'inactive',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log((int) $user['id'], 'service.delete', 'service', $id, 'Soft delete service', [
            'store_id' => (int) ($current['store_id'] ?? 0),
            'before_status' => (string) ($current['status'] ?? ''),
            'after_status' => 'inactive',
        ]);

        Response::json(['id' => $id, 'removed' => true, 'status' => 'inactive']);
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

    public static function removeCategory(): void
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
        $stmt = $pdo->prepare('SELECT id, store_id, category_name, status FROM qiling_service_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            Response::json(['message' => 'service category not found'], 404);
            return;
        }

        DataScope::assertStoreAccess($user, (int) ($current['store_id'] ?? 0));
        $update = $pdo->prepare('UPDATE qiling_service_categories SET status = :status, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            'id' => $id,
            'status' => 'inactive',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log((int) $user['id'], 'service_category.delete', 'service_category', $id, 'Soft delete service category', [
            'store_id' => (int) ($current['store_id'] ?? 0),
            'category_name' => (string) ($current['category_name'] ?? ''),
            'before_status' => (string) ($current['status'] ?? ''),
            'after_status' => 'inactive',
        ]);

        Response::json(['id' => $id, 'removed' => true, 'status' => 'inactive']);
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

    public static function updatePackage(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        self::ensureServiceSchema(Database::pdo());

        $id = Request::int($data, 'id', 0);
        if ($id <= 0) {
            Response::json(['message' => 'id is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM qiling_service_packages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            Response::json(['message' => 'service package not found'], 404);
            return;
        }

        $currentStoreId = (int) ($current['store_id'] ?? 0);
        DataScope::assertStoreAccess($user, $currentStoreId);

        $storeId = $currentStoreId;
        if (array_key_exists('store_id', $data)) {
            $storeInput = Request::int($data, 'store_id', $currentStoreId);
            $storeId = DataScope::resolveInputStoreId($user, $storeInput, true);
            if ($storeId <= 0) {
                $storeId = $currentStoreId;
            }
        }

        $packageName = Request::str($data, 'package_name', (string) ($current['package_name'] ?? ''));
        if ($packageName === '') {
            Response::json(['message' => 'package_name is required'], 422);
            return;
        }

        $status = Request::str($data, 'status', (string) ($current['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            Response::json(['message' => 'status must be active or inactive'], 422);
            return;
        }

        $totalSessions = Request::int($data, 'total_sessions', (int) ($current['total_sessions'] ?? 1));
        if ($totalSessions <= 0) {
            Response::json(['message' => 'total_sessions must be positive'], 422);
            return;
        }

        $serviceId = ($current['service_id'] ?? null) !== null
            ? (int) ($current['service_id'] ?? 0)
            : null;
        $serviceIdRaw = array_key_exists('service_id', $data) ? trim((string) ($data['service_id'] ?? '')) : '';
        if ($serviceIdRaw !== '') {
            $serviceIdInput = Request::int($data, 'service_id', 0);
            $serviceId = $serviceIdInput > 0 ? $serviceIdInput : null;
        }
        if ($serviceId !== null) {
            $serviceStmt = $pdo->prepare('SELECT id, store_id FROM qiling_services WHERE id = :id LIMIT 1');
            $serviceStmt->execute(['id' => $serviceId]);
            $serviceRow = $serviceStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($serviceRow)) {
                Response::json(['message' => 'service_id is invalid'], 422);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($serviceRow['store_id'] ?? 0));
        }

        $salePriceRaw = $data['sale_price'] ?? ($current['sale_price'] ?? 0);
        $salePrice = max(0, (float) $salePriceRaw);
        $validDays = max(1, Request::int($data, 'valid_days', (int) ($current['valid_days'] ?? 365)));
        $now = gmdate('Y-m-d H:i:s');

        $update = $pdo->prepare(
            'UPDATE qiling_service_packages
             SET store_id = :store_id,
                 package_name = :package_name,
                 service_id = :service_id,
                 total_sessions = :total_sessions,
                 sale_price = :sale_price,
                 valid_days = :valid_days,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'id' => $id,
            'store_id' => $storeId,
            'package_name' => $packageName,
            'service_id' => $serviceId,
            'total_sessions' => $totalSessions,
            'sale_price' => $salePrice,
            'valid_days' => $validDays,
            'status' => $status,
            'updated_at' => $now,
        ]);

        Audit::log((int) $user['id'], 'package.update', 'service_package', $id, 'Update service package', [
            'store_id' => $storeId,
            'status' => $status,
        ]);

        Response::json(['id' => $id, 'updated' => true]);
    }

    public static function removePackage(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        self::ensureServiceSchema(Database::pdo());

        $id = Request::int($data, 'id', 0);
        if ($id <= 0) {
            Response::json(['message' => 'id is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, store_id, status FROM qiling_service_packages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            Response::json(['message' => 'service package not found'], 404);
            return;
        }

        DataScope::assertStoreAccess($user, (int) ($current['store_id'] ?? 0));
        $update = $pdo->prepare('UPDATE qiling_service_packages SET status = :status, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            'id' => $id,
            'status' => 'inactive',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log((int) $user['id'], 'package.delete', 'service_package', $id, 'Soft delete service package', [
            'store_id' => (int) ($current['store_id'] ?? 0),
            'before_status' => (string) ($current['status'] ?? ''),
            'after_status' => 'inactive',
        ]);

        Response::json(['id' => $id, 'removed' => true, 'status' => 'inactive']);
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
