<?php

declare(strict_types=1);

use Qiling\Core\Config;
use Qiling\Core\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::pdo();

/**
 * 为旧版本数据库补齐字段。
 *
 * @param \PDO  $pdo PDO连接
 * @param string $table 表名
 * @param string $column 字段名
 * @param string $definition 字段定义
 * @return void
 */
$ensureColumn = static function (\PDO $pdo, string $table, string $column, string $definition): void {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $check->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    $exists = (int) $check->fetchColumn() > 0;
    if ($exists) {
        return;
    }

    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
};

/**
 * 为旧版本数据库补齐索引。
 *
 * @param \PDO  $pdo PDO连接
 * @param string $table 表名
 * @param string $indexName 索引名
 * @param string $indexDefinition 索引定义（如 INDEX idx_xxx (a,b)）
 * @return void
 */
$ensureIndex = static function (\PDO $pdo, string $table, string $indexName, string $indexDefinition): void {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $check->execute([
        'table_name' => $table,
        'index_name' => $indexName,
    ]);

    $exists = (int) $check->fetchColumn() > 0;
    if ($exists) {
        return;
    }

    $pdo->exec("ALTER TABLE {$table} ADD {$indexDefinition}");
};

$schemaPath = dirname(__DIR__) . '/sql/schema.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "schema.sql not found\n");
    exit(1);
}

$schemaSql = file_get_contents($schemaPath);
if (!is_string($schemaSql)) {
    fwrite(STDERR, "failed to read schema.sql\n");
    exit(1);
}

$statements = preg_split('/;\s*\n/', $schemaSql);
if (!is_array($statements)) {
    $statements = [];
}

foreach ($statements as $statement) {
    $statement = trim((string) $statement);
    if ($statement === '') {
        continue;
    }

    $pdo->exec($statement);
}

// v0.4+: 预约自动核销回退字段兼容迁移（支持旧环境重跑 install.sh）。
$ensureColumn($pdo, 'qiling_appointment_consumes', 'rolled_back_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_operator_user_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_note', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
$ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_before_sessions', 'INT NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_after_sessions', 'INT NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_users', 'login_failed_attempts', 'INT NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_users', 'login_lock_until', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_users', 'last_login_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_users', 'last_login_ip', "VARCHAR(64) NOT NULL DEFAULT ''");

// v0.6+: 回访消息推送字段兼容迁移（支持旧环境重跑 install.sh）。
$ensureColumn($pdo, 'qiling_followup_tasks', 'notify_status', 'VARCHAR(20) NOT NULL DEFAULT \'pending\'');
$ensureColumn($pdo, 'qiling_followup_tasks', 'notified_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_followup_tasks', 'notify_channel_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_followup_tasks', 'notify_error', 'VARCHAR(500) NOT NULL DEFAULT \'\'');
$ensureColumn($pdo, 'qiling_services', 'supports_online_booking', 'TINYINT(1) NOT NULL DEFAULT 0');

// v0.9+: 运营报表性能索引（支持旧环境重跑 install.sh）。
$ensureIndex($pdo, 'qiling_customers', 'idx_qiling_customers_created_at', 'INDEX idx_qiling_customers_created_at (created_at)');
$ensureIndex($pdo, 'qiling_customers', 'idx_qiling_customers_store_created_at', 'INDEX idx_qiling_customers_store_created_at (store_id, created_at)');
$ensureIndex($pdo, 'qiling_customers', 'idx_qiling_customers_store_source_channel', 'INDEX idx_qiling_customers_store_source_channel (store_id, source_channel)');

$ensureIndex($pdo, 'qiling_member_card_logs', 'idx_qiling_member_card_logs_created_at', 'INDEX idx_qiling_member_card_logs_created_at (created_at)');
$ensureIndex($pdo, 'qiling_member_card_logs', 'idx_qiling_member_card_logs_card_created_at', 'INDEX idx_qiling_member_card_logs_card_created_at (member_card_id, created_at)');

$ensureIndex($pdo, 'qiling_appointments', 'idx_qiling_appointments_store_start_status', 'INDEX idx_qiling_appointments_store_start_status (store_id, start_at, status)');

$ensureIndex($pdo, 'qiling_orders', 'idx_qiling_orders_status_paid_at_store', 'INDEX idx_qiling_orders_status_paid_at_store (status, paid_at, store_id)');
$ensureIndex($pdo, 'qiling_orders', 'idx_qiling_orders_store_paid_at_customer', 'INDEX idx_qiling_orders_store_paid_at_customer (store_id, paid_at, customer_id)');

$ensureIndex($pdo, 'qiling_order_items', 'idx_qiling_order_items_order_item', 'INDEX idx_qiling_order_items_order_item (order_id, item_type, item_ref_id)');
$ensureIndex($pdo, 'qiling_order_items', 'idx_qiling_order_items_staff_order', 'INDEX idx_qiling_order_items_staff_order (staff_id, order_id)');

$ensureIndex($pdo, 'qiling_order_payments', 'idx_qiling_order_payments_status_paid_at', 'INDEX idx_qiling_order_payments_status_paid_at (status, paid_at)');
$ensureIndex($pdo, 'qiling_order_payments', 'idx_qiling_order_payments_paid_at_order_id', 'INDEX idx_qiling_order_payments_paid_at_order_id (paid_at, order_id)');
$ensureIndex($pdo, 'qiling_order_payments', 'idx_qiling_order_payments_pay_method_status_paid_at', 'INDEX idx_qiling_order_payments_pay_method_status_paid_at (pay_method, status, paid_at)');

