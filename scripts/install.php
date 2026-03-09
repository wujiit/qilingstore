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

    $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition);
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

    $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD ' . $indexDefinition);
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

$statements = preg_split('/;\s*(?:\r?\n|$)/', $schemaSql);
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
$ensureColumn($pdo, 'qiling_users', 'token_version', 'INT NOT NULL DEFAULT 1');

// v0.6+: 回访消息推送字段兼容迁移（支持旧环境重跑 install.sh）。
$ensureColumn($pdo, 'qiling_followup_tasks', 'notify_status', 'VARCHAR(20) NOT NULL DEFAULT \'pending\'');
$ensureColumn($pdo, 'qiling_followup_tasks', 'notified_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_followup_tasks', 'notify_channel_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_followup_tasks', 'notify_error', 'VARCHAR(500) NOT NULL DEFAULT \'\'');
$ensureColumn($pdo, 'qiling_services', 'supports_online_booking', 'TINYINT(1) NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_report_daily_channel', 'paid_customers', 'INT NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'channel', 'VARCHAR(20) NOT NULL DEFAULT \'email\'');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'receiver', 'VARCHAR(160) NOT NULL DEFAULT \'\'');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'code_hash', 'CHAR(64) NOT NULL DEFAULT \'\'');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'expire_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'used_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'fail_count', 'INT NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'request_ip', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'requested_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'created_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_password_reset_requests', 'updated_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_companies', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_crm_companies', 'archived_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_companies', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_companies', 'deleted_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_companies', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_companies', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_companies', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_companies', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
$ensureColumn($pdo, 'qiling_crm_contacts', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_crm_contacts', 'archived_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_contacts', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_contacts', 'deleted_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_contacts', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_contacts', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_contacts', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_contacts', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
$ensureColumn($pdo, 'qiling_crm_leads', 'visibility_scope', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
$ensureColumn($pdo, 'qiling_crm_leads', 'public_pool_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_leads', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
$ensureColumn($pdo, 'qiling_crm_leads', 'archived_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_leads', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_leads', 'deleted_at', 'DATETIME NULL');
$ensureColumn($pdo, 'qiling_crm_leads', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_leads', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_leads', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_leads', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
$ensureColumn($pdo, 'qiling_crm_deals', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_deals', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_deals', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
$ensureColumn($pdo, 'qiling_crm_activities', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_activities', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
$ensureColumn($pdo, 'qiling_crm_activities', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
$ensureColumn($pdo, 'qiling_crm_assignment_rules', 'last_pick_index', 'INT NOT NULL DEFAULT -1');

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

// v1.0+: 高频列表与回访队列性能索引（支持旧环境重跑 install.sh）。
$ensureIndex($pdo, 'qiling_customer_portal_tokens', 'idx_qiling_customer_portal_tokens_customer_status_expire_id', 'INDEX idx_qiling_customer_portal_tokens_customer_status_expire_id (customer_id, status, expire_at, id)');
$ensureIndex($pdo, 'qiling_coupons', 'idx_qiling_coupons_customer_id_id', 'INDEX idx_qiling_coupons_customer_id_id (customer_id, id)');
$ensureIndex($pdo, 'qiling_customer_consume_records', 'idx_qiling_customer_consume_records_customer_id_id', 'INDEX idx_qiling_customer_consume_records_customer_id_id (customer_id, id)');
$ensureIndex($pdo, 'qiling_appointments', 'idx_qiling_appointments_store_status_id', 'INDEX idx_qiling_appointments_store_status_id (store_id, status, id)');
$ensureIndex($pdo, 'qiling_appointments', 'idx_qiling_appointments_status_id', 'INDEX idx_qiling_appointments_status_id (status, id)');
$ensureIndex($pdo, 'qiling_orders', 'idx_qiling_orders_store_status_id', 'INDEX idx_qiling_orders_store_status_id (store_id, status, id)');
$ensureIndex($pdo, 'qiling_orders', 'idx_qiling_orders_customer_id_id', 'INDEX idx_qiling_orders_customer_id_id (customer_id, id)');
$ensureIndex($pdo, 'qiling_online_payments', 'idx_qiling_online_payments_order_status_id', 'INDEX idx_qiling_online_payments_order_status_id (order_id, status, id)');
$ensureIndex($pdo, 'qiling_followup_tasks', 'idx_qiling_followup_tasks_store_status_due_id', 'INDEX idx_qiling_followup_tasks_store_status_due_id (store_id, status, due_at, id)');
$ensureIndex($pdo, 'qiling_followup_tasks', 'idx_qiling_followup_tasks_status_notify_due_id', 'INDEX idx_qiling_followup_tasks_status_notify_due_id (status, notify_status, due_at, id)');
$ensureIndex($pdo, 'qiling_followup_tasks', 'idx_qiling_followup_tasks_appointment_status', 'INDEX idx_qiling_followup_tasks_appointment_status (appointment_id, status)');
$ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_lookup', 'INDEX idx_qiling_pwd_reset_lookup (user_id, channel, receiver, used_at, id)');
$ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_user', 'INDEX idx_qiling_pwd_reset_user (user_id, requested_at)');
$ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_ip', 'INDEX idx_qiling_pwd_reset_ip (request_ip, requested_at)');
$ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_expire_used', 'INDEX idx_qiling_pwd_reset_expire_used (expire_at, used_at)');

// v1.1+: 报表预聚合索引（支持旧环境重跑 install.sh）。
$ensureIndex($pdo, 'qiling_report_daily_store', 'idx_qiling_report_daily_store_store_date', 'INDEX idx_qiling_report_daily_store_store_date (store_id, report_date)');
$ensureIndex($pdo, 'qiling_report_daily_store', 'idx_qiling_report_daily_store_aggregated_at', 'INDEX idx_qiling_report_daily_store_aggregated_at (aggregated_at)');
$ensureIndex($pdo, 'qiling_report_daily_channel', 'idx_qiling_report_daily_channel_store_date', 'INDEX idx_qiling_report_daily_channel_store_date (store_id, report_date)');
$ensureIndex($pdo, 'qiling_report_daily_channel', 'idx_qiling_report_daily_channel_channel', 'INDEX idx_qiling_report_daily_channel_channel (source_channel)');
$ensureIndex($pdo, 'qiling_report_daily_service', 'idx_qiling_report_daily_service_store_date', 'INDEX idx_qiling_report_daily_service_store_date (store_id, report_date)');
$ensureIndex($pdo, 'qiling_report_daily_service', 'idx_qiling_report_daily_service_sales', 'INDEX idx_qiling_report_daily_service_sales (sales_amount)');
$ensureIndex($pdo, 'qiling_report_aggregate_marks', 'idx_qiling_report_aggregate_marks_aggregated_at', 'INDEX idx_qiling_report_aggregate_marks_aggregated_at (aggregated_at)');

// v1.2+: CRM 独立域索引（支持旧环境重跑 install.sh）。
$ensureIndex($pdo, 'qiling_crm_pipelines', 'idx_qiling_crm_pipelines_status', 'INDEX idx_qiling_crm_pipelines_status (status)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_owner_status', 'INDEX idx_qiling_crm_companies_owner_status (owner_user_id, status, id)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_status_id', 'INDEX idx_qiling_crm_companies_status_id (status, id)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_country', 'INDEX idx_qiling_crm_companies_country (country_code)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_type', 'INDEX idx_qiling_crm_companies_type (company_type)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_created_at', 'INDEX idx_qiling_crm_companies_created_at (created_at)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_company_name', 'INDEX idx_qiling_crm_companies_company_name (company_name)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_created_by_id', 'INDEX idx_qiling_crm_companies_created_by_id (created_by, id)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_company_deleted_id', 'INDEX idx_qiling_crm_companies_company_deleted_id (company_name, deleted_at, id)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_owner_deleted_archived_id', 'INDEX idx_qiling_crm_companies_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_visibility_owner_team', 'INDEX idx_qiling_crm_companies_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_visibility_owner_dept', 'INDEX idx_qiling_crm_companies_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_companies', 'ft_qiling_crm_companies_search', 'FULLTEXT INDEX ft_qiling_crm_companies_search (company_name, website, industry)');
$ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_deleted_archived', 'INDEX idx_qiling_crm_companies_deleted_archived (deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_owner_status', 'INDEX idx_qiling_crm_contacts_owner_status (owner_user_id, status, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_status_id', 'INDEX idx_qiling_crm_contacts_status_id (status, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_company', 'INDEX idx_qiling_crm_contacts_company (company_id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_mobile', 'INDEX idx_qiling_crm_contacts_mobile (mobile)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_email', 'INDEX idx_qiling_crm_contacts_email (email)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_created_at', 'INDEX idx_qiling_crm_contacts_created_at (created_at)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_whatsapp', 'INDEX idx_qiling_crm_contacts_whatsapp (whatsapp)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_created_by_id', 'INDEX idx_qiling_crm_contacts_created_by_id (created_by, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_mobile_deleted_id', 'INDEX idx_qiling_crm_contacts_mobile_deleted_id (mobile, deleted_at, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_email_deleted_id', 'INDEX idx_qiling_crm_contacts_email_deleted_id (email, deleted_at, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_whatsapp_deleted_id', 'INDEX idx_qiling_crm_contacts_whatsapp_deleted_id (whatsapp, deleted_at, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_owner_deleted_archived_id', 'INDEX idx_qiling_crm_contacts_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_company_deleted_archived_id', 'INDEX idx_qiling_crm_contacts_company_deleted_archived_id (company_id, deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_visibility_owner_team', 'INDEX idx_qiling_crm_contacts_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_visibility_owner_dept', 'INDEX idx_qiling_crm_contacts_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'ft_qiling_crm_contacts_search', 'FULLTEXT INDEX ft_qiling_crm_contacts_search (contact_name, mobile, email, whatsapp)');
$ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_deleted_archived', 'INDEX idx_qiling_crm_contacts_deleted_archived (deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_owner_status', 'INDEX idx_qiling_crm_leads_owner_status (owner_user_id, status, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_status_id', 'INDEX idx_qiling_crm_leads_status_id (status, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_owner_intent_id', 'INDEX idx_qiling_crm_leads_owner_intent_id (owner_user_id, intent_level, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_next_followup', 'INDEX idx_qiling_crm_leads_next_followup (next_followup_at, status)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_mobile', 'INDEX idx_qiling_crm_leads_mobile (mobile)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_email', 'INDEX idx_qiling_crm_leads_email (email)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_company', 'INDEX idx_qiling_crm_leads_company (related_company_id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_contact', 'INDEX idx_qiling_crm_leads_contact (related_contact_id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_visibility_owner', 'INDEX idx_qiling_crm_leads_visibility_owner (visibility_scope, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_public_pool', 'INDEX idx_qiling_crm_leads_public_pool (visibility_scope, public_pool_at, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_created_by_id', 'INDEX idx_qiling_crm_leads_created_by_id (created_by, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_company_name_deleted_id', 'INDEX idx_qiling_crm_leads_company_name_deleted_id (company_name, deleted_at, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_mobile_deleted_id', 'INDEX idx_qiling_crm_leads_mobile_deleted_id (mobile, deleted_at, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_email_deleted_id', 'INDEX idx_qiling_crm_leads_email_deleted_id (email, deleted_at, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_owner_deleted_archived_id', 'INDEX idx_qiling_crm_leads_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_created_deleted_archived_id', 'INDEX idx_qiling_crm_leads_created_deleted_archived_id (created_by, deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_visibility_deleted_archived_pool', 'INDEX idx_qiling_crm_leads_visibility_deleted_archived_pool (visibility_scope, deleted_at, is_archived, public_pool_at, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_visibility_owner_team', 'INDEX idx_qiling_crm_leads_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_visibility_owner_dept', 'INDEX idx_qiling_crm_leads_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_leads', 'ft_qiling_crm_leads_search', 'FULLTEXT INDEX ft_qiling_crm_leads_search (lead_name, mobile, email, company_name)');
$ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_deleted_archived', 'INDEX idx_qiling_crm_leads_deleted_archived (deleted_at, is_archived, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_owner_status', 'INDEX idx_qiling_crm_deals_owner_status (owner_user_id, deal_status, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_status_id', 'INDEX idx_qiling_crm_deals_status_id (deal_status, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_pipeline_stage', 'INDEX idx_qiling_crm_deals_pipeline_stage (pipeline_key, stage_key)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_pipeline_stage_id', 'INDEX idx_qiling_crm_deals_pipeline_stage_id (pipeline_key, stage_key, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_expected_close', 'INDEX idx_qiling_crm_deals_expected_close (expected_close_date)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_owner_id', 'INDEX idx_qiling_crm_deals_owner_id (owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_company', 'INDEX idx_qiling_crm_deals_company (company_id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_contact', 'INDEX idx_qiling_crm_deals_contact (contact_id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_lead', 'INDEX idx_qiling_crm_deals_lead (lead_id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_created_by_id', 'INDEX idx_qiling_crm_deals_created_by_id (created_by, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_visibility_owner_team', 'INDEX idx_qiling_crm_deals_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_visibility_owner_dept', 'INDEX idx_qiling_crm_deals_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_deals', 'ft_qiling_crm_deals_name', 'FULLTEXT INDEX ft_qiling_crm_deals_name (deal_name)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_owner_status_due', 'INDEX idx_qiling_crm_activities_owner_status_due (owner_user_id, status, due_at, id)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_status_due_id', 'INDEX idx_qiling_crm_activities_status_due_id (status, due_at, id)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_entity', 'INDEX idx_qiling_crm_activities_entity (entity_type, entity_id, id)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_owner_id', 'INDEX idx_qiling_crm_activities_owner_id (owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_created_by_id', 'INDEX idx_qiling_crm_activities_created_by_id (created_by, id)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_visibility_owner_team', 'INDEX idx_qiling_crm_activities_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_visibility_owner_dept', 'INDEX idx_qiling_crm_activities_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_due_status', 'INDEX idx_qiling_crm_activities_due_status (due_at, status)');
$ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_entity', 'INDEX idx_qiling_crm_transfer_logs_entity (entity_type, entity_id, id)');
$ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_entity_type_id', 'INDEX idx_qiling_crm_transfer_logs_entity_type_id (entity_type, id)');
$ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_to_owner', 'INDEX idx_qiling_crm_transfer_logs_to_owner (to_owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_from_owner', 'INDEX idx_qiling_crm_transfer_logs_from_owner (from_owner_user_id, id)');
$ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_created_by', 'INDEX idx_qiling_crm_transfer_logs_created_by (created_by, id)');
$ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_created_at', 'INDEX idx_qiling_crm_transfer_logs_created_at (created_at)');
$ensureIndex($pdo, 'qiling_crm_assignment_rules', 'idx_qiling_crm_assignment_rules_entity_enabled', 'INDEX idx_qiling_crm_assignment_rules_entity_enabled (entity_type, enabled, id)');
$ensureIndex($pdo, 'qiling_crm_assignment_rules', 'idx_qiling_crm_assignment_rules_source_scope', 'INDEX idx_qiling_crm_assignment_rules_source_scope (source_scope, enabled, id)');

$now = gmdate('Y-m-d H:i:s');
$crmPermissionsView = [
    'crm',
    'crm.dashboard.view',
    'crm.pipelines.view',
    'crm.companies.view',
    'crm.contacts.view',
    'crm.leads.view',
    'crm.deals.view',
    'crm.activities.view',
    'crm.org.view',
    'crm.custom_fields.view',
    'crm.form_config.view',
    'crm.reminders.view',
];
$crmPermissionsEdit = [
    'crm.companies.edit',
    'crm.contacts.edit',
    'crm.leads.edit',
    'crm.leads.convert',
    'crm.deals.edit',
    'crm.activities.edit',
    'crm.org.edit',
    'crm.custom_fields.edit',
    'crm.form_config.edit',
    'crm.reminders.edit',
];
$crmPermissionsManage = [
    'crm.scope.all',
    'crm.pipelines.manage',
    'crm.leads.assign',
    'crm.governance.manage',
    'crm.assignment_rules.view',
    'crm.assignment_rules.edit',
    'crm.assignment_rules.manage',
    'crm.transfer_logs.view',
];

$roles = [
    ['admin', '系统管理员', array_merge(['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users', 'system'], $crmPermissionsView, $crmPermissionsEdit, $crmPermissionsManage)],
    ['manager', '门店经理', array_merge(['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users'], $crmPermissionsView, $crmPermissionsEdit, $crmPermissionsManage)],
    ['consultant', '顾问', array_merge(['dashboard', 'customers', 'member_cards', 'orders', 'appointments', 'followup', 'reports', 'points', 'prints'], $crmPermissionsView, $crmPermissionsEdit)],
    ['therapist', '护理师', array_merge(['dashboard', 'customers', 'appointments', 'followup', 'performance'], $crmPermissionsView, $crmPermissionsEdit)],
    ['reception', '前台', array_merge(['dashboard', 'customers', 'orders', 'appointments', 'followup'], $crmPermissionsView, $crmPermissionsEdit)],
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

$crmPipelineStmt = $pdo->prepare(
    'INSERT INTO qiling_crm_pipelines
     (pipeline_key, pipeline_name, stages_json, is_system, status, created_at, updated_at)
     VALUES
     (:pipeline_key, :pipeline_name, :stages_json, 1, :status, :created_at, :updated_at)
     ON DUPLICATE KEY UPDATE
        pipeline_name = VALUES(pipeline_name),
        stages_json = VALUES(stages_json),
        status = VALUES(status),
        updated_at = VALUES(updated_at)'
);
$crmPipelineStmt->execute([
    'pipeline_key' => 'default',
    'pipeline_name' => '默认销售管道',
    'stages_json' => json_encode([
        ['key' => 'new', 'name' => '新建线索', 'sort' => 10],
        ['key' => 'contacted', 'name' => '已触达', 'sort' => 20],
        ['key' => 'qualified', 'name' => '已确认需求', 'sort' => 30],
        ['key' => 'proposal', 'name' => '方案/报价', 'sort' => 40],
        ['key' => 'negotiation', 'name' => '商务谈判', 'sort' => 50],
        ['key' => 'won', 'name' => '赢单', 'sort' => 60],
        ['key' => 'lost', 'name' => '输单', 'sort' => 70],
    ], JSON_UNESCAPED_UNICODE),
    'status' => 'active',
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
