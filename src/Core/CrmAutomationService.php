<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class CrmAutomationService
{
    /** @var array<string, string> */
    private const ENTITY_TABLES = [
        'lead' => 'qiling_crm_leads',
        'contact' => 'qiling_crm_contacts',
        'company' => 'qiling_crm_companies',
        'deal' => 'qiling_crm_deals',
    ];

    /** @var array<string, string> */
    private const ACTION_TYPES = [
        'create_task' => '创建任务',
        'assign_owner' => '分配负责人',
        'create_reminder' => '创建提醒',
        'webhook' => '调用 Webhook',
    ];

    /** @var array<int, string> */
    private const VISIBILITY_LEVELS = ['private', 'team', 'department', 'public'];

    /** @var array<int, string> */
    private const ACTIVITY_TYPES = ['note', 'call', 'email', 'meeting', 'task'];

    /** @var array<int, string> */
    private const ACTIVITY_STATUSES = ['todo', 'done', 'cancelled'];

    /** @var array<int, string> */
    private const REMINDER_TYPES = ['schedule', 'due', 'overdue'];

    /** @var array<int, string> */
    private const ENTITY_TYPES = ['lead', 'contact', 'company', 'deal'];

    /**
     * @return array<string, string>
     */
    public static function actionTypes(): array
    {
        return self::ACTION_TYPES;
    }

    /**
     * @return array<int, string>
     */
    public static function entityTypes(): array
    {
        return self::ENTITY_TYPES;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $entitySnapshot
     * @return array<string,mixed>
     */
    public static function executeFieldChange(
        PDO $pdo,
        array $user,
        string $entityType,
        int $entityId,
        string $triggerField,
        string $fromValue,
        string $toValue,
        array $entitySnapshot = []
    ): array {
        $entityType = strtolower(trim($entityType));
        $triggerField = strtolower(trim($triggerField));
        $fromValue = trim($fromValue);
        $toValue = trim($toValue);

        $summary = [
            'matched' => 0,
            'executed' => 0,
            'success' => 0,
            'failed' => 0,
            'logs' => [],
        ];

        if (
            $entityId <= 0
            || !isset(self::ENTITY_TABLES[$entityType])
            || $triggerField === ''
            || $toValue === ''
            || $fromValue === $toValue
        ) {
            return $summary;
        }

        $rules = self::matchedRules($pdo, $entityType, $triggerField, $fromValue, $toValue);
        if ($rules === []) {
            return $summary;
        }

        $summary['matched'] = count($rules);
        $executedBy = (int) ($user['id'] ?? 0);
        $snapshot = $entitySnapshot;

        foreach ($rules as $rule) {
            $ruleId = (int) ($rule['id'] ?? 0);
            if ($ruleId <= 0) {
                continue;
            }

            $actionType = strtolower(trim((string) ($rule['action_type'] ?? '')));
            $config = self::decodeJsonObject($rule['action_config_json'] ?? null);
            $now = gmdate('Y-m-d H:i:s');
            $status = 'success';
            $message = 'ok';
            $response = [];

            $payload = [
                'rule_name' => (string) ($rule['rule_name'] ?? ''),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'trigger_field' => $triggerField,
                'trigger_from' => $fromValue,
                'trigger_to' => $toValue,
                'action_type' => $actionType,
                'action_config' => $config,
            ];

            try {
                if ($snapshot === []) {
                    $snapshot = self::findEntityById($pdo, $entityType, $entityId);
                }

                $response = self::executeAction(
                    $pdo,
                    $user,
                    $entityType,
                    $entityId,
                    $triggerField,
                    $fromValue,
                    $toValue,
                    $actionType,
                    $config,
                    $snapshot
                );
                $summary['success']++;
            } catch (\Throwable $e) {
                $status = 'failed';
                $message = self::truncate($e->getMessage(), 500);
                $response = ['error' => $message];
                $summary['failed']++;
            }

            $logId = self::writeLog($pdo, [
                'rule_id' => $ruleId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'trigger_field' => $triggerField,
                'trigger_from' => $fromValue,
                'trigger_to' => $toValue,
                'action_type' => $actionType,
                'status' => $status,
                'message' => $message,
                'payload_json' => self::encodeJson($payload),
                'response_json' => self::encodeJson($response),
                'executed_by' => $executedBy > 0 ? $executedBy : null,
                'executed_at' => $now,
                'created_at' => $now,
            ]);

            $summary['executed']++;
            $summary['logs'][] = [
                'rule_id' => $ruleId,
                'log_id' => $logId,
                'status' => $status,
            ];
        }

        return $summary;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private static function matchedRules(PDO $pdo, string $entityType, string $triggerField, string $fromValue, string $toValue): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, rule_name, action_type, action_config_json
             FROM qiling_crm_automation_rules
             WHERE enabled = 1
               AND entity_type = :entity_type
               AND trigger_field = :trigger_field
               AND trigger_to = :trigger_to
               AND (trigger_from = :trigger_from OR trigger_from = \'\')
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'trigger_field' => $triggerField,
            'trigger_to' => $toValue,
            'trigger_from' => $fromValue,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $config
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function executeAction(
        PDO $pdo,
        array $user,
        string $entityType,
        int $entityId,
        string $triggerField,
        string $fromValue,
        string $toValue,
        string $actionType,
        array $config,
        array $snapshot
    ): array {
        if (!isset(self::ACTION_TYPES[$actionType])) {
            throw new \RuntimeException('unsupported action_type');
        }

        if ($actionType === 'create_task') {
            return self::createTaskAction($pdo, $user, $entityType, $entityId, $triggerField, $fromValue, $toValue, $config, $snapshot);
        }
        if ($actionType === 'assign_owner') {
            return self::assignOwnerAction($pdo, $entityType, $entityId, $config);
        }
        if ($actionType === 'create_reminder') {
            return self::createReminderAction($pdo, $user, $entityType, $entityId, $triggerField, $fromValue, $toValue, $config, $snapshot);
        }

        return self::webhookAction($entityType, $entityId, $triggerField, $fromValue, $toValue, $config, $snapshot);
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $config
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function createTaskAction(
        PDO $pdo,
        array $user,
        string $entityType,
        int $entityId,
        string $triggerField,
        string $fromValue,
        string $toValue,
        array $config,
        array $snapshot
    ): array {
        $ownerUserId = self::resolveOwnerUserId($pdo, $user, $config, $snapshot);
        $ownerOrg = self::resolveOwnerOrgScope($pdo, $ownerUserId);
        $now = gmdate('Y-m-d H:i:s');

        $subject = trim((string) ($config['subject'] ?? ''));
        if ($subject === '') {
            $subject = self::entityLabel($entityType) . '状态流转自动任务';
        }

        $content = trim((string) ($config['content'] ?? ''));
        if ($content === '') {
            $content = sprintf(
                '%s #%d 字段 %s 变更：%s -> %s',
                self::entityLabel($entityType),
                $entityId,
                $triggerField,
                ($fromValue !== '' ? $fromValue : '(空)'),
                ($toValue !== '' ? $toValue : '(空)')
            );
        }

        $dueInMinutes = self::intValue($config['due_in_minutes'] ?? 0, -30 * 24 * 60, 30 * 24 * 60);
        $dueAt = gmdate('Y-m-d H:i:s', time() + ($dueInMinutes * 60));

        $activityType = self::normalizeEnum((string) ($config['activity_type'] ?? 'task'), self::ACTIVITY_TYPES, 'task');
        $status = self::normalizeEnum((string) ($config['status'] ?? 'todo'), self::ACTIVITY_STATUSES, 'todo');
        $doneAt = $status === 'done' ? $now : null;
        $visibility = self::normalizeEnum((string) ($config['visibility_level'] ?? 'private'), self::VISIBILITY_LEVELS, 'private');

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_activities
             (entity_type, entity_id, activity_type, subject, content, due_at, done_at, status, owner_user_id, owner_team_id, owner_department_id, visibility_level, created_by, created_at, updated_at)
             VALUES
             (:entity_type, :entity_id, :activity_type, :subject, :content, :due_at, :done_at, :status, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'activity_type' => $activityType,
            'subject' => $subject,
            'content' => $content,
            'due_at' => $dueAt,
            'done_at' => $doneAt,
            'status' => $status,
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibility,
            'created_by' => (int) ($user['id'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'activity_id' => (int) $pdo->lastInsertId(),
            'owner_user_id' => $ownerUserId,
            'due_at' => $dueAt,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function assignOwnerAction(PDO $pdo, string $entityType, int $entityId, array $config): array
    {
        $ownerUserId = (int) ($config['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            throw new \RuntimeException('assign_owner requires owner_user_id');
        }
        self::assertActiveUser($pdo, $ownerUserId);

        $table = self::ENTITY_TABLES[$entityType] ?? '';
        if ($table === '') {
            throw new \RuntimeException('entity_type is not supported for assign_owner');
        }

        $ownerOrg = self::resolveOwnerOrgScope($pdo, $ownerUserId);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE ' . $table . '
             SET owner_user_id = :owner_user_id,
                 owner_team_id = :owner_team_id,
                 owner_department_id = :owner_department_id,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'updated_at' => $now,
            'id' => $entityId,
        ]);

        return [
            'owner_user_id' => $ownerUserId,
            'updated_rows' => (int) $stmt->rowCount(),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $config
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function createReminderAction(
        PDO $pdo,
        array $user,
        string $entityType,
        int $entityId,
        string $triggerField,
        string $fromValue,
        string $toValue,
        array $config,
        array $snapshot
    ): array {
        $userId = (int) ($config['user_id'] ?? 0);
        if ($userId <= 0) {
            $userId = (int) ($snapshot['owner_user_id'] ?? 0);
        }
        if ($userId <= 0) {
            $userId = (int) ($user['id'] ?? 0);
        }
        if ($userId <= 0) {
            throw new \RuntimeException('reminder receiver is missing');
        }
        self::assertActiveUser($pdo, $userId);

        $reminderType = self::normalizeEnum((string) ($config['reminder_type'] ?? 'due'), self::REMINDER_TYPES, 'due');
        $title = trim((string) ($config['title'] ?? ''));
        if ($title === '') {
            $title = self::entityLabel($entityType) . '状态变更提醒';
        }

        $content = trim((string) ($config['content'] ?? ''));
        if ($content === '') {
            $content = sprintf(
                '%s #%d 字段 %s 变更：%s -> %s',
                self::entityLabel($entityType),
                $entityId,
                $triggerField,
                ($fromValue !== '' ? $fromValue : '(空)'),
                ($toValue !== '' ? $toValue : '(空)')
            );
        }

        $dueAt = self::parseDateTime((string) ($config['due_at'] ?? ''));
        if ($dueAt === null) {
            $dueInMinutes = self::intValue($config['due_in_minutes'] ?? 0, -30 * 24 * 60, 30 * 24 * 60);
            $dueAt = gmdate('Y-m-d H:i:s', time() + ($dueInMinutes * 60));
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_notifications
             (user_id, activity_id, entity_type, entity_id, reminder_type, title, content, due_at, status, sent_at, read_at, created_at, updated_at)
             VALUES
             (:user_id, NULL, :entity_type, :entity_id, :reminder_type, :title, :content, :due_at, :status, :sent_at, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reminder_type' => $reminderType,
            'title' => $title,
            'content' => $content,
            'due_at' => $dueAt,
            'status' => 'unread',
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'notification_id' => (int) $pdo->lastInsertId(),
            'user_id' => $userId,
            'reminder_type' => $reminderType,
            'due_at' => $dueAt,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function webhookAction(
        string $entityType,
        int $entityId,
        string $triggerField,
        string $fromValue,
        string $toValue,
        array $config,
        array $snapshot
    ): array {
        $webhookUrl = trim((string) ($config['webhook_url'] ?? ''));
        if ($webhookUrl === '' || preg_match('/^https?:\/\//i', $webhookUrl) !== 1) {
            throw new \RuntimeException('webhook_url is invalid');
        }

        $timeout = self::intValue($config['timeout_seconds'] ?? 10, 3, 30);
        $headers = self::sanitizeHeaders($config['headers'] ?? null);
        $extraPayload = is_array($config['payload'] ?? null) ? $config['payload'] : [];
        $allowedHosts = self::allowedWebhookHosts();
        if ($allowedHosts === []) {
            throw new \RuntimeException('CRM_WEBHOOK_ALLOWED_HOSTS is required for webhook action');
        }

        $payload = array_merge([
            'event' => 'crm.automation.triggered',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'trigger_field' => $triggerField,
            'trigger_from' => $fromValue,
            'trigger_to' => $toValue,
            'triggered_at' => gmdate('Y-m-d H:i:s'),
            'entity' => $snapshot,
        ], $extraPayload);

        $allowPrivateNetwork = self::toBool((string) Config::get('CRM_WEBHOOK_ALLOW_PRIVATE_NETWORK', 'false'));
        $response = HttpClient::postJson($webhookUrl, $payload, $headers, $timeout, [
            'block_private_network' => !$allowPrivateNetwork,
            'disallow_redirects' => true,
            'allowed_hosts' => $allowedHosts,
            'require_allowed_hosts' => true,
        ]);
        $statusCode = (int) ($response['status_code'] ?? 0);
        if ((string) ($response['error'] ?? '') !== '') {
            throw new \RuntimeException((string) $response['error']);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('webhook returned HTTP ' . $statusCode);
        }

        return [
            'status_code' => $statusCode,
            'body' => self::truncate((string) ($response['body'] ?? ''), 1000),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $config
     * @param array<string,mixed> $snapshot
     */
    private static function resolveOwnerUserId(PDO $pdo, array $user, array $config, array $snapshot): int
    {
        $ownerUserId = (int) ($config['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            $ownerUserId = (int) ($snapshot['owner_user_id'] ?? 0);
        }
        if ($ownerUserId <= 0) {
            $ownerUserId = (int) ($user['id'] ?? 0);
        }
        if ($ownerUserId <= 0) {
            throw new \RuntimeException('owner_user_id is invalid');
        }

        self::assertActiveUser($pdo, $ownerUserId);
        return $ownerUserId;
    }

    /**
     * @return array{owner_team_id:?int,owner_department_id:?int}
     */
    private static function resolveOwnerOrgScope(PDO $pdo, int $ownerUserId): array
    {
        if ($ownerUserId <= 0) {
            return [
                'owner_team_id' => null,
                'owner_department_id' => null,
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT tm.team_id, COALESCE(tm.department_id, t.department_id) AS department_id
             FROM qiling_crm_team_members tm
             LEFT JOIN qiling_crm_teams t ON t.id = tm.team_id
             WHERE tm.user_id = :user_id
               AND tm.status = :member_status
               AND (t.id IS NULL OR t.status = :team_status)
             ORDER BY tm.id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $ownerUserId,
            'member_status' => 'active',
            'team_status' => 'active',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'owner_team_id' => null,
                'owner_department_id' => null,
            ];
        }

        $teamId = (int) ($row['team_id'] ?? 0);
        $departmentId = (int) ($row['department_id'] ?? 0);

        return [
            'owner_team_id' => $teamId > 0 ? $teamId : null,
            'owner_department_id' => $departmentId > 0 ? $departmentId : null,
        ];
    }

    private static function assertActiveUser(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM qiling_users
             WHERE id = :id
               AND status = :status
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $userId,
            'status' => 'active',
        ]);

        if ((int) $stmt->fetchColumn() <= 0) {
            throw new \RuntimeException('target owner user not found');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function findEntityById(PDO $pdo, string $entityType, int $entityId): array
    {
        $table = self::ENTITY_TABLES[$entityType] ?? '';
        if ($table === '') {
            return [];
        }

        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function writeLog(PDO $pdo, array $row): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_automation_logs
             (rule_id, entity_type, entity_id, trigger_field, trigger_from, trigger_to, action_type, status, message, payload_json, response_json, executed_by, executed_at, created_at)
             VALUES
             (:rule_id, :entity_type, :entity_id, :trigger_field, :trigger_from, :trigger_to, :action_type, :status, :message, :payload_json, :response_json, :executed_by, :executed_at, :created_at)'
        );
        $stmt->execute([
            'rule_id' => (int) ($row['rule_id'] ?? 0),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (int) ($row['entity_id'] ?? 0),
            'trigger_field' => (string) ($row['trigger_field'] ?? ''),
            'trigger_from' => (string) ($row['trigger_from'] ?? ''),
            'trigger_to' => (string) ($row['trigger_to'] ?? ''),
            'action_type' => (string) ($row['action_type'] ?? ''),
            'status' => (string) ($row['status'] ?? 'failed'),
            'message' => self::truncate((string) ($row['message'] ?? ''), 500),
            'payload_json' => $row['payload_json'] ?? null,
            'response_json' => $row['response_json'] ?? null,
            'executed_by' => $row['executed_by'] ?? null,
            'executed_at' => (string) ($row['executed_at'] ?? gmdate('Y-m-d H:i:s')),
            'created_at' => (string) ($row['created_at'] ?? gmdate('Y-m-d H:i:s')),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private static function decodeJsonObject(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    /**
     * @param array<int,string> $allowed
     */
    private static function normalizeEnum(string $value, array $allowed, string $default): string
    {
        $value = strtolower(trim($value));
        if ($value !== '' && in_array($value, $allowed, true)) {
            return $value;
        }
        return $default;
    }

    private static function intValue(mixed $value, int $min, int $max): int
    {
        $num = is_numeric($value) ? (int) $value : 0;
        return max($min, min($num, $max));
    }

    /**
     * @return array<int, string>
     */
    private static function allowedWebhookHosts(): array
    {
        $raw = (string) Config::get('CRM_WEBHOOK_ALLOWED_HOSTS', '');
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', strtolower(trim($raw)));
        if (!is_array($parts)) {
            return [];
        }

        $hosts = [];
        foreach ($parts as $part) {
            $host = trim((string) $part);
            if ($host === '') {
                continue;
            }
            $hosts[$host] = $host;
        }

        return array_values($hosts);
    }

    private static function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function parseDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * @param mixed $raw
     * @return array<string,string>
     */
    private static function sanitizeHeaders(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $headers = [];
        foreach ($raw as $key => $value) {
            $name = trim((string) $key);
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9\-]{1,80}$/', $name)) {
                continue;
            }
            $headers[$name] = self::truncate(trim((string) $value), 300);
            if (count($headers) >= 20) {
                break;
            }
        }

        return $headers;
    }

    private static function entityLabel(string $entityType): string
    {
        if ($entityType === 'lead') {
            return '线索';
        }
        if ($entityType === 'contact') {
            return '联系人';
        }
        if ($entityType === 'company') {
            return '企业';
        }
        if ($entityType === 'deal') {
            return '商机';
        }
        return $entityType;
    }

    private static function truncate(string $value, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= $max) {
                return $value;
            }
            return mb_substr($value, 0, $max);
        }
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }
}
