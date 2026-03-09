<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Core\Push\PushService;
use Qiling\Core\SystemSettingService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmReminderController
{
    /** @var array<int, string> */
    private const REMINDER_TYPES = ['schedule', 'due', 'overdue'];

    private const PUSH_TEMPLATE_FALLBACK = "类型：{reminder_type_label}\n标题：{title}\n截止：{due_at}\n内容：{content}";

    public static function notifications(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.view');
        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);

        $requestedUserId = CrmSupport::queryInt('user_id');
        $scopeUserId = self::resolveScopeUserId($user, $requestedUserId);
        $status = CrmSupport::queryStr('status');
        $reminderType = CrmSupport::queryStr('reminder_type');

        $sql = 'SELECT n.*, a.subject AS activity_subject, a.status AS activity_status
                FROM qiling_crm_notifications n
                LEFT JOIN qiling_crm_activities a ON a.id = n.activity_id
                WHERE n.user_id = :user_id';
        $params = ['user_id' => $scopeUserId];

        if ($status !== '') {
            $sql .= ' AND n.status = :status';
            $params['status'] = $status;
        }
        if ($reminderType !== '') {
            $sql .= ' AND n.reminder_type = :reminder_type';
            $params['reminder_type'] = $reminderType;
        }
        if ($cursor > 0) {
            $sql .= ' AND n.id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY n.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function summary(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.view');

        $requestedUserId = CrmSupport::queryInt('user_id');
        $scopeUserId = self::resolveScopeUserId($user, $requestedUserId);

        $stmt = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN status = \'unread\' THEN 1 ELSE 0 END) AS unread_count,
                SUM(CASE WHEN status = \'read\' THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN reminder_type = \'overdue\' AND status = \'unread\' THEN 1 ELSE 0 END) AS overdue_unread_count
             FROM qiling_crm_notifications
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $scopeUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $row = [];
        }

        Response::json([
            'user_id' => $scopeUserId,
            'summary' => [
                'unread_count' => (int) ($row['unread_count'] ?? 0),
                'read_count' => (int) ($row['read_count'] ?? 0),
                'overdue_unread_count' => (int) ($row['overdue_unread_count'] ?? 0),
            ],
        ]);
    }

    public static function markRead(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.view');
        $data = Request::jsonBody();

        $requestedUserId = Request::int($data, 'user_id', 0);
        $scopeUserId = self::resolveScopeUserId($user, $requestedUserId > 0 ? $requestedUserId : null);
        $markAll = CrmSupport::boolValue($data['mark_all'] ?? false);
        $notificationIds = CrmSupport::positiveIdList($data['notification_ids'] ?? null, 1000);
        if (!$markAll && $notificationIds === []) {
            Response::json(['message' => 'notification_ids is required'], 422);
            return;
        }

        $params = [
            'user_id' => $scopeUserId,
            'status_read' => 'read',
            'read_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        $sql = 'UPDATE qiling_crm_notifications
                SET status = :status_read,
                    read_at = :read_at,
                    updated_at = :updated_at
                WHERE user_id = :user_id
                  AND status <> :status_read';

        if (!$markAll) {
            $idSql = self::buildIdSql('nid', $notificationIds, $params);
            $sql .= ' AND id IN (' . $idSql . ')';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = (int) $stmt->rowCount();

        Response::json([
            'user_id' => $scopeUserId,
            'affected' => $affected,
            'mark_all' => $markAll,
        ]);
    }

    public static function rules(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.view');

        $stmt = $pdo->prepare(
            'SELECT id, rule_code, rule_name, remind_type, offset_minutes, enabled, created_at, updated_at
             FROM qiling_crm_reminder_rules
             ORDER BY id ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as &$row) {
            $row['offset_minutes'] = (int) ($row['offset_minutes'] ?? 0);
            $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1 ? 1 : 0;
        }
        unset($row);

        Response::json(['data' => $rows]);
    }

    public static function upsertRule(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.edit');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }

        $data = Request::jsonBody();
        $ruleCode = Request::str($data, 'rule_code');
        if ($ruleCode === '') {
            Response::json(['message' => 'rule_code is required'], 422);
            return;
        }
        if (!preg_match('/^[a-z0-9_\\-]{4,40}$/i', $ruleCode)) {
            Response::json(['message' => 'rule_code invalid'], 422);
            return;
        }

        $ruleName = Request::str($data, 'rule_name', $ruleCode);
        $remindType = Request::str($data, 'remind_type', 'schedule');
        if (!in_array($remindType, ['schedule', 'due', 'overdue'], true)) {
            Response::json(['message' => 'remind_type invalid'], 422);
            return;
        }
        $offsetMinutes = Request::int($data, 'offset_minutes', 0);
        $offsetMinutes = max(0, min($offsetMinutes, 30 * 24 * 60));
        $enabled = CrmSupport::boolValue($data['enabled'] ?? true) ? 1 : 0;
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_reminder_rules
             (rule_code, rule_name, remind_type, offset_minutes, enabled, created_by, created_at, updated_at)
             VALUES
             (:rule_code, :rule_name, :remind_type, :offset_minutes, :enabled, :created_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                rule_name = VALUES(rule_name),
                remind_type = VALUES(remind_type),
                offset_minutes = VALUES(offset_minutes),
                enabled = VALUES(enabled),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'rule_code' => $ruleCode,
            'rule_name' => $ruleName,
            'remind_type' => $remindType,
            'offset_minutes' => $offsetMinutes,
            'enabled' => $enabled,
            'created_by' => (int) ($user['id'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::log((int) ($user['id'] ?? 0), 'crm.reminder_rule.upsert', 'crm_reminder_rule', 0, 'Upsert crm reminder rule', [
            'rule_code' => $ruleCode,
            'remind_type' => $remindType,
            'offset_minutes' => $offsetMinutes,
            'enabled' => $enabled,
        ]);

        Response::json([
            'rule_code' => $ruleCode,
            'remind_type' => $remindType,
            'offset_minutes' => $offsetMinutes,
            'enabled' => $enabled,
        ]);
    }

    public static function pushSettings(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.edit');

        $settings = self::loadPushSettings($pdo);
        $canManage = CrmService::canManageAll($user);
        $channels = PushService::listEnabledChannelOptions($pdo, ['feishu', 'dingtalk']);

        Response::json([
            'editable' => $canManage ? 1 : 0,
            'settings' => $settings,
            'channel_options' => $channels,
            'allowed_types' => self::REMINDER_TYPES,
            'template_placeholders' => [
                '{prefix}',
                '{title}',
                '{content}',
                '{due_at}',
                '{reminder_type}',
                '{reminder_type_label}',
                '{activity_id}',
                '{notification_id}',
                '{user_id}',
                '{generated_at}',
            ],
        ]);
    }

    public static function savePushSettings(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.edit');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }

        $data = Request::jsonBody();
        $enabled = CrmSupport::boolValue($data['enabled'] ?? false);
        $channelIds = self::normalizeChannelIds($data['channel_ids'] ?? []);
        $types = self::normalizeReminderTypes($data['reminder_types'] ?? []);
        if ($types === []) {
            $types = self::REMINDER_TYPES;
        }

        $titlePrefix = trim((string) ($data['title_prefix'] ?? ''));
        if ($titlePrefix === '') {
            $titlePrefix = '【启灵CRM提醒】';
        }
        if (mb_strlen($titlePrefix) > 40) {
            $titlePrefix = mb_substr($titlePrefix, 0, 40);
        }

        $template = trim((string) ($data['template'] ?? ''));
        if ($template === '') {
            $template = self::PUSH_TEMPLATE_FALLBACK;
        }
        if (mb_strlen($template) > 2000) {
            $template = mb_substr($template, 0, 2000);
        }

        $maxPerRun = Request::int($data, 'max_per_run', 50);
        $maxPerRun = max(1, min($maxPerRun, 500));
        $onlyCreated = CrmSupport::boolValue($data['only_created'] ?? true);
        $channelIdsJson = json_encode($channelIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($channelIdsJson)) {
            $channelIdsJson = '[]';
        }
        $typesJson = json_encode($types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($typesJson)) {
            $typesJson = '[]';
        }

        $payload = [
            'crm_reminder_push_enabled' => $enabled ? '1' : '0',
            'crm_reminder_push_channel_ids' => $channelIdsJson,
            'crm_reminder_push_types' => $typesJson,
            'crm_reminder_push_title_prefix' => $titlePrefix,
            'crm_reminder_push_template' => $template,
            'crm_reminder_push_max_per_run' => (string) $maxPerRun,
            'crm_reminder_push_only_created' => $onlyCreated ? '1' : '0',
        ];

        SystemSettingService::upsert($pdo, $payload, (int) ($user['id'] ?? 0));
        $saved = self::loadPushSettings($pdo);

        Audit::log((int) ($user['id'] ?? 0), 'crm.reminder_push_settings.update', 'system_settings', 0, 'Update crm reminder push settings', [
            'enabled' => $saved['enabled'] ?? 0,
            'channel_ids' => $saved['channel_ids'] ?? [],
            'reminder_types' => $saved['reminder_types'] ?? [],
            'max_per_run' => $saved['max_per_run'] ?? 50,
            'only_created' => $saved['only_created'] ?? 1,
        ]);

        Response::json([
            'editable' => 1,
            'settings' => $saved,
        ]);
    }

    public static function run(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.reminders.edit');
        $data = Request::jsonBody();

        $limit = Request::int($data, 'limit', 200);
        $limit = max(1, min($limit, 1000));
        $lookbackDays = Request::int($data, 'lookback_days', 30);
        $lookbackDays = max(1, min($lookbackDays, 365));

        $scopeUserId = null;
        if (!CrmService::canManageAll($user)) {
            $scopeUserId = (int) ($user['id'] ?? 0);
        } else {
            $requestedUserId = Request::int($data, 'user_id', 0);
            if ($requestedUserId > 0) {
                $scopeUserId = $requestedUserId;
            }
        }

        $rules = self::enabledRules($pdo);
        $pushSettings = self::loadPushSettings($pdo);
        $pushEnabled = (int) ($pushSettings['enabled'] ?? 0) === 1;
        $pushOnlyCreated = (int) ($pushSettings['only_created'] ?? 1) === 1;
        if ($rules === []) {
            Response::json([
                'summary' => [
                    'checked' => 0,
                    'created' => 0,
                    'duplicated' => 0,
                    'rules' => 0,
                ],
                'details' => [],
                'push' => self::emptyPushSummary($pushSettings, 0),
            ]);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $minDueAt = gmdate('Y-m-d H:i:s', strtotime('-' . $lookbackDays . ' days'));

        $created = 0;
        $duplicated = 0;
        $checked = 0;
        $details = [];
        $pushCandidates = [];
        $txOwner = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $txOwner = true;
        }

        try {
            foreach ($rules as $rule) {
                $ruleCode = (string) ($rule['rule_code'] ?? '');
                $remindType = (string) ($rule['remind_type'] ?? '');
                $offsetMinutes = (int) ($rule['offset_minutes'] ?? 0);
                $activities = self::matchedActivities($pdo, $remindType, $offsetMinutes, $minDueAt, $now, $limit, $scopeUserId);
                $checked += count($activities);

                $ruleCreated = 0;
                $ruleDuplicated = 0;
                foreach ($activities as $activity) {
                    $notification = self::buildNotificationPayload($activity, $remindType);
                    if ($notification === null) {
                        continue;
                    }

                    $insertedId = self::insertNotification($pdo, $notification, $now);
                    if ($insertedId > 0) {
                        $created++;
                        $ruleCreated++;
                        if ($pushEnabled) {
                            $notification['id'] = $insertedId;
                            $pushCandidates[] = $notification;
                        }
                    } else {
                        $duplicated++;
                        $ruleDuplicated++;
                        if ($pushEnabled && !$pushOnlyCreated) {
                            $notification['id'] = 0;
                            $pushCandidates[] = $notification;
                        }
                    }
                }

                $details[] = [
                    'rule_code' => $ruleCode,
                    'remind_type' => $remindType,
                    'offset_minutes' => $offsetMinutes,
                    'matched' => count($activities),
                    'created' => $ruleCreated,
                    'duplicated' => $ruleDuplicated,
                ];
            }

            if ($txOwner && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($txOwner && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('crm reminder run failed', $e);
            return;
        }

        $pushSummary = self::dispatchReminderPush($pdo, $pushSettings, $pushCandidates, $now);
        Audit::log((int) ($user['id'] ?? 0), 'crm.reminder.run', 'crm_notification', 0, 'Run crm reminder generator', [
            'limit' => $limit,
            'lookback_days' => $lookbackDays,
            'scope_user_id' => $scopeUserId,
            'checked' => $checked,
            'created' => $created,
            'duplicated' => $duplicated,
            'push_enabled' => $pushSummary['enabled'] ?? 0,
            'push_attempted' => $pushSummary['attempted'] ?? 0,
            'push_notification_sent' => $pushSummary['notification_sent'] ?? 0,
            'push_notification_failed' => $pushSummary['notification_failed'] ?? 0,
            'push_channel_sent' => $pushSummary['channel_sent'] ?? 0,
            'push_channel_failed' => $pushSummary['channel_failed'] ?? 0,
        ]);

        Response::json([
            'summary' => [
                'checked' => $checked,
                'created' => $created,
                'duplicated' => $duplicated,
                'rules' => count($rules),
            ],
            'details' => $details,
            'push' => $pushSummary,
        ]);
    }

    private static function resolveScopeUserId(array $user, ?int $requestedUserId): int
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            Response::json(['message' => 'unauthorized'], 401);
            exit;
        }
        if (CrmService::canManageAll($user)) {
            return ($requestedUserId !== null && $requestedUserId > 0) ? $requestedUserId : $uid;
        }
        if ($requestedUserId !== null && $requestedUserId > 0 && $requestedUserId !== $uid) {
            Response::json(['message' => 'forbidden: cross-user query denied'], 403);
            exit;
        }
        return $uid;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function enabledRules(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, rule_code, rule_name, remind_type, offset_minutes
             FROM qiling_crm_reminder_rules
             WHERE enabled = 1
             ORDER BY id ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function matchedActivities(
        PDO $pdo,
        string $remindType,
        int $offsetMinutes,
        string $minDueAt,
        string $now,
        int $limit,
        ?int $scopeUserId
    ): array {
        $sql = 'SELECT id, entity_type, entity_id, subject, due_at, owner_user_id
                FROM qiling_crm_activities
                WHERE status = :status
                  AND due_at IS NOT NULL
                  AND due_at >= :min_due_at
                  AND owner_user_id IS NOT NULL
                  AND owner_user_id > 0';
        $params = [
            'status' => 'todo',
            'min_due_at' => $minDueAt,
        ];

        if ($scopeUserId !== null && $scopeUserId > 0) {
            $sql .= ' AND owner_user_id = :owner_user_id';
            $params['owner_user_id'] = $scopeUserId;
        }

        if ($remindType === 'schedule') {
            $sql .= ' AND due_at <= :schedule_due_to';
            $params['schedule_due_to'] = gmdate('Y-m-d H:i:s', strtotime($now . ' +' . $offsetMinutes . ' minutes'));
        } elseif ($remindType === 'due') {
            $sql .= ' AND due_at <= :due_at';
            $params['due_at'] = gmdate('Y-m-d H:i:s', strtotime($now . ' -' . $offsetMinutes . ' minutes'));
        } elseif ($remindType === 'overdue') {
            $sql .= ' AND due_at <= :overdue_at';
            $params['overdue_at'] = gmdate('Y-m-d H:i:s', strtotime($now . ' -' . $offsetMinutes . ' minutes'));
        } else {
            return [];
        }

        $sql .= ' ORDER BY due_at ASC, id ASC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $activity
     * @return array<string,mixed>|null
     */
    private static function buildNotificationPayload(array $activity, string $remindType): ?array
    {
        $activityId = (int) ($activity['id'] ?? 0);
        $ownerUserId = (int) ($activity['owner_user_id'] ?? 0);
        if ($activityId <= 0 || $ownerUserId <= 0) {
            return null;
        }

        $subject = trim((string) ($activity['subject'] ?? ''));
        if ($subject === '') {
            $subject = '未命名任务';
        }
        $titlePrefix = $remindType === 'schedule'
            ? '任务即将到期'
            : ($remindType === 'due' ? '任务到期提醒' : '任务已逾期');
        $title = $titlePrefix . '：' . $subject;
        $content = '任务 #' . $activityId . ' 截止时间：' . (string) ($activity['due_at'] ?? '-');

        return [
            'id' => 0,
            'user_id' => $ownerUserId,
            'activity_id' => $activityId,
            'entity_type' => 'activity',
            'entity_id' => $activityId,
            'reminder_type' => $remindType,
            'title' => $title,
            'content' => $content,
            'due_at' => $activity['due_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $notification
     */
    private static function insertNotification(PDO $pdo, array $notification, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO qiling_crm_notifications
             (user_id, activity_id, entity_type, entity_id, reminder_type, title, content, due_at, status, sent_at, read_at, created_at, updated_at)
             VALUES
             (:user_id, :activity_id, :entity_type, :entity_id, :reminder_type, :title, :content, :due_at, :status, :sent_at, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'user_id' => (int) ($notification['user_id'] ?? 0),
            'activity_id' => (int) ($notification['activity_id'] ?? 0),
            'entity_type' => (string) ($notification['entity_type'] ?? 'activity'),
            'entity_id' => (int) ($notification['entity_id'] ?? 0),
            'reminder_type' => (string) ($notification['reminder_type'] ?? ''),
            'title' => (string) ($notification['title'] ?? ''),
            'content' => (string) ($notification['content'] ?? ''),
            'due_at' => $notification['due_at'] ?? null,
            'status' => 'unread',
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ((int) $stmt->rowCount() <= 0) {
            return 0;
        }
        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadPushSettings(PDO $pdo): array
    {
        $settings = SystemSettingService::all($pdo);
        $channelIds = self::normalizeChannelIds(CrmSupport::decodeJsonArray($settings['crm_reminder_push_channel_ids'] ?? '[]'));
        $types = self::normalizeReminderTypes(CrmSupport::decodeJsonArray($settings['crm_reminder_push_types'] ?? '[]'));
        if ($types === []) {
            $types = self::REMINDER_TYPES;
        }

        $titlePrefix = trim((string) ($settings['crm_reminder_push_title_prefix'] ?? ''));
        if ($titlePrefix === '') {
            $titlePrefix = '【启灵CRM提醒】';
        }

        $template = trim((string) ($settings['crm_reminder_push_template'] ?? ''));
        if ($template === '') {
            $template = self::PUSH_TEMPLATE_FALLBACK;
        }

        $maxPerRun = (int) ($settings['crm_reminder_push_max_per_run'] ?? '50');
        $maxPerRun = max(1, min($maxPerRun, 500));

        return [
            'enabled' => (($settings['crm_reminder_push_enabled'] ?? '0') === '1') ? 1 : 0,
            'channel_ids' => $channelIds,
            'reminder_types' => $types,
            'title_prefix' => $titlePrefix,
            'template' => $template,
            'max_per_run' => $maxPerRun,
            'only_created' => (($settings['crm_reminder_push_only_created'] ?? '1') === '1') ? 1 : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyPushSummary(array $settings, int $candidateCount): array
    {
        return [
            'enabled' => (int) ($settings['enabled'] ?? 0),
            'candidate_count' => $candidateCount,
            'attempted' => 0,
            'notification_sent' => 0,
            'notification_failed' => 0,
            'channel_sent' => 0,
            'channel_failed' => 0,
            'message' => '',
            'details' => [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $pushCandidates
     * @return array<string,mixed>
     */
    private static function dispatchReminderPush(PDO $pdo, array $settings, array $pushCandidates, string $generatedAt): array
    {
        $summary = self::emptyPushSummary($settings, count($pushCandidates));
        if ((int) ($settings['enabled'] ?? 0) !== 1 || $pushCandidates === []) {
            return $summary;
        }

        $allowedTypes = self::normalizeReminderTypes($settings['reminder_types'] ?? []);
        if ($allowedTypes === []) {
            $allowedTypes = self::REMINDER_TYPES;
        }

        $filtered = array_values(array_filter($pushCandidates, static function (array $candidate) use ($allowedTypes): bool {
            $type = (string) ($candidate['reminder_type'] ?? '');
            return in_array($type, $allowedTypes, true);
        }));

        $maxPerRun = max(1, min((int) ($settings['max_per_run'] ?? 50), 500));
        $dispatchRows = array_slice($filtered, 0, $maxPerRun);
        $summary['attempted'] = count($dispatchRows);
        if ($dispatchRows === []) {
            $summary['message'] = 'no matched reminder type to push';
            return $summary;
        }

        $channelIds = self::normalizeChannelIds($settings['channel_ids'] ?? []);
        $details = [];

        foreach ($dispatchRows as $row) {
            $message = self::buildPushMessage($settings, $row, $generatedAt);
            try {
                $result = PushService::sendText(
                    $pdo,
                    $message,
                    $channelIds === [] ? null : $channelIds,
                    'crm_reminder_push',
                    (int) ($row['activity_id'] ?? 0),
                    [
                        'crm_notification_id' => (int) ($row['id'] ?? 0),
                        'crm_reminder_type' => (string) ($row['reminder_type'] ?? ''),
                    ]
                );
            } catch (\RuntimeException $e) {
                $summary['message'] = $e->getMessage();
                break;
            } catch (\Throwable $e) {
                $summary['notification_failed'] = (int) $summary['notification_failed'] + 1;
                $details[] = [
                    'notification_id' => (int) ($row['id'] ?? 0),
                    'activity_id' => (int) ($row['activity_id'] ?? 0),
                    'ok' => false,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ];
                continue;
            }

            $sentChannels = (int) ($result['sent'] ?? 0);
            $failedChannels = (int) ($result['failed'] ?? 0);
            $summary['channel_sent'] = (int) $summary['channel_sent'] + $sentChannels;
            $summary['channel_failed'] = (int) $summary['channel_failed'] + $failedChannels;

            if ($sentChannels > 0) {
                $summary['notification_sent'] = (int) $summary['notification_sent'] + 1;
            } else {
                $summary['notification_failed'] = (int) $summary['notification_failed'] + 1;
            }

            $details[] = [
                'notification_id' => (int) ($row['id'] ?? 0),
                'activity_id' => (int) ($row['activity_id'] ?? 0),
                'reminder_type' => (string) ($row['reminder_type'] ?? ''),
                'sent' => $sentChannels,
                'failed' => $failedChannels,
            ];
        }

        if (count($details) > 30) {
            $details = array_slice($details, 0, 30);
        }
        $summary['details'] = $details;
        return $summary;
    }

    /**
     * @param array<string,mixed> $notification
     */
    private static function buildPushMessage(array $settings, array $notification, string $generatedAt): string
    {
        $title = trim((string) ($notification['title'] ?? ''));
        $content = trim((string) ($notification['content'] ?? ''));
        $dueAt = trim((string) ($notification['due_at'] ?? ''));
        $reminderType = trim((string) ($notification['reminder_type'] ?? ''));
        $prefix = trim((string) ($settings['title_prefix'] ?? ''));
        $template = (string) ($settings['template'] ?? self::PUSH_TEMPLATE_FALLBACK);

        $map = [
            '{prefix}' => $prefix,
            '{title}' => ($title !== '' ? $title : '-'),
            '{content}' => ($content !== '' ? $content : '-'),
            '{due_at}' => ($dueAt !== '' ? $dueAt : '-'),
            '{reminder_type}' => $reminderType,
            '{reminder_type_label}' => self::reminderTypeLabel($reminderType),
            '{activity_id}' => (string) ((int) ($notification['activity_id'] ?? 0)),
            '{notification_id}' => (string) ((int) ($notification['id'] ?? 0)),
            '{user_id}' => (string) ((int) ($notification['user_id'] ?? 0)),
            '{generated_at}' => $generatedAt,
        ];

        $message = str_replace(array_keys($map), array_values($map), $template);
        $message = trim($message);
        if ($message === '') {
            $message = self::PUSH_TEMPLATE_FALLBACK;
            $message = str_replace(array_keys($map), array_values($map), $message);
            $message = trim($message);
        }

        if ($prefix !== '' && strpos($message, $prefix) !== 0) {
            $message = $prefix . "\n" . $message;
        }

        return $message;
    }

    /**
     * @param mixed $raw
     * @return array<int,int>
     */
    private static function normalizeChannelIds(mixed $raw): array
    {
        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\s,，]+/', trim($raw));
            if (is_array($parts)) {
                $items = $parts;
            }
        } elseif (is_numeric($raw)) {
            $items = [$raw];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_numeric($item)) {
                continue;
            }
            $id = (int) $item;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /**
     * @param mixed $raw
     * @return array<int,string>
     */
    private static function normalizeReminderTypes(mixed $raw): array
    {
        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\s,，]+/', trim($raw));
            if (is_array($parts)) {
                $items = $parts;
            }
        }

        $out = [];
        foreach ($items as $item) {
            $value = strtolower(trim((string) $item));
            if ($value === '' || !in_array($value, self::REMINDER_TYPES, true)) {
                continue;
            }
            $out[$value] = $value;
        }
        return array_values($out);
    }

    private static function reminderTypeLabel(string $reminderType): string
    {
        if ($reminderType === 'schedule') {
            return '日程提醒';
        }
        if ($reminderType === 'due') {
            return '到期提醒';
        }
        if ($reminderType === 'overdue') {
            return '逾期提醒';
        }
        return $reminderType;
    }

    /**
     * @param array<int,int> $ids
     */
    private static function buildIdSql(string $prefix, array $ids, array &$params): string
    {
        $parts = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . '_' . $index;
            $parts[] = ':' . $key;
            $params[$key] = $id;
        }
        return $parts !== [] ? implode(',', $parts) : 'NULL';
    }
}
