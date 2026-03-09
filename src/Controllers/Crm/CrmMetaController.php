<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmMetaController
{
    public static function customFields(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.custom_fields.view');

        $entityType = strtolower(CrmSupport::queryStr('entity_type'));
        if (!in_array($entityType, ['company', 'contact', 'lead', 'deal'], true)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }
        $activeOnly = CrmSupport::queryStr('active_only');
        $onlyActive = $activeOnly === '' ? true : CrmSupport::boolValue($activeOnly);
        $rows = CrmSupport::customFields($pdo, $entityType, $onlyActive);
        Response::json([
            'entity_type' => $entityType,
            'data' => $rows,
        ]);
    }

    public static function upsertCustomField(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.custom_fields.edit');
        $data = Request::jsonBody();

        $entityType = strtolower(Request::str($data, 'entity_type'));
        if (!in_array($entityType, ['company', 'contact', 'lead', 'deal'], true)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }
        $fieldLabel = Request::str($data, 'field_label');
        if ($fieldLabel === '') {
            Response::json(['message' => 'field_label is required'], 422);
            return;
        }

        $fieldKeyRaw = Request::str($data, 'field_key');
        if ($fieldKeyRaw === '') {
            $fieldKeyRaw = self::slugify($fieldLabel);
        }
        $fieldKey = strtolower(trim($fieldKeyRaw));
        if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/', $fieldKey)) {
            Response::json(['message' => 'field_key invalid'], 422);
            return;
        }

        $fieldType = Request::str($data, 'field_type', 'text');
        $allowedTypes = ['text', 'textarea', 'number', 'date', 'datetime', 'select', 'checkbox', 'email', 'phone', 'url'];
        if (!in_array($fieldType, $allowedTypes, true)) {
            $fieldType = 'text';
        }

        $status = CrmSupport::normalizeStatus(Request::str($data, 'status', 'active'), ['active', 'inactive'], 'active');
        $options = self::normalizeOptions($data['options'] ?? null);
        $defaultValue = Request::str($data, 'default_value');
        $placeholder = Request::str($data, 'placeholder');
        $isRequired = CrmSupport::boolValue($data['is_required'] ?? false) ? 1 : 0;
        $sortOrder = Request::int($data, 'sort_order', 100);
        $sortOrder = max(-9999, min($sortOrder, 9999));
        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_custom_fields
             (entity_type, field_key, field_label, field_type, options_json, default_value, placeholder, is_required, sort_order, status, created_by, created_at, updated_at)
             VALUES
             (:entity_type, :field_key, :field_label, :field_type, :options_json, :default_value, :placeholder, :is_required, :sort_order, :status, :created_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                field_label = VALUES(field_label),
                field_type = VALUES(field_type),
                options_json = VALUES(options_json),
                default_value = VALUES(default_value),
                placeholder = VALUES(placeholder),
                is_required = VALUES(is_required),
                sort_order = VALUES(sort_order),
                status = VALUES(status),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'field_key' => $fieldKey,
            'field_label' => $fieldLabel,
            'field_type' => $fieldType,
            'options_json' => $options !== [] ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            'default_value' => $defaultValue,
            'placeholder' => $placeholder,
            'is_required' => $isRequired,
            'sort_order' => $sortOrder,
            'status' => $status,
            'created_by' => $uid > 0 ? $uid : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            $fetch = $pdo->prepare(
                'SELECT id FROM qiling_crm_custom_fields
                 WHERE entity_type = :entity_type AND field_key = :field_key
                 LIMIT 1'
            );
            $fetch->execute([
                'entity_type' => $entityType,
                'field_key' => $fieldKey,
            ]);
            $id = (int) $fetch->fetchColumn();
        }

        Audit::log($uid, 'crm.custom_field.upsert', 'crm_custom_field', $id, 'Upsert crm custom field', [
            'entity_type' => $entityType,
            'field_key' => $fieldKey,
            'field_type' => $fieldType,
            'status' => $status,
        ]);

        Response::json([
            'field_id' => $id,
            'entity_type' => $entityType,
            'field_key' => $fieldKey,
        ]);
    }

    public static function deleteCustomField(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.custom_fields.edit');
        $data = Request::jsonBody();
        $fieldId = Request::int($data, 'field_id', 0);
        if ($fieldId <= 0) {
            Response::json(['message' => 'field_id is required'], 422);
            return;
        }

        $stmt = $pdo->prepare('DELETE FROM qiling_crm_custom_fields WHERE id = :id');
        $stmt->execute(['id' => $fieldId]);
        if ((int) $stmt->rowCount() <= 0) {
            Response::json(['message' => 'field not found'], 404);
            return;
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.custom_field.delete', 'crm_custom_field', $fieldId, 'Delete crm custom field', []);
        Response::json(['field_id' => $fieldId]);
    }

    public static function formConfig(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.form_config.view');
        $entityType = strtolower(CrmSupport::queryStr('entity_type'));
        if (!in_array($entityType, ['company', 'contact', 'lead', 'deal'], true)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT id, entity_type, layout_json, status, updated_by, created_at, updated_at
             FROM qiling_crm_form_configs
             WHERE entity_type = :entity_type
             LIMIT 1'
        );
        $stmt->execute(['entity_type' => $entityType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            Response::json([
                'entity_type' => $entityType,
                'config' => [
                    'entity_type' => $entityType,
                    'layout' => [],
                    'status' => 'active',
                ],
            ]);
            return;
        }

        Response::json([
            'entity_type' => $entityType,
            'config' => [
                'id' => (int) ($row['id'] ?? 0),
                'entity_type' => (string) ($row['entity_type'] ?? $entityType),
                'layout' => CrmSupport::decodeJsonArray($row['layout_json'] ?? null),
                'status' => (string) ($row['status'] ?? 'active'),
                'updated_by' => (int) ($row['updated_by'] ?? 0),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ],
        ]);
    }

    public static function upsertFormConfig(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.form_config.edit');
        $data = Request::jsonBody();

        $entityType = strtolower(Request::str($data, 'entity_type'));
        if (!in_array($entityType, ['company', 'contact', 'lead', 'deal'], true)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }
        $layout = $data['layout'] ?? [];
        if (!is_array($layout)) {
            $layout = [];
        }
        $status = CrmSupport::normalizeStatus(Request::str($data, 'status', 'active'), ['active', 'inactive'], 'active');
        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_form_configs
             (entity_type, layout_json, status, updated_by, created_at, updated_at)
             VALUES
             (:entity_type, :layout_json, :status, :updated_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                layout_json = VALUES(layout_json),
                status = VALUES(status),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'layout_json' => json_encode(array_values($layout), JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'updated_by' => $uid > 0 ? $uid : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::log($uid, 'crm.form_config.upsert', 'crm_form_config', 0, 'Upsert crm form config', [
            'entity_type' => $entityType,
            'status' => $status,
            'field_count' => count($layout),
        ]);

        Response::json([
            'entity_type' => $entityType,
            'status' => $status,
            'field_count' => count($layout),
        ]);
    }

    public static function dedupeRules(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.governance.manage');

        $limit = CrmSupport::queryInt('limit') ?? 50;
        $limit = max(1, min($limit, 200));
        $stmt = $pdo->prepare(
            'SELECT id, entity_type, match_mobile, match_email, match_company, enabled, updated_by, created_at, updated_at
             FROM qiling_crm_dedupe_rules
             ORDER BY id ASC
             LIMIT ' . $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as &$row) {
            $row['match_mobile'] = (int) ($row['match_mobile'] ?? 0) === 1 ? 1 : 0;
            $row['match_email'] = (int) ($row['match_email'] ?? 0) === 1 ? 1 : 0;
            $row['match_company'] = (int) ($row['match_company'] ?? 0) === 1 ? 1 : 0;
            $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1 ? 1 : 0;
        }
        unset($row);
        Response::json(['data' => $rows]);
    }

    public static function upsertDedupeRule(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.governance.manage');
        $data = Request::jsonBody();

        $entityType = strtolower(Request::str($data, 'entity_type'));
        if (!in_array($entityType, ['company', 'contact', 'lead'], true)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_dedupe_rules
             (entity_type, match_mobile, match_email, match_company, enabled, updated_by, created_at, updated_at)
             VALUES
             (:entity_type, :match_mobile, :match_email, :match_company, :enabled, :updated_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                match_mobile = VALUES(match_mobile),
                match_email = VALUES(match_email),
                match_company = VALUES(match_company),
                enabled = VALUES(enabled),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'match_mobile' => CrmSupport::boolValue($data['match_mobile'] ?? false) ? 1 : 0,
            'match_email' => CrmSupport::boolValue($data['match_email'] ?? false) ? 1 : 0,
            'match_company' => CrmSupport::boolValue($data['match_company'] ?? false) ? 1 : 0,
            'enabled' => CrmSupport::boolValue($data['enabled'] ?? true) ? 1 : 0,
            'updated_by' => $uid > 0 ? $uid : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::log($uid, 'crm.dedupe_rule.upsert', 'crm_dedupe_rule', 0, 'Upsert crm dedupe rule', [
            'entity_type' => $entityType,
        ]);
        Response::json(['entity_type' => $entityType]);
    }

    /**
     * @param mixed $raw
     * @return array<int,string>
     */
    private static function normalizeOptions(mixed $raw): array
    {
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\r\n,，;；]+/', trim($raw));
            $list = is_array($parts) ? $parts : [];
        } else {
            $list = [];
        }
        $out = [];
        foreach ($list as $item) {
            $value = trim((string) $item);
            if ($value === '') {
                continue;
            }
            $out[$value] = $value;
            if (count($out) >= 100) {
                break;
            }
        }
        return array_values($out);
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        if (!is_string($value)) {
            return 'field_' . gmdate('His');
        }
        $value = trim($value, '_');
        if ($value === '' || !preg_match('/^[a-z]/', $value)) {
            $value = 'field_' . $value;
        }
        return substr($value, 0, 60);
    }
}
