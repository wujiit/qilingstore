<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use RuntimeException;

final class SystemUpgradeService
{
    public const APP_VERSION = '1.3.2';
    public const SCHEMA_RELEASE = '2026.03.08.2';

    /**
     * @return array<string, mixed>
     */
    public static function status(PDO $pdo): array
    {
        self::ensureUpgradeLogTable($pdo);

        $latest = self::latestUpgradeLog($pdo);
        $latestVersion = is_array($latest) ? (string) ($latest['release_version'] ?? '') : '';
        $currentVersion = $latestVersion !== '' ? $latestVersion : 'unknown';
        $pending = self::detectPendingMigrations($pdo);
        $missingTablesCount = (int) ($pending['missing_tables_count'] ?? 0);
        $schemaNeedsUpgrade = ((int) ($pending['missing_columns_count'] ?? 0) > 0)
            || ((int) ($pending['missing_indexes_count'] ?? 0) > 0)
            || ($missingTablesCount > 0);
        $versionNeedsUpgrade = ($latestVersion === '') || ($latestVersion !== self::APP_VERSION);

        return [
            'release_version' => self::APP_VERSION, // compatibility for old frontend
            'current_version' => $currentVersion,
            'target_version' => self::APP_VERSION,
            'schema_release' => self::SCHEMA_RELEASE,
            'version_upgrade_needed' => $versionNeedsUpgrade,
            'upgrade_needed' => $versionNeedsUpgrade || $schemaNeedsUpgrade,
            'pending_migrations' => $pending,
            'latest_upgrade' => $latest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(PDO $pdo, string $projectRoot, int $actorUserId = 0): array
    {
        $startedAt = gmdate('Y-m-d H:i:s');
        $startedTs = microtime(true);
        $summary = [
            'schema_statements' => 0,
            'columns_added' => 0,
            'indexes_added' => 0,
            'roles_created' => 0,
            'roles_updated' => 0,
            'defaults_created' => 0,
        ];
        $result = [
            'release_version' => self::APP_VERSION,
            'current_version' => self::APP_VERSION,
            'target_version' => self::APP_VERSION,
            'schema_release' => self::SCHEMA_RELEASE,
            'started_at' => $startedAt,
            'finished_at' => '',
            'duration_ms' => 0,
            'summary' => $summary,
            'log_id' => 0,
        ];
        $exception = null;
        $success = false;
        $errorMessage = '';

        try {
            $summary['schema_statements'] = self::applySchema($pdo, $projectRoot . '/sql/schema.sql');

            foreach (self::columnMigrations() as $migration) {
                [$table, $column, $definition] = $migration;
                if (self::ensureColumn($pdo, $table, $column, $definition)) {
                    $summary['columns_added']++;
                }
            }

            foreach (self::indexMigrations() as $migration) {
                [$table, $indexName, $indexDefinition] = $migration;
                if (self::ensureIndex($pdo, $table, $indexName, $indexDefinition)) {
                    $summary['indexes_added']++;
                }
            }

            $roleResult = self::syncRolePermissions($pdo);
            $summary['roles_created'] = $roleResult['created'];
            $summary['roles_updated'] = $roleResult['updated'];
            $summary['defaults_created'] = self::seedDefaultData($pdo);

            $success = true;
        } catch (\Throwable $e) {
            $exception = $e;
            $errorMessage = $e->getMessage();
        }

        $finishedAt = gmdate('Y-m-d H:i:s');
        $durationMs = (int) round((microtime(true) - $startedTs) * 1000);
        $summary['success'] = $success ? 1 : 0;
        if (!$success) {
            $summary['error_message'] = $errorMessage;
        }

        try {
            self::ensureUpgradeLogTable($pdo);
            $result['log_id'] = self::insertUpgradeLog($pdo, [
                'release_version' => self::APP_VERSION,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'executed_by' => max(0, $actorUserId),
                'created_at' => $finishedAt,
            ]);
        } catch (\Throwable $logError) {
            $summary['log_error'] = $logError->getMessage();
        }

        $result['finished_at'] = $finishedAt;
        $result['duration_ms'] = $durationMs;
        $result['summary'] = $summary;

        if ($exception instanceof \Throwable) {
            throw $exception;
        }

        return $result;
    }

    private static function applySchema(PDO $pdo, string $schemaPath): int
    {
        if (!is_file($schemaPath)) {
            throw new RuntimeException('schema.sql not found');
        }

        $schemaSql = file_get_contents($schemaPath);
        if (!is_string($schemaSql) || trim($schemaSql) === '') {
            throw new RuntimeException('schema.sql is empty');
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', $schemaSql);
        if (!is_array($statements)) {
            $statements = [];
        }

        $count = 0;
        foreach ($statements as $statement) {
            $statement = trim((string) $statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private static function columnMigrations(): array
    {
        return [
            ['qiling_appointment_consumes', 'rolled_back_at', 'DATETIME NULL'],
            ['qiling_appointment_consumes', 'rollback_operator_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_appointment_consumes', 'rollback_note', 'VARCHAR(255) NOT NULL DEFAULT \'\''],
            ['qiling_appointment_consumes', 'rollback_before_sessions', 'INT NOT NULL DEFAULT 0'],
            ['qiling_appointment_consumes', 'rollback_after_sessions', 'INT NOT NULL DEFAULT 0'],
            ['qiling_users', 'login_failed_attempts', 'INT NOT NULL DEFAULT 0'],
            ['qiling_users', 'login_lock_until', 'DATETIME NULL'],
            ['qiling_users', 'last_login_at', 'DATETIME NULL'],
            ['qiling_users', 'last_login_ip', 'VARCHAR(64) NOT NULL DEFAULT \'\''],
            ['qiling_users', 'token_version', 'INT NOT NULL DEFAULT 1'],
            ['qiling_followup_tasks', 'notify_status', 'VARCHAR(20) NOT NULL DEFAULT \'pending\''],
            ['qiling_followup_tasks', 'notified_at', 'DATETIME NULL'],
            ['qiling_followup_tasks', 'notify_channel_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_followup_tasks', 'notify_error', 'VARCHAR(500) NOT NULL DEFAULT \'\''],
            ['qiling_services', 'supports_online_booking', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['qiling_report_daily_channel', 'paid_customers', 'INT NOT NULL DEFAULT 0'],
            ['qiling_password_reset_requests', 'channel', 'VARCHAR(20) NOT NULL DEFAULT \'email\''],
            ['qiling_password_reset_requests', 'receiver', 'VARCHAR(160) NOT NULL DEFAULT \'\''],
            ['qiling_password_reset_requests', 'code_hash', 'CHAR(64) NOT NULL DEFAULT \'\''],
            ['qiling_password_reset_requests', 'expire_at', 'DATETIME NULL'],
            ['qiling_password_reset_requests', 'used_at', 'DATETIME NULL'],
            ['qiling_password_reset_requests', 'fail_count', 'INT NOT NULL DEFAULT 0'],
            ['qiling_password_reset_requests', 'request_ip', 'VARCHAR(64) NOT NULL DEFAULT \'\''],
            ['qiling_password_reset_requests', 'requested_at', 'DATETIME NULL'],
            ['qiling_password_reset_requests', 'created_at', 'DATETIME NULL'],
            ['qiling_password_reset_requests', 'updated_at', 'DATETIME NULL'],
            ['qiling_crm_companies', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['qiling_crm_companies', 'archived_at', 'DATETIME NULL'],
            ['qiling_crm_companies', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_companies', 'deleted_at', 'DATETIME NULL'],
            ['qiling_crm_companies', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_companies', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_companies', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_companies', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\''],
            ['qiling_crm_contacts', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['qiling_crm_contacts', 'archived_at', 'DATETIME NULL'],
            ['qiling_crm_contacts', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_contacts', 'deleted_at', 'DATETIME NULL'],
            ['qiling_crm_contacts', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_contacts', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_contacts', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_contacts', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\''],
            ['qiling_crm_leads', 'visibility_scope', 'VARCHAR(20) NOT NULL DEFAULT \'private\''],
            ['qiling_crm_leads', 'public_pool_at', 'DATETIME NULL'],
            ['qiling_crm_leads', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['qiling_crm_leads', 'archived_at', 'DATETIME NULL'],
            ['qiling_crm_leads', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_leads', 'deleted_at', 'DATETIME NULL'],
            ['qiling_crm_leads', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_leads', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_leads', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_leads', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\''],
            ['qiling_crm_deals', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_deals', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_deals', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\''],
            ['qiling_crm_activities', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_activities', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL'],
            ['qiling_crm_activities', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\''],
            ['qiling_crm_assignment_rules', 'last_pick_index', 'INT NOT NULL DEFAULT -1'],
        ];
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private static function indexMigrations(): array
    {
        return [
            ['qiling_customers', 'idx_qiling_customers_created_at', 'INDEX idx_qiling_customers_created_at (created_at)'],
            ['qiling_customers', 'idx_qiling_customers_store_created_at', 'INDEX idx_qiling_customers_store_created_at (store_id, created_at)'],
            ['qiling_customers', 'idx_qiling_customers_store_source_channel', 'INDEX idx_qiling_customers_store_source_channel (store_id, source_channel)'],

            ['qiling_member_card_logs', 'idx_qiling_member_card_logs_created_at', 'INDEX idx_qiling_member_card_logs_created_at (created_at)'],
            ['qiling_member_card_logs', 'idx_qiling_member_card_logs_card_created_at', 'INDEX idx_qiling_member_card_logs_card_created_at (member_card_id, created_at)'],

            ['qiling_appointments', 'idx_qiling_appointments_store_start_status', 'INDEX idx_qiling_appointments_store_start_status (store_id, start_at, status)'],

            ['qiling_orders', 'idx_qiling_orders_status_paid_at_store', 'INDEX idx_qiling_orders_status_paid_at_store (status, paid_at, store_id)'],
            ['qiling_orders', 'idx_qiling_orders_store_paid_at_customer', 'INDEX idx_qiling_orders_store_paid_at_customer (store_id, paid_at, customer_id)'],

            ['qiling_order_items', 'idx_qiling_order_items_order_item', 'INDEX idx_qiling_order_items_order_item (order_id, item_type, item_ref_id)'],
            ['qiling_order_items', 'idx_qiling_order_items_staff_order', 'INDEX idx_qiling_order_items_staff_order (staff_id, order_id)'],

            ['qiling_order_payments', 'idx_qiling_order_payments_status_paid_at', 'INDEX idx_qiling_order_payments_status_paid_at (status, paid_at)'],
            ['qiling_order_payments', 'idx_qiling_order_payments_paid_at_order_id', 'INDEX idx_qiling_order_payments_paid_at_order_id (paid_at, order_id)'],
            ['qiling_order_payments', 'idx_qiling_order_payments_pay_method_status_paid_at', 'INDEX idx_qiling_order_payments_pay_method_status_paid_at (pay_method, status, paid_at)'],

            ['qiling_customer_portal_tokens', 'idx_qiling_customer_portal_tokens_customer_status_expire_id', 'INDEX idx_qiling_customer_portal_tokens_customer_status_expire_id (customer_id, status, expire_at, id)'],
            ['qiling_coupons', 'idx_qiling_coupons_customer_id_id', 'INDEX idx_qiling_coupons_customer_id_id (customer_id, id)'],
            ['qiling_customer_consume_records', 'idx_qiling_customer_consume_records_customer_id_id', 'INDEX idx_qiling_customer_consume_records_customer_id_id (customer_id, id)'],
            ['qiling_appointments', 'idx_qiling_appointments_store_status_id', 'INDEX idx_qiling_appointments_store_status_id (store_id, status, id)'],
            ['qiling_appointments', 'idx_qiling_appointments_status_id', 'INDEX idx_qiling_appointments_status_id (status, id)'],
            ['qiling_orders', 'idx_qiling_orders_store_status_id', 'INDEX idx_qiling_orders_store_status_id (store_id, status, id)'],
            ['qiling_orders', 'idx_qiling_orders_customer_id_id', 'INDEX idx_qiling_orders_customer_id_id (customer_id, id)'],
            ['qiling_online_payments', 'idx_qiling_online_payments_order_status_id', 'INDEX idx_qiling_online_payments_order_status_id (order_id, status, id)'],
            ['qiling_followup_tasks', 'idx_qiling_followup_tasks_store_status_due_id', 'INDEX idx_qiling_followup_tasks_store_status_due_id (store_id, status, due_at, id)'],
            ['qiling_followup_tasks', 'idx_qiling_followup_tasks_status_notify_due_id', 'INDEX idx_qiling_followup_tasks_status_notify_due_id (status, notify_status, due_at, id)'],
            ['qiling_followup_tasks', 'idx_qiling_followup_tasks_appointment_status', 'INDEX idx_qiling_followup_tasks_appointment_status (appointment_id, status)'],
            ['qiling_finance_daily_settlements', 'idx_qiling_finance_daily_settlements_store_status_date', 'INDEX idx_qiling_finance_daily_settlements_store_status_date (store_id, status, settlement_date)'],
            ['qiling_finance_daily_settlements', 'idx_qiling_finance_daily_settlements_closed_at', 'INDEX idx_qiling_finance_daily_settlements_closed_at (closed_at)'],
            ['qiling_finance_reconciliation_items', 'idx_qiling_finance_reconciliation_items_store_date', 'INDEX idx_qiling_finance_reconciliation_items_store_date (store_id, settlement_date)'],
            ['qiling_finance_reconciliation_items', 'idx_qiling_finance_reconciliation_items_store_diff', 'INDEX idx_qiling_finance_reconciliation_items_store_diff (store_id, diff_amount, settlement_date)'],
            ['qiling_finance_exceptions', 'idx_qiling_finance_exceptions_store_status_date', 'INDEX idx_qiling_finance_exceptions_store_status_date (store_id, status, settlement_date, id)'],
            ['qiling_finance_exceptions', 'idx_qiling_finance_exceptions_order_id', 'INDEX idx_qiling_finance_exceptions_order_id (order_id)'],
            ['qiling_finance_exceptions', 'idx_qiling_finance_exceptions_payment_no', 'INDEX idx_qiling_finance_exceptions_payment_no (payment_no)'],
            ['qiling_finance_exceptions', 'idx_qiling_finance_exceptions_refund_no', 'INDEX idx_qiling_finance_exceptions_refund_no (refund_no)'],
            ['qiling_inventory_materials', 'idx_qiling_inventory_materials_store_status_name', 'INDEX idx_qiling_inventory_materials_store_status_name (store_id, status, material_name)'],
            ['qiling_inventory_materials', 'idx_qiling_inventory_materials_store_low_stock', 'INDEX idx_qiling_inventory_materials_store_low_stock (store_id, status, current_stock, safety_stock)'],
            ['qiling_inventory_service_materials', 'idx_qiling_inventory_service_materials_service_enabled', 'INDEX idx_qiling_inventory_service_materials_service_enabled (service_id, enabled)'],
            ['qiling_inventory_service_materials', 'idx_qiling_inventory_service_materials_material_enabled', 'INDEX idx_qiling_inventory_service_materials_material_enabled (material_id, enabled)'],
            ['qiling_inventory_stock_movements', 'idx_qiling_inventory_stock_movements_material_created', 'INDEX idx_qiling_inventory_stock_movements_material_created (material_id, created_at, id)'],
            ['qiling_inventory_stock_movements', 'idx_qiling_inventory_stock_movements_store_type_created', 'INDEX idx_qiling_inventory_stock_movements_store_type_created (store_id, movement_type, created_at, id)'],
            ['qiling_inventory_stock_movements', 'idx_qiling_inventory_stock_movements_reference', 'INDEX idx_qiling_inventory_stock_movements_reference (reference_type, reference_id)'],
            ['qiling_inventory_stock_movements', 'idx_qiling_inventory_stock_movements_created_at', 'INDEX idx_qiling_inventory_stock_movements_created_at (created_at)'],
            ['qiling_inventory_purchase_orders', 'idx_qiling_inventory_purchase_orders_store_status_created', 'INDEX idx_qiling_inventory_purchase_orders_store_status_created (store_id, status, created_at)'],
            ['qiling_inventory_purchase_orders', 'idx_qiling_inventory_purchase_orders_expected_at', 'INDEX idx_qiling_inventory_purchase_orders_expected_at (expected_at)'],
            ['qiling_inventory_purchase_items', 'idx_qiling_inventory_purchase_items_purchase_id', 'INDEX idx_qiling_inventory_purchase_items_purchase_id (purchase_id)'],
            ['qiling_inventory_purchase_items', 'idx_qiling_inventory_purchase_items_material_id', 'INDEX idx_qiling_inventory_purchase_items_material_id (material_id)'],

            ['qiling_report_daily_store', 'idx_qiling_report_daily_store_store_date', 'INDEX idx_qiling_report_daily_store_store_date (store_id, report_date)'],
            ['qiling_report_daily_store', 'idx_qiling_report_daily_store_aggregated_at', 'INDEX idx_qiling_report_daily_store_aggregated_at (aggregated_at)'],
            ['qiling_report_daily_channel', 'idx_qiling_report_daily_channel_store_date', 'INDEX idx_qiling_report_daily_channel_store_date (store_id, report_date)'],
            ['qiling_report_daily_channel', 'idx_qiling_report_daily_channel_channel', 'INDEX idx_qiling_report_daily_channel_channel (source_channel)'],
            ['qiling_report_daily_service', 'idx_qiling_report_daily_service_store_date', 'INDEX idx_qiling_report_daily_service_store_date (store_id, report_date)'],
            ['qiling_report_daily_service', 'idx_qiling_report_daily_service_sales', 'INDEX idx_qiling_report_daily_service_sales (sales_amount)'],
            ['qiling_report_aggregate_marks', 'idx_qiling_report_aggregate_marks_aggregated_at', 'INDEX idx_qiling_report_aggregate_marks_aggregated_at (aggregated_at)'],

            ['qiling_crm_pipelines', 'idx_qiling_crm_pipelines_status', 'INDEX idx_qiling_crm_pipelines_status (status)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_owner_status', 'INDEX idx_qiling_crm_companies_owner_status (owner_user_id, status, id)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_status_id', 'INDEX idx_qiling_crm_companies_status_id (status, id)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_country', 'INDEX idx_qiling_crm_companies_country (country_code)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_type', 'INDEX idx_qiling_crm_companies_type (company_type)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_created_at', 'INDEX idx_qiling_crm_companies_created_at (created_at)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_company_name', 'INDEX idx_qiling_crm_companies_company_name (company_name)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_created_by_id', 'INDEX idx_qiling_crm_companies_created_by_id (created_by, id)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_company_deleted_id', 'INDEX idx_qiling_crm_companies_company_deleted_id (company_name, deleted_at, id)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_owner_deleted_archived_id', 'INDEX idx_qiling_crm_companies_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_visibility_owner_team', 'INDEX idx_qiling_crm_companies_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_visibility_owner_dept', 'INDEX idx_qiling_crm_companies_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)'],
            ['qiling_crm_companies', 'ft_qiling_crm_companies_search', 'FULLTEXT INDEX ft_qiling_crm_companies_search (company_name, website, industry)'],
            ['qiling_crm_companies', 'idx_qiling_crm_companies_deleted_archived', 'INDEX idx_qiling_crm_companies_deleted_archived (deleted_at, is_archived, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_owner_status', 'INDEX idx_qiling_crm_contacts_owner_status (owner_user_id, status, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_status_id', 'INDEX idx_qiling_crm_contacts_status_id (status, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_company', 'INDEX idx_qiling_crm_contacts_company (company_id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_mobile', 'INDEX idx_qiling_crm_contacts_mobile (mobile)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_email', 'INDEX idx_qiling_crm_contacts_email (email)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_created_at', 'INDEX idx_qiling_crm_contacts_created_at (created_at)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_whatsapp', 'INDEX idx_qiling_crm_contacts_whatsapp (whatsapp)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_created_by_id', 'INDEX idx_qiling_crm_contacts_created_by_id (created_by, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_mobile_deleted_id', 'INDEX idx_qiling_crm_contacts_mobile_deleted_id (mobile, deleted_at, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_email_deleted_id', 'INDEX idx_qiling_crm_contacts_email_deleted_id (email, deleted_at, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_whatsapp_deleted_id', 'INDEX idx_qiling_crm_contacts_whatsapp_deleted_id (whatsapp, deleted_at, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_owner_deleted_archived_id', 'INDEX idx_qiling_crm_contacts_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_company_deleted_archived_id', 'INDEX idx_qiling_crm_contacts_company_deleted_archived_id (company_id, deleted_at, is_archived, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_visibility_owner_team', 'INDEX idx_qiling_crm_contacts_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_visibility_owner_dept', 'INDEX idx_qiling_crm_contacts_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)'],
            ['qiling_crm_contacts', 'ft_qiling_crm_contacts_search', 'FULLTEXT INDEX ft_qiling_crm_contacts_search (contact_name, mobile, email, whatsapp)'],
            ['qiling_crm_contacts', 'idx_qiling_crm_contacts_deleted_archived', 'INDEX idx_qiling_crm_contacts_deleted_archived (deleted_at, is_archived, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_owner_status', 'INDEX idx_qiling_crm_leads_owner_status (owner_user_id, status, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_status_id', 'INDEX idx_qiling_crm_leads_status_id (status, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_owner_intent_id', 'INDEX idx_qiling_crm_leads_owner_intent_id (owner_user_id, intent_level, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_next_followup', 'INDEX idx_qiling_crm_leads_next_followup (next_followup_at, status)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_mobile', 'INDEX idx_qiling_crm_leads_mobile (mobile)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_email', 'INDEX idx_qiling_crm_leads_email (email)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_company', 'INDEX idx_qiling_crm_leads_company (related_company_id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_contact', 'INDEX idx_qiling_crm_leads_contact (related_contact_id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_visibility_owner', 'INDEX idx_qiling_crm_leads_visibility_owner (visibility_scope, owner_user_id, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_public_pool', 'INDEX idx_qiling_crm_leads_public_pool (visibility_scope, public_pool_at, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_created_by_id', 'INDEX idx_qiling_crm_leads_created_by_id (created_by, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_company_name_deleted_id', 'INDEX idx_qiling_crm_leads_company_name_deleted_id (company_name, deleted_at, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_mobile_deleted_id', 'INDEX idx_qiling_crm_leads_mobile_deleted_id (mobile, deleted_at, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_email_deleted_id', 'INDEX idx_qiling_crm_leads_email_deleted_id (email, deleted_at, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_owner_deleted_archived_id', 'INDEX idx_qiling_crm_leads_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_created_deleted_archived_id', 'INDEX idx_qiling_crm_leads_created_deleted_archived_id (created_by, deleted_at, is_archived, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_visibility_deleted_archived_pool', 'INDEX idx_qiling_crm_leads_visibility_deleted_archived_pool (visibility_scope, deleted_at, is_archived, public_pool_at, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_visibility_owner_team', 'INDEX idx_qiling_crm_leads_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_visibility_owner_dept', 'INDEX idx_qiling_crm_leads_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)'],
            ['qiling_crm_leads', 'ft_qiling_crm_leads_search', 'FULLTEXT INDEX ft_qiling_crm_leads_search (lead_name, mobile, email, company_name)'],
            ['qiling_crm_leads', 'idx_qiling_crm_leads_deleted_archived', 'INDEX idx_qiling_crm_leads_deleted_archived (deleted_at, is_archived, id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_owner_status', 'INDEX idx_qiling_crm_deals_owner_status (owner_user_id, deal_status, id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_status_id', 'INDEX idx_qiling_crm_deals_status_id (deal_status, id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_pipeline_stage', 'INDEX idx_qiling_crm_deals_pipeline_stage (pipeline_key, stage_key)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_pipeline_stage_id', 'INDEX idx_qiling_crm_deals_pipeline_stage_id (pipeline_key, stage_key, id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_expected_close', 'INDEX idx_qiling_crm_deals_expected_close (expected_close_date)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_owner_id', 'INDEX idx_qiling_crm_deals_owner_id (owner_user_id, id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_company', 'INDEX idx_qiling_crm_deals_company (company_id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_contact', 'INDEX idx_qiling_crm_deals_contact (contact_id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_lead', 'INDEX idx_qiling_crm_deals_lead (lead_id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_created_by_id', 'INDEX idx_qiling_crm_deals_created_by_id (created_by, id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_visibility_owner_team', 'INDEX idx_qiling_crm_deals_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)'],
            ['qiling_crm_deals', 'idx_qiling_crm_deals_visibility_owner_dept', 'INDEX idx_qiling_crm_deals_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)'],
            ['qiling_crm_deals', 'ft_qiling_crm_deals_name', 'FULLTEXT INDEX ft_qiling_crm_deals_name (deal_name)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_owner_status_due', 'INDEX idx_qiling_crm_activities_owner_status_due (owner_user_id, status, due_at, id)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_status_due_id', 'INDEX idx_qiling_crm_activities_status_due_id (status, due_at, id)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_entity', 'INDEX idx_qiling_crm_activities_entity (entity_type, entity_id, id)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_owner_id', 'INDEX idx_qiling_crm_activities_owner_id (owner_user_id, id)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_created_by_id', 'INDEX idx_qiling_crm_activities_created_by_id (created_by, id)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_due_status', 'INDEX idx_qiling_crm_activities_due_status (due_at, status)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_visibility_owner_team', 'INDEX idx_qiling_crm_activities_visibility_owner_team (visibility_level, owner_team_id, owner_user_id, id)'],
            ['qiling_crm_activities', 'idx_qiling_crm_activities_visibility_owner_dept', 'INDEX idx_qiling_crm_activities_visibility_owner_dept (visibility_level, owner_department_id, owner_user_id, id)'],
            ['qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_entity', 'INDEX idx_qiling_crm_transfer_logs_entity (entity_type, entity_id, id)'],
            ['qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_entity_type_id', 'INDEX idx_qiling_crm_transfer_logs_entity_type_id (entity_type, id)'],
            ['qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_to_owner', 'INDEX idx_qiling_crm_transfer_logs_to_owner (to_owner_user_id, id)'],
            ['qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_from_owner', 'INDEX idx_qiling_crm_transfer_logs_from_owner (from_owner_user_id, id)'],
            ['qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_created_by', 'INDEX idx_qiling_crm_transfer_logs_created_by (created_by, id)'],
            ['qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_created_at', 'INDEX idx_qiling_crm_transfer_logs_created_at (created_at)'],
            ['qiling_crm_assignment_rules', 'idx_qiling_crm_assignment_rules_entity_enabled', 'INDEX idx_qiling_crm_assignment_rules_entity_enabled (entity_type, enabled, id)'],
            ['qiling_crm_assignment_rules', 'idx_qiling_crm_assignment_rules_source_scope', 'INDEX idx_qiling_crm_assignment_rules_source_scope (source_scope, enabled, id)'],
            ['qiling_customer_crm_links', 'uq_qiling_customer_crm_links_customer_contact', 'UNIQUE INDEX uq_qiling_customer_crm_links_customer_contact (customer_id, crm_contact_id)'],
            ['qiling_customer_crm_links', 'uq_qiling_customer_crm_links_customer_company', 'UNIQUE INDEX uq_qiling_customer_crm_links_customer_company (customer_id, crm_company_id)'],
            ['qiling_customer_crm_links', 'idx_qiling_customer_crm_links_customer_status_id', 'INDEX idx_qiling_customer_crm_links_customer_status_id (customer_id, status, id)'],
            ['qiling_customer_crm_links', 'idx_qiling_customer_crm_links_contact_status_id', 'INDEX idx_qiling_customer_crm_links_contact_status_id (crm_contact_id, status, id)'],
            ['qiling_customer_crm_links', 'idx_qiling_customer_crm_links_company_status_id', 'INDEX idx_qiling_customer_crm_links_company_status_id (crm_company_id, status, id)'],
            ['qiling_customer_crm_links', 'idx_qiling_customer_crm_links_updated_at', 'INDEX idx_qiling_customer_crm_links_updated_at (updated_at)'],
            ['qiling_customer_crm_links', 'idx_qiling_customer_crm_links_created_by_id', 'INDEX idx_qiling_customer_crm_links_created_by_id (created_by, id)'],
            ['qiling_crm_products', 'idx_qiling_crm_products_status_id', 'INDEX idx_qiling_crm_products_status_id (status, id)'],
            ['qiling_crm_products', 'idx_qiling_crm_products_category_id', 'INDEX idx_qiling_crm_products_category_id (category, id)'],
            ['qiling_crm_products', 'idx_qiling_crm_products_created_by_id', 'INDEX idx_qiling_crm_products_created_by_id (created_by, id)'],
            ['qiling_crm_products', 'ft_qiling_crm_products_name', 'FULLTEXT INDEX ft_qiling_crm_products_name (product_name)'],
            ['qiling_crm_quotes', 'idx_qiling_crm_quotes_deal_status_id', 'INDEX idx_qiling_crm_quotes_deal_status_id (deal_id, status, id)'],
            ['qiling_crm_quotes', 'idx_qiling_crm_quotes_owner_status_id', 'INDEX idx_qiling_crm_quotes_owner_status_id (owner_user_id, status, id)'],
            ['qiling_crm_quotes', 'idx_qiling_crm_quotes_company_id', 'INDEX idx_qiling_crm_quotes_company_id (company_id)'],
            ['qiling_crm_quotes', 'idx_qiling_crm_quotes_contact_id', 'INDEX idx_qiling_crm_quotes_contact_id (contact_id)'],
            ['qiling_crm_quotes', 'idx_qiling_crm_quotes_valid_until', 'INDEX idx_qiling_crm_quotes_valid_until (valid_until)'],
            ['qiling_crm_quote_items', 'idx_qiling_crm_quote_items_quote_id', 'INDEX idx_qiling_crm_quote_items_quote_id (quote_id)'],
            ['qiling_crm_quote_items', 'idx_qiling_crm_quote_items_product_id', 'INDEX idx_qiling_crm_quote_items_product_id (product_id)'],
            ['qiling_crm_contracts', 'idx_qiling_crm_contracts_deal_status_id', 'INDEX idx_qiling_crm_contracts_deal_status_id (deal_id, status, id)'],
            ['qiling_crm_contracts', 'idx_qiling_crm_contracts_owner_status_id', 'INDEX idx_qiling_crm_contracts_owner_status_id (owner_user_id, status, id)'],
            ['qiling_crm_contracts', 'idx_qiling_crm_contracts_quote_id', 'INDEX idx_qiling_crm_contracts_quote_id (quote_id)'],
            ['qiling_crm_contracts', 'idx_qiling_crm_contracts_signed_at', 'INDEX idx_qiling_crm_contracts_signed_at (signed_at)'],
            ['qiling_crm_contract_items', 'idx_qiling_crm_contract_items_contract_id', 'INDEX idx_qiling_crm_contract_items_contract_id (contract_id)'],
            ['qiling_crm_contract_items', 'idx_qiling_crm_contract_items_product_id', 'INDEX idx_qiling_crm_contract_items_product_id (product_id)'],
            ['qiling_crm_payment_plans', 'idx_qiling_crm_payment_plans_contract_status_due', 'INDEX idx_qiling_crm_payment_plans_contract_status_due (contract_id, status, due_date, id)'],
            ['qiling_crm_payment_plans', 'idx_qiling_crm_payment_plans_deal_status_due', 'INDEX idx_qiling_crm_payment_plans_deal_status_due (deal_id, status, due_date, id)'],
            ['qiling_crm_payment_plans', 'idx_qiling_crm_payment_plans_status_due', 'INDEX idx_qiling_crm_payment_plans_status_due (status, due_date)'],
            ['qiling_crm_invoices', 'idx_qiling_crm_invoices_contract_status_id', 'INDEX idx_qiling_crm_invoices_contract_status_id (contract_id, status, id)'],
            ['qiling_crm_invoices', 'idx_qiling_crm_invoices_deal_status_id', 'INDEX idx_qiling_crm_invoices_deal_status_id (deal_id, status, id)'],
            ['qiling_crm_invoices', 'idx_qiling_crm_invoices_issue_date', 'INDEX idx_qiling_crm_invoices_issue_date (issue_date)'],
            ['qiling_crm_invoices', 'idx_qiling_crm_invoices_due_date', 'INDEX idx_qiling_crm_invoices_due_date (due_date)'],
            ['qiling_crm_automation_rules', 'idx_qiling_crm_automation_rules_entity_trigger_enabled', 'INDEX idx_qiling_crm_automation_rules_entity_trigger_enabled (entity_type, trigger_field, enabled, sort_order, id)'],
            ['qiling_crm_automation_rules', 'idx_qiling_crm_automation_rules_action_enabled', 'INDEX idx_qiling_crm_automation_rules_action_enabled (action_type, enabled, id)'],
            ['qiling_crm_automation_rules', 'uq_qiling_crm_automation_rules_unique', 'UNIQUE INDEX uq_qiling_crm_automation_rules_unique (entity_type, trigger_field, trigger_from, trigger_to, action_type, sort_order, rule_name)'],
            ['qiling_crm_automation_logs', 'idx_qiling_crm_automation_logs_rule_time', 'INDEX idx_qiling_crm_automation_logs_rule_time (rule_id, executed_at, id)'],
            ['qiling_crm_automation_logs', 'idx_qiling_crm_automation_logs_entity_time', 'INDEX idx_qiling_crm_automation_logs_entity_time (entity_type, entity_id, executed_at, id)'],
            ['qiling_crm_automation_logs', 'idx_qiling_crm_automation_logs_status_time', 'INDEX idx_qiling_crm_automation_logs_status_time (status, executed_at, id)'],
            ['qiling_crm_deal_stage_logs', 'idx_qiling_crm_deal_stage_logs_deal_time', 'INDEX idx_qiling_crm_deal_stage_logs_deal_time (deal_id, changed_at, id)'],
            ['qiling_crm_deal_stage_logs', 'idx_qiling_crm_deal_stage_logs_pipeline_stage_time', 'INDEX idx_qiling_crm_deal_stage_logs_pipeline_stage_time (pipeline_key, to_stage_key, changed_at, id)'],
            ['qiling_crm_deal_stage_logs', 'idx_qiling_crm_deal_stage_logs_from_stage_time', 'INDEX idx_qiling_crm_deal_stage_logs_from_stage_time (from_stage_key, changed_at, id)'],
            ['qiling_password_reset_requests', 'idx_qiling_pwd_reset_user', 'INDEX idx_qiling_pwd_reset_user (user_id, requested_at)'],
            ['qiling_password_reset_requests', 'idx_qiling_pwd_reset_lookup', 'INDEX idx_qiling_pwd_reset_lookup (user_id, channel, receiver, used_at, id)'],
            ['qiling_password_reset_requests', 'idx_qiling_pwd_reset_ip', 'INDEX idx_qiling_pwd_reset_ip (request_ip, requested_at)'],
            ['qiling_password_reset_requests', 'idx_qiling_pwd_reset_expire_used', 'INDEX idx_qiling_pwd_reset_expire_used (expire_at, used_at)'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function syncRolePermissions(PDO $pdo): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $result = ['created' => 0, 'updated' => 0];

        foreach (self::roleTemplates() as $roleKey => $roleConfig) {
            $stmt = $pdo->prepare(
                'SELECT id, permissions_json, is_system
                 FROM qiling_roles
                 WHERE role_key = :role_key
                 LIMIT 1'
            );
            $stmt->execute(['role_key' => $roleKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $requiredPermissions = $roleConfig['permissions'];
            if (!is_array($row)) {
                $insert = $pdo->prepare(
                    'INSERT INTO qiling_roles
                     (role_key, role_name, permissions_json, is_system, status, created_at, updated_at)
                     VALUES
                     (:role_key, :role_name, :permissions_json, 1, :status, :created_at, :updated_at)'
                );
                $insert->execute([
                    'role_key' => $roleKey,
                    'role_name' => $roleConfig['name'],
                    'permissions_json' => json_encode($requiredPermissions, JSON_UNESCAPED_UNICODE),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $result['created']++;
                continue;
            }

            $existingPermissions = self::decodePermissions($row['permissions_json'] ?? null);
            $mergedPermissions = self::mergePermissions($existingPermissions, $requiredPermissions);

            $isSystem = (int) ($row['is_system'] ?? 0) === 1;
            $needUpdatePermissions = $mergedPermissions !== $existingPermissions;

            if (!$isSystem || $needUpdatePermissions) {
                $update = $pdo->prepare(
                    'UPDATE qiling_roles
                     SET permissions_json = :permissions_json,
                         is_system = :is_system,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'permissions_json' => json_encode($mergedPermissions, JSON_UNESCAPED_UNICODE),
                    'is_system' => 1,
                    'updated_at' => $now,
                    'id' => (int) ($row['id'] ?? 0),
                ]);
                $result['updated']++;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{name:string,permissions:array<int,string>}>
     */
    private static function roleTemplates(): array
    {
        $crmPermissionsView = [
            'crm',
            'crm.dashboard.view',
            'crm.analytics.view',
            'crm.pipelines.view',
            'crm.companies.view',
            'crm.contacts.view',
            'crm.leads.view',
            'crm.deals.view',
            'crm.activities.view',
            'crm.trade.view',
            'crm.automation.view',
            'crm.bridge.view',
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
            'crm.trade.edit',
            'crm.automation.edit',
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
            'crm.automation.manage',
            'crm.bridge.edit',
        ];

        return [
            'admin' => [
                'name' => '系统管理员',
                'permissions' => array_merge(
                    ['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users', 'system'],
                    $crmPermissionsView,
                    $crmPermissionsEdit,
                    $crmPermissionsManage
                ),
            ],
            'manager' => [
                'name' => '门店经理',
                'permissions' => array_merge(
                    ['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users'],
                    $crmPermissionsView,
                    $crmPermissionsEdit,
                    $crmPermissionsManage
                ),
            ],
            'consultant' => [
                'name' => '顾问',
                'permissions' => array_merge(
                    ['dashboard', 'customers', 'member_cards', 'orders', 'appointments', 'followup', 'reports', 'points', 'prints'],
                    $crmPermissionsView,
                    $crmPermissionsEdit
                ),
            ],
            'therapist' => [
                'name' => '护理师',
                'permissions' => array_merge(
                    ['dashboard', 'customers', 'appointments', 'followup', 'performance'],
                    $crmPermissionsView,
                    $crmPermissionsEdit
                ),
            ],
            'reception' => [
                'name' => '前台',
                'permissions' => array_merge(
                    ['dashboard', 'customers', 'orders', 'appointments', 'followup'],
                    $crmPermissionsView,
                    $crmPermissionsEdit
                ),
            ],
        ];
    }

    private static function seedDefaultData(PDO $pdo): int
    {
        $created = 0;
        $now = gmdate('Y-m-d H:i:s');

        if (self::ensureDefaultStore($pdo, $now)) {
            $created++;
        }
        if (self::ensureDefaultFollowupPlan($pdo, $now)) {
            $created++;
        }
        if (self::ensureDefaultCrmPipeline($pdo, $now)) {
            $created++;
        }
        if (self::ensureDefaultCrmDedupeRules($pdo, $now) > 0) {
            $created++;
        }
        if (self::ensureDefaultCrmReminderRules($pdo, $now) > 0) {
            $created++;
        }
        if (self::ensureDefaultCrmAutomationRules($pdo, $now) > 0) {
            $created++;
        }
        $created += self::ensureSystemSettingDefaults($pdo, $now);

        return $created;
    }

    private static function ensureDefaultStore(PDO $pdo, string $now): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM qiling_stores WHERE store_code = :store_code LIMIT 1');
        $stmt->execute(['store_code' => 'QLS00001']);
        $storeId = (int) $stmt->fetchColumn();
        if ($storeId > 0) {
            return false;
        }

        $insert = $pdo->prepare(
            'INSERT INTO qiling_stores
             (store_code, store_name, contact_name, contact_phone, address, open_time, close_time, status, created_at, updated_at)
             VALUES
             (:store_code, :store_name, :contact_name, :contact_phone, :address, :open_time, :close_time, :status, :created_at, :updated_at)'
        );
        $insert->execute([
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

        return true;
    }

    private static function ensureDefaultFollowupPlan(PDO $pdo, string $now): bool
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_followup_plans
             (store_id, trigger_type, plan_name, schedule_days_json, enabled, created_at, updated_at)
             VALUES
             (:store_id, :trigger_type, :plan_name, :schedule_days_json, :enabled, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([
            'store_id' => 0,
            'trigger_type' => 'appointment_completed',
            'plan_name' => '默认回访计划',
            'schedule_days_json' => json_encode([1, 3, 7], JSON_UNESCAPED_UNICODE),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    private static function ensureDefaultCrmPipeline(PDO $pdo, string $now): bool
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_pipelines
             (pipeline_key, pipeline_name, stages_json, is_system, status, created_at, updated_at)
             VALUES
             (:pipeline_key, :pipeline_name, :stages_json, 1, :status, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([
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

        return $stmt->rowCount() > 0;
    }

    private static function ensureDefaultCrmDedupeRules(PDO $pdo, string $now): int
    {
        $defaults = [
            ['lead', 1, 1, 1],
            ['contact', 1, 1, 1],
            ['company', 0, 0, 1],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_dedupe_rules
             (entity_type, match_mobile, match_email, match_company, enabled, updated_by, created_at, updated_at)
             VALUES
             (:entity_type, :match_mobile, :match_email, :match_company, 1, 0, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $created = 0;
        foreach ($defaults as $item) {
            [$entityType, $matchMobile, $matchEmail, $matchCompany] = $item;
            $stmt->execute([
                'entity_type' => $entityType,
                'match_mobile' => $matchMobile,
                'match_email' => $matchEmail,
                'match_company' => $matchCompany,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($stmt->rowCount() > 0) {
                $created++;
            }
        }
        return $created;
    }

    private static function ensureDefaultCrmReminderRules(PDO $pdo, string $now): int
    {
        $defaults = [
            ['activity_schedule_1440', '任务前 24 小时提醒', 'schedule', 1440],
            ['activity_due_0', '任务到期提醒', 'due', 0],
            ['activity_overdue_60', '任务逾期 60 分钟提醒', 'overdue', 60],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_reminder_rules
             (rule_code, rule_name, remind_type, offset_minutes, enabled, created_by, created_at, updated_at)
             VALUES
             (:rule_code, :rule_name, :remind_type, :offset_minutes, 1, 0, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $created = 0;
        foreach ($defaults as $item) {
            [$ruleCode, $ruleName, $remindType, $offsetMinutes] = $item;
            $stmt->execute([
                'rule_code' => $ruleCode,
                'rule_name' => $ruleName,
                'remind_type' => $remindType,
                'offset_minutes' => $offsetMinutes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($stmt->rowCount() > 0) {
                $created++;
            }
        }
        return $created;
    }

    private static function ensureDefaultCrmAutomationRules(PDO $pdo, string $now): int
    {
        $defaults = [
            [
                'rule_name' => '商机赢单自动提醒',
                'entity_type' => 'deal',
                'trigger_field' => 'deal_status',
                'trigger_from' => 'open',
                'trigger_to' => 'won',
                'action_type' => 'create_reminder',
                'action_config_json' => json_encode([
                    'title' => '商机赢单提醒',
                    'content' => '商机状态已变更为赢单，请跟进合同与回款计划。',
                    'due_in_minutes' => 0,
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 20,
            ],
            [
                'rule_name' => '线索已确认自动建任务',
                'entity_type' => 'lead',
                'trigger_field' => 'status',
                'trigger_from' => '',
                'trigger_to' => 'qualified',
                'action_type' => 'create_task',
                'action_config_json' => json_encode([
                    'subject' => '线索已确认，安排商机推进',
                    'content' => '线索进入已确认阶段，建议 24 小时内完成方案沟通。',
                    'due_in_minutes' => 1440,
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 30,
            ],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_automation_rules
             (rule_name, entity_type, trigger_field, trigger_from, trigger_to, action_type, action_config_json, sort_order, enabled, created_by, created_at, updated_at)
             VALUES
             (:rule_name, :entity_type, :trigger_field, :trigger_from, :trigger_to, :action_type, :action_config_json, :sort_order, 1, 0, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE id = id'
        );

        $created = 0;
        foreach ($defaults as $item) {
            $stmt->execute([
                'rule_name' => $item['rule_name'],
                'entity_type' => $item['entity_type'],
                'trigger_field' => $item['trigger_field'],
                'trigger_from' => $item['trigger_from'],
                'trigger_to' => $item['trigger_to'],
                'action_type' => $item['action_type'],
                'action_config_json' => $item['action_config_json'],
                'sort_order' => $item['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($stmt->rowCount() > 0) {
                $created++;
            }
        }

        return $created;
    }

    private static function ensureSystemSettingDefaults(PDO $pdo, string $now): int
    {
        SystemSettingService::all($pdo);

        $defaults = [
            'admin_entry_path' => 'admin',
            'front_site_enabled' => '1',
            'front_maintenance_message' => '系统维护中，请稍后访问。',
            'front_allow_ips' => '',
            'security_headers_enabled' => '1',
            'frontend_asset_version_seed' => '',
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_system_settings
             (setting_key, setting_value, updated_by, created_at, updated_at)
             VALUES
             (:setting_key, :setting_value, :updated_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE setting_key = setting_key'
        );

        $count = 0;
        foreach ($defaults as $settingKey => $settingValue) {
            $stmt->execute([
                'setting_key' => $settingKey,
                'setting_value' => $settingValue,
                'updated_by' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($stmt->rowCount() > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private static function detectPendingMigrations(PDO $pdo): array
    {
        $missingTables = [];
        foreach (self::requiredTables() as $table) {
            if (!self::tableExists($pdo, $table)) {
                $missingTables[] = $table;
            }
        }

        $missingColumns = [];
        foreach (self::columnMigrations() as $migration) {
            [$table, $column] = $migration;
            if (self::hasColumn($pdo, $table, $column)) {
                continue;
            }
            $missingColumns[] = $table . '.' . $column;
        }

        $missingIndexes = [];
        foreach (self::indexMigrations() as $migration) {
            [$table, $indexName] = $migration;
            if (self::hasIndex($pdo, $table, $indexName)) {
                continue;
            }
            $missingIndexes[] = $table . '.' . $indexName;
        }

        return [
            'missing_tables_count' => count($missingTables),
            'missing_columns_count' => count($missingColumns),
            'missing_indexes_count' => count($missingIndexes),
            'missing_tables_preview' => array_slice($missingTables, 0, 20),
            'missing_columns_preview' => array_slice($missingColumns, 0, 20),
            'missing_indexes_preview' => array_slice($missingIndexes, 0, 20),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function requiredTables(): array
    {
        return [
            'qiling_finance_daily_settlements',
            'qiling_finance_reconciliation_items',
            'qiling_finance_exceptions',
            'qiling_inventory_materials',
            'qiling_inventory_service_materials',
            'qiling_inventory_stock_movements',
            'qiling_inventory_purchase_orders',
            'qiling_inventory_purchase_items',
            'qiling_password_reset_requests',
            'qiling_crm_transfer_logs',
            'qiling_crm_assignment_rules',
            'qiling_crm_departments',
            'qiling_crm_teams',
            'qiling_crm_team_members',
            'qiling_crm_custom_fields',
            'qiling_crm_form_configs',
            'qiling_crm_dedupe_rules',
            'qiling_crm_reminder_rules',
            'qiling_crm_notifications',
            'qiling_customer_crm_links',
            'qiling_crm_products',
            'qiling_crm_quotes',
            'qiling_crm_quote_items',
            'qiling_crm_contracts',
            'qiling_crm_contract_items',
            'qiling_crm_payment_plans',
            'qiling_crm_invoices',
            'qiling_crm_automation_rules',
            'qiling_crm_automation_logs',
            'qiling_crm_deal_stage_logs',
        ];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $check = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $check->execute([
            'table_name' => $table,
        ]);

        return (int) $check->fetchColumn() > 0;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $check = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $check->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $check->fetchColumn() > 0;
    }

    private static function hasIndex(PDO $pdo, string $table, string $indexName): bool
    {
        $check = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name'
        );
        $check->execute([
            'table_name' => $table,
            'index_name' => $indexName,
        ]);

        return (int) $check->fetchColumn() > 0;
    }

    private static function isDuplicateSchemaMutationError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'duplicate column name')
            || str_contains($message, 'duplicate key name')
            || str_contains($message, 'already exists')
        ) {
            return true;
        }

        if (!$e instanceof \PDOException) {
            return false;
        }

        $errorInfo = $e->errorInfo;
        if (!is_array($errorInfo)) {
            return false;
        }

        $driverError = (int) ($errorInfo[1] ?? 0);
        if (in_array($driverError, [1060, 1061], true)) {
            return true;
        }

        return false;
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): bool
    {
        if (self::hasColumn($pdo, $table, $column)) {
            return false;
        }

        try {
            $pdo->exec('ALTER TABLE `' . self::quoteIdentifier($table) . '` ADD COLUMN `' . self::quoteIdentifier($column) . '` ' . $definition);
        } catch (\Throwable $e) {
            if (self::isDuplicateSchemaMutationError($e) && self::hasColumn($pdo, $table, $column)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    private static function ensureIndex(PDO $pdo, string $table, string $indexName, string $indexDefinition): bool
    {
        if (self::hasIndex($pdo, $table, $indexName)) {
            return false;
        }

        try {
            $pdo->exec('ALTER TABLE `' . self::quoteIdentifier($table) . '` ADD ' . $indexDefinition);
        } catch (\Throwable $e) {
            if (self::isDuplicateSchemaMutationError($e) && self::hasIndex($pdo, $table, $indexName)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    private static function ensureUpgradeLogTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS qiling_system_upgrade_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                release_version VARCHAR(64) NOT NULL DEFAULT \'\',
                summary_json LONGTEXT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NOT NULL,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                executed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_qiling_system_upgrade_logs_finished_at (finished_at),
                KEY idx_qiling_system_upgrade_logs_release (release_version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function insertUpgradeLog(PDO $pdo, array $payload): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_system_upgrade_logs
             (release_version, summary_json, started_at, finished_at, duration_ms, executed_by, created_at)
             VALUES
             (:release_version, :summary_json, :started_at, :finished_at, :duration_ms, :executed_by, :created_at)'
        );
        $stmt->execute([
            'release_version' => (string) ($payload['release_version'] ?? ''),
            'summary_json' => $payload['summary_json'],
            'started_at' => (string) ($payload['started_at'] ?? ''),
            'finished_at' => (string) ($payload['finished_at'] ?? ''),
            'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
            'executed_by' => (int) ($payload['executed_by'] ?? 0),
            'created_at' => (string) ($payload['created_at'] ?? ''),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function latestUpgradeLog(PDO $pdo): ?array
    {
        $stmt = $pdo->query(
            'SELECT l.id,
                    l.release_version,
                    l.started_at,
                    l.finished_at,
                    l.duration_ms,
                    l.executed_by,
                    l.summary_json,
                    l.created_at,
                    COALESCE(u.username, \'\') AS executed_by_username
             FROM qiling_system_upgrade_logs l
             LEFT JOIN qiling_users u ON u.id = l.executed_by
             ORDER BY l.id DESC
             LIMIT 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $summary = json_decode((string) ($row['summary_json'] ?? ''), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'release_version' => (string) ($row['release_version'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'duration_ms' => (int) ($row['duration_ms'] ?? 0),
            'executed_by' => (int) ($row['executed_by'] ?? 0),
            'executed_by_username' => (string) ($row['executed_by_username'] ?? ''),
            'summary' => is_array($summary) ? $summary : new \stdClass(),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function decodePermissions(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $permission = trim($item);
            if ($permission === '') {
                continue;
            }
            $out[] = $permission;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<int, string> $existing
     * @param array<int, string> $required
     * @return array<int, string>
     */
    private static function mergePermissions(array $existing, array $required): array
    {
        $result = [];
        foreach ($existing as $item) {
            $permission = trim((string) $item);
            if ($permission === '' || isset($result[$permission])) {
                continue;
            }
            $result[$permission] = true;
        }
        foreach ($required as $item) {
            $permission = trim((string) $item);
            if ($permission === '' || isset($result[$permission])) {
                continue;
            }
            $result[$permission] = true;
        }
        return array_values(array_keys($result));
    }

    private static function quoteIdentifier(string $name): string
    {
        return str_replace('`', '``', $name);
    }
}
