<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmAutomationService;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmAutomationController
{
    public static function rules(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.automation.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $entityType = strtolower(CrmSupport::queryStr('entity_type'));
        if (!in_array($entityType, CrmAutomationService::entityTypes(), true)) {
            $entityType = '';
        }

        $enabled = CrmSupport::queryInt('enabled');
        if (!in_array($enabled, [0, 1], true)) {
            $enabled = null;
        }

        $sql = 'SELECT id, rule_name, entity_type, trigger_field, trigger_from, trigger_to, action_type, action_config_json, sort_order, enabled, created_by, created_at, updated_at
                FROM qiling_crm_automation_rules
                WHERE 1 = 1';
        $params = [];

        if ($entityType !== '') {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($enabled !== null) {
            $sql .= ' AND enabled = :enabled';
            $params['enabled'] = $enabled;
        }
        if ($cursor > 0) {
            $sql .= ' AND id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . $queryLimit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        foreach ($rows as &$row) {
            $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
            $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1 ? 1 : 0;
            $row['action_config'] = CrmSupport::decodeJsonObject($row['action_config_json'] ?? null);
            unset($row['action_config_json']);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
            'meta' => [
                'entity_types' => CrmAutomationService::entityTypes(),
                'action_types' => CrmAutomationService::actionTypes(),
                'trigger_fields' => ['status', 'deal_status', 'stage_key', 'pipeline_key'],
            ],
        ]);
    }

    public static function upsertRule(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.automation.manage');

        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }

        $data = Request::jsonBody();
        $ruleId = Request::int($data, 'id', 0);
        $ruleName = Request::str($data, 'rule_name');
        if ($ruleName === '') {
            Response::json(['message' => 'rule_name is required'], 422);
            return;
        }

        $entityType = strtolower(Request::str($data, 'entity_type'));
        if (!in_array($entityType, CrmAutomationService::entityTypes(), true)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }

        $triggerField = strtolower(Request::str($data, 'trigger_field'));
        if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', $triggerField)) {
            Response::json(['message' => 'trigger_field invalid'], 422);
            return;
        }

        $triggerFrom = self::trimTo(Request::str($data, 'trigger_from'), 40);
        $triggerTo = self::trimTo(Request::str($data, 'trigger_to'), 40);
        if ($triggerTo === '') {
            Response::json(['message' => 'trigger_to is required'], 422);
            return;
        }

        $actionType = strtolower(Request::str($data, 'action_type'));
        if (!array_key_exists($actionType, CrmAutomationService::actionTypes())) {
            Response::json(['message' => 'action_type invalid'], 422);
            return;
        }

        $actionConfig = [];
        if (is_array($data['action_config'] ?? null)) {
            $actionConfig = $data['action_config'];
        } elseif (is_string($data['action_config_json'] ?? null)) {
            $decoded = json_decode((string) $data['action_config_json'], true);
            if (is_array($decoded)) {
                $actionConfig = $decoded;
            }
        }

        $sortOrder = Request::int($data, 'sort_order', 100);
        $sortOrder = max(1, min($sortOrder, 9999));
        $enabled = CrmSupport::boolValue($data['enabled'] ?? true) ? 1 : 0;
        $now = gmdate('Y-m-d H:i:s');

        $actionConfigJson = json_encode($actionConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($actionConfigJson)) {
            $actionConfigJson = '{}';
        }

        if ($ruleId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM qiling_crm_automation_rules WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $ruleId]);
            if ((int) $stmt->fetchColumn() <= 0) {
                Response::json(['message' => 'rule not found'], 404);
                return;
            }

            $update = $pdo->prepare(
                'UPDATE qiling_crm_automation_rules
                 SET rule_name = :rule_name,
                     entity_type = :entity_type,
                     trigger_field = :trigger_field,
                     trigger_from = :trigger_from,
                     trigger_to = :trigger_to,
                     action_type = :action_type,
                     action_config_json = :action_config_json,
                     sort_order = :sort_order,
                     enabled = :enabled,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'rule_name' => $ruleName,
                'entity_type' => $entityType,
                'trigger_field' => $triggerField,
                'trigger_from' => $triggerFrom,
                'trigger_to' => $triggerTo,
                'action_type' => $actionType,
                'action_config_json' => $actionConfigJson,
                'sort_order' => $sortOrder,
                'enabled' => $enabled,
                'updated_at' => $now,
                'id' => $ruleId,
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO qiling_crm_automation_rules
                 (rule_name, entity_type, trigger_field, trigger_from, trigger_to, action_type, action_config_json, sort_order, enabled, created_by, created_at, updated_at)
                 VALUES
                 (:rule_name, :entity_type, :trigger_field, :trigger_from, :trigger_to, :action_type, :action_config_json, :sort_order, :enabled, :created_by, :created_at, :updated_at)'
            );
            $insert->execute([
                'rule_name' => $ruleName,
                'entity_type' => $entityType,
                'trigger_field' => $triggerField,
                'trigger_from' => $triggerFrom,
                'trigger_to' => $triggerTo,
                'action_type' => $actionType,
                'action_config_json' => $actionConfigJson,
                'sort_order' => $sortOrder,
                'enabled' => $enabled,
                'created_by' => (int) ($user['id'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ruleId = (int) $pdo->lastInsertId();
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.automation.rule.upsert', 'crm_automation_rule', $ruleId, 'Upsert crm automation rule', [
            'entity_type' => $entityType,
            'trigger_field' => $triggerField,
            'trigger_from' => $triggerFrom,
            'trigger_to' => $triggerTo,
            'action_type' => $actionType,
            'enabled' => $enabled,
            'sort_order' => $sortOrder,
        ]);

        Response::json([
            'rule_id' => $ruleId,
            'enabled' => $enabled,
        ]);
    }

    public static function logs(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.automation.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);

        $status = CrmSupport::queryStr('status');
        $entityType = strtolower(CrmSupport::queryStr('entity_type'));
        if (!in_array($entityType, CrmAutomationService::entityTypes(), true)) {
            $entityType = '';
        }
        $entityId = CrmSupport::queryInt('entity_id');
        $ruleId = CrmSupport::queryInt('rule_id');

        $sql = 'SELECT l.*, r.rule_name, u.username AS executed_username
                FROM qiling_crm_automation_logs l
                LEFT JOIN qiling_crm_automation_rules r ON r.id = l.rule_id
                LEFT JOIN qiling_users u ON u.id = l.executed_by
                WHERE 1 = 1';
        $params = [];

        if ($status !== '') {
            $sql .= ' AND l.status = :status';
            $params['status'] = $status;
        }
        if ($entityType !== '') {
            $sql .= ' AND l.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($entityId !== null && $entityId > 0) {
            $sql .= ' AND l.entity_id = :entity_id';
            $params['entity_id'] = $entityId;
        }
        if ($ruleId !== null && $ruleId > 0) {
            $sql .= ' AND l.rule_id = :rule_id';
            $params['rule_id'] = $ruleId;
        }

        if (!CrmService::canManageAll($user)) {
            $uid = (int) ($user['id'] ?? 0);
            if ($uid <= 0) {
                Response::json(['message' => 'unauthorized'], 401);
                return;
            }
            $sql .= ' AND (l.executed_by = :executed_by OR l.executed_by IS NULL OR l.executed_by = 0)';
            $params['executed_by'] = $uid;
        }

        if ($cursor > 0) {
            $sql .= ' AND l.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY l.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        foreach ($rows as &$row) {
            $row['rule_id'] = (int) ($row['rule_id'] ?? 0);
            $row['entity_id'] = (int) ($row['entity_id'] ?? 0);
            $row['payload'] = CrmSupport::decodeJsonObject($row['payload_json'] ?? null);
            $row['response'] = CrmSupport::decodeJsonObject($row['response_json'] ?? null);
            unset($row['payload_json'], $row['response_json']);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    private static function trimTo(string $value, int $max): string
    {
        $value = trim($value);
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) > $max) {
                return mb_substr($value, 0, $max);
            }
            return $value;
        }
        if (strlen($value) > $max) {
            return substr($value, 0, $max);
        }
        return $value;
    }
}