$now = gmdate('Y-m-d H:i:s');

$roles = [
    ['admin', '系统管理员', ['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users', 'system']],
    ['manager', '门店经理', ['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users']],
    ['consultant', '顾问', ['dashboard', 'customers', 'member_cards', 'orders', 'appointments', 'followup', 'reports', 'points', 'prints']],
    ['therapist', '护理师', ['dashboard', 'customers', 'appointments', 'followup', 'performance']],
    ['reception', '前台', ['dashboard', 'customers', 'orders', 'appointments', 'followup']],
];

foreach ($roles as $role) {
    [$roleKey, $roleName, $permissions] = $role;

    $stmt = $pdo->prepare(
        'INSERT INTO qiling_roles (role_key, role_name, permissions_json, is_system, status, created_at, updated_at)
         VALUES (:role_key, :role_name, :permissions_json, 1, :status, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), permissions_json = VALUES(permissions_json), updated_at = VALUES(updated_at)'
    );

    $stmt->execute([
        'role_key' => $roleKey,
        'role_name' => $roleName,
        'permissions_json' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

$storeStmt = $pdo->prepare('SELECT id FROM qiling_stores WHERE store_code = :store_code LIMIT 1');
$storeStmt->execute(['store_code' => 'QLS00001']);
$storeId = $storeStmt->fetchColumn();

if (!$storeId) {
    $insertStore = $pdo->prepare(
        'INSERT INTO qiling_stores (store_code, store_name, contact_name, contact_phone, address, open_time, close_time, status, created_at, updated_at)
         VALUES (:store_code, :store_name, :contact_name, :contact_phone, :address, :open_time, :close_time, :status, :created_at, :updated_at)'
    );
    $insertStore->execute([
        'store_code' => 'QLS00001',
        'store_name' => '默认门店',
        'contact_name' => '',
        'contact_phone' => '',
        'address' => '',
        'open_time' => '09:00',
        'close_time' => '21:00',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $storeId = (int) $pdo->lastInsertId();
}

$followupPlanStmt = $pdo->prepare(
    'INSERT INTO qiling_followup_plans (store_id, trigger_type, plan_name, schedule_days_json, enabled, created_at, updated_at)
     VALUES (:store_id, :trigger_type, :plan_name, :schedule_days_json, :enabled, :created_at, :updated_at)
     ON DUPLICATE KEY UPDATE
        plan_name = VALUES(plan_name),
        schedule_days_json = VALUES(schedule_days_json),
        enabled = VALUES(enabled),
        updated_at = VALUES(updated_at)'
);
$followupPlanStmt->execute([
    'store_id' => 0,
    'trigger_type' => 'appointment_completed',
    'plan_name' => '默认回访计划',
    'schedule_days_json' => json_encode([1, 3, 7], JSON_UNESCAPED_UNICODE),
    'enabled' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$adminUsername = (string) Config::get('INSTALL_ADMIN_USERNAME', 'admin');
$adminEmail = (string) Config::get('INSTALL_ADMIN_EMAIL', 'admin@qiling.local');
$adminPassword = (string) Config::get('INSTALL_ADMIN_PASSWORD', '');
if ($adminPassword === '') {
    $adminPassword = bin2hex(random_bytes(8));
}

$adminStmt = $pdo->prepare('SELECT id FROM qiling_users WHERE username = :username LIMIT 1');
$adminStmt->execute(['username' => $adminUsername]);
$adminId = $adminStmt->fetchColumn();

if (!$adminId) {
    $insertAdmin = $pdo->prepare(
        'INSERT INTO qiling_users (username, email, password_hash, role_key, status, created_at, updated_at)
         VALUES (:username, :email, :password_hash, :role_key, :status, :created_at, :updated_at)'
    );
    $insertAdmin->execute([
        'username' => $adminUsername,
        'email' => $adminEmail,
        'password_hash' => password_hash($adminPassword, PASSWORD_BCRYPT),
        'role_key' => 'admin',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $adminId = (int) $pdo->lastInsertId();
} else {
    $adminId = (int) $adminId;
    $updateAdminRole = $pdo->prepare('UPDATE qiling_users SET role_key = :role_key, updated_at = :updated_at WHERE id = :id');
    $updateAdminRole->execute([
        'role_key' => 'admin',
        'updated_at' => $now,
        'id' => $adminId,
    ]);
}

$staffStmt = $pdo->prepare('SELECT id FROM qiling_staff WHERE user_id = :user_id LIMIT 1');
$staffStmt->execute(['user_id' => $adminId]);
$staffId = $staffStmt->fetchColumn();

if (!$staffId) {
    $insertStaff = $pdo->prepare(
        'INSERT INTO qiling_staff (user_id, store_id, role_key, staff_no, phone, title, status, created_at, updated_at)
         VALUES (:user_id, :store_id, :role_key, :staff_no, :phone, :title, :status, :created_at, :updated_at)'
    );

    $insertStaff->execute([
        'user_id' => $adminId,
        'store_id' => (int) $storeId,
        'role_key' => 'manager',
        'staff_no' => 'A0001',
        'phone' => '',
        'title' => '系统管理员',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

echo "Install success\n";
echo "Admin username: {$adminUsername}\n";
echo "Admin password: {$adminPassword}\n";
