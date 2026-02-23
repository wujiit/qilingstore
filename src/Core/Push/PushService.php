<?php

declare(strict_types=1);

namespace Qiling\Core\Push;

use PDO;

final class PushService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listChannels(PDO $pdo): array
    {
        $sql = 'SELECT id, channel_code, channel_name, provider, webhook_url, keyword, security_mode, enabled, created_at, updated_at,
                       CASE WHEN secret <> \'\' THEN 1 ELSE 0 END AS has_secret
                FROM qiling_push_channels
                ORDER BY id DESC';

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function upsertChannel(PDO $pdo, array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $channelCode = strtoupper(trim((string) ($payload['channel_code'] ?? '')));
        $channelName = trim((string) ($payload['channel_name'] ?? ''));
        $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
        $webhookUrl = trim((string) ($payload['webhook_url'] ?? ''));
        $keyword = trim((string) ($payload['keyword'] ?? ''));
        $securityMode = self::sanitizeSecurityMode((string) ($payload['security_mode'] ?? 'auto'));
        $enabled = (int) ($payload['enabled'] ?? 1) === 1 ? 1 : 0;

        $secretProvided = (bool) ($payload['secret_provided'] ?? false);
        $secret = trim((string) ($payload['secret'] ?? ''));

        if ($channelName === '' || $provider === '' || $webhookUrl === '') {
            throw new \RuntimeException('channel_name, provider, webhook_url are required');
        }

        if (!in_array($provider, ['dingtalk', 'feishu'], true)) {
            throw new \RuntimeException('provider must be dingtalk or feishu');
        }

        $exists = null;
        if ($id > 0) {
            $exists = self::findChannel($pdo, $id, '');
            if (!is_array($exists)) {
                throw new \RuntimeException('channel not found');
            }
        } else {
            $exists = self::findChannel($pdo, 0, $channelCode);
        }

        if ($channelCode === '') {
            if (is_array($exists) && !empty($exists['channel_code'])) {
                $channelCode = (string) $exists['channel_code'];
            } else {
                $channelCode = 'QLPCH' . gmdate('ymdHis') . random_int(100, 999);
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        if (!is_array($exists)) {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_push_channels
                 (channel_code, channel_name, provider, webhook_url, secret, keyword, security_mode, enabled, created_at, updated_at)
                 VALUES
                 (:channel_code, :channel_name, :provider, :webhook_url, :secret, :keyword, :security_mode, :enabled, :created_at, :updated_at)'
            );
            $stmt->execute([
                'channel_code' => $channelCode,
                'channel_name' => $channelName,
                'provider' => $provider,
                'webhook_url' => $webhookUrl,
                'secret' => $secret,
                'keyword' => $keyword,
                'security_mode' => $securityMode,
                'enabled' => $enabled,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'id' => (int) $pdo->lastInsertId(),
                'channel_code' => $channelCode,
                'created' => true,
            ];
        }

        $channelId = (int) ($exists['id'] ?? 0);
        $newSecret = $secretProvided ? $secret : (string) ($exists['secret'] ?? '');

        $stmt = $pdo->prepare(
            'UPDATE qiling_push_channels
             SET channel_code = :channel_code,
                 channel_name = :channel_name,
                 provider = :provider,
                 webhook_url = :webhook_url,
                 secret = :secret,
                 keyword = :keyword,
                 security_mode = :security_mode,
                 enabled = :enabled,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'channel_code' => $channelCode,
            'channel_name' => $channelName,
            'provider' => $provider,
            'webhook_url' => $webhookUrl,
            'secret' => $newSecret,
            'keyword' => $keyword,
            'security_mode' => $securityMode,
            'enabled' => $enabled,
            'updated_at' => $now,
            'id' => $channelId,
        ]);

        return [
            'id' => $channelId,
            'channel_code' => $channelCode,
            'created' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sendTest(PDO $pdo, int $channelId, string $message): array
    {
        $channel = self::findEnabledChannel($pdo, $channelId);
        if (!is_array($channel)) {
            throw new \RuntimeException('enabled channel not found');
        }

        if ($message === '') {
            $message = '启灵医美养生门店系统测试消息 ' . gmdate('Y-m-d H:i:s');
        }

        $provider = self::provider((string) $channel['provider']);
        if (!$provider instanceof PushProviderInterface) {
            throw new \RuntimeException('push provider not supported');
        }

        $result = $provider->send($channel, $message, ['type' => 'text']);

        $logId = self::writeLog($pdo, [
            'channel_id' => (int) $channel['id'],
            'provider' => (string) $channel['provider'],
            'status' => (bool) ($result['ok'] ?? false) ? 'success' : 'failed',
            'response_code' => (int) ($result['status_code'] ?? 0),
            'request_payload' => $result['request_payload'] ?? [],
            'response_body' => (string) ($result['body'] ?? ''),
            'error_message' => (string) ($result['error'] ?? ''),
            'trigger_source' => 'manual_test',
            'task_id' => null,
        ]);

        return [
            'channel_id' => (int) $channel['id'],
            'channel_code' => (string) $channel['channel_code'],
            'provider' => (string) $channel['provider'],
            'ok' => (bool) ($result['ok'] ?? false),
            'error' => (string) ($result['error'] ?? ''),
            'status_code' => (int) ($result['status_code'] ?? 0),
            'body' => (string) ($result['body'] ?? ''),
            'log_id' => $logId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function notifyAppointmentCreated(PDO $pdo, int $appointmentId, string $triggerSource = 'appointment_created'): array
    {
        if ($appointmentId <= 0) {
            throw new \RuntimeException('appointment_id is required');
        }

        $appointment = self::findAppointment($pdo, $appointmentId);
        if (!is_array($appointment)) {
            throw new \RuntimeException('appointment not found');
        }

        $channels = self::listEnabledChannels($pdo);
        if ($channels === []) {
            return [
                'appointment' => [
                    'id' => (int) ($appointment['id'] ?? 0),
                    'appointment_no' => (string) ($appointment['appointment_no'] ?? ''),
                ],
                'channels' => 0,
                'sent' => 0,
                'failed' => 0,
                'details' => [],
                'message' => 'no enabled push channel',
            ];
        }

        $message = self::buildAppointmentCreatedMessage($appointment);
        $source = self::sanitizeTriggerSource($triggerSource, 'appointment_created');

        $sent = 0;
        $failed = 0;
        $details = [];

        foreach ($channels as $channel) {
            $provider = self::provider((string) ($channel['provider'] ?? ''));
            $ok = false;
            $error = '';
            $responseCode = 0;
            $body = '';
            $result = [];

            if ($provider instanceof PushProviderInterface) {
                try {
                    $result = $provider->send($channel, $message, [
                        'type' => 'text',
                        'appointment_id' => $appointmentId,
                    ]);
                    $ok = (bool) ($result['ok'] ?? false);
                    $error = self::truncate((string) ($result['error'] ?? ''), 500);
                    $responseCode = (int) ($result['status_code'] ?? 0);
                    $body = (string) ($result['body'] ?? '');
                } catch (\Throwable $e) {
                    $ok = false;
                    $error = self::truncate($e->getMessage(), 500);
                }
            } else {
                $ok = false;
                $error = 'push provider not supported';
            }

            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }

            $logId = self::writeLog($pdo, [
                'channel_id' => (int) ($channel['id'] ?? 0),
                'provider' => (string) ($channel['provider'] ?? ''),
                'status' => $ok ? 'success' : 'failed',
                'response_code' => $responseCode,
                'request_payload' => $result['request_payload'] ?? [],
                'response_body' => $body,
                'error_message' => $error,
                'trigger_source' => $source,
                'task_id' => $appointmentId,
            ]);

            $details[] = [
                'channel_id' => (int) ($channel['id'] ?? 0),
                'channel_code' => (string) ($channel['channel_code'] ?? ''),
                'channel_name' => (string) ($channel['channel_name'] ?? ''),
                'provider' => (string) ($channel['provider'] ?? ''),
                'ok' => $ok,
                'error' => $error,
                'status_code' => $responseCode,
                'log_id' => $logId,
            ];
        }

        return [
            'appointment' => [
                'id' => (int) ($appointment['id'] ?? 0),
                'appointment_no' => (string) ($appointment['appointment_no'] ?? ''),
            ],
            'channels' => count($channels),
            'sent' => $sent,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function notifyDueFollowupTasks(
        PDO $pdo,
        ?array $channelIds,
        ?int $storeId,
        int $limit,
        bool $retryFailed = false
    ): array {
        $limit = max(1, min($limit, 500));
        $channels = self::resolveNotifyChannels($pdo, $channelIds);
        if ($channels === []) {
            throw new \RuntimeException('no enabled push channel');
        }

        $now = gmdate('Y-m-d H:i:s');
        $sql = 'SELECT t.id, t.appointment_id, t.customer_id, t.store_id, t.schedule_day, t.due_at, t.title, t.content,
                       t.notify_status, c.name AS customer_name, c.mobile AS customer_mobile,
                       a.appointment_no, s.store_name
                FROM qiling_followup_tasks t
                INNER JOIN qiling_customers c ON c.id = t.customer_id
                INNER JOIN qiling_appointments a ON a.id = t.appointment_id
                LEFT JOIN qiling_stores s ON s.id = t.store_id
                WHERE t.status = :status_pending
                  AND t.due_at <= :now';

        $params = [
            'status_pending' => 'pending',
            'now' => $now,
        ];

        if ($retryFailed) {
            $sql .= ' AND (t.notify_status = :notify_pending OR t.notify_status = :notify_failed OR t.notify_status = :notify_sending)';
            $params['notify_pending'] = 'pending';
            $params['notify_failed'] = 'failed';
            $params['notify_sending'] = 'sending';
        } else {
            $sql .= ' AND t.notify_status = :notify_pending';
            $params['notify_pending'] = 'pending';
        }

        if ($storeId !== null && $storeId > 0) {
            $sql .= ' AND t.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' ORDER BY t.due_at ASC, t.id ASC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $details = [];

        foreach ($tasks as $task) {
            $taskId = (int) ($task['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            if (!self::claimTaskForNotify($pdo, $taskId, $retryFailed)) {
                $skipped++;
                continue;
            }

            $message = self::buildFollowupMessage($task);
            $taskOk = false;
            $taskErrors = [];
            $taskChannelDetails = [];
            $sentChannelId = 0;

            foreach ($channels as $channel) {
                $provider = self::provider((string) ($channel['provider'] ?? ''));
                $result = [];
                $ok = false;
                $error = '';
                $responseCode = 0;
                $body = '';

                if ($provider instanceof PushProviderInterface) {
                    try {
                        $result = $provider->send($channel, $message, ['type' => 'text', 'task_id' => $taskId]);
                        $ok = (bool) ($result['ok'] ?? false);
                        $error = self::truncate((string) ($result['error'] ?? ''), 500);
                        $responseCode = (int) ($result['status_code'] ?? 0);
                        $body = (string) ($result['body'] ?? '');
                    } catch (\Throwable $e) {
                        $ok = false;
                        $error = self::truncate($e->getMessage(), 500);
                    }
                } else {
                    $ok = false;
                    $error = 'push provider not supported';
                }

                if ($ok) {
                    $taskOk = true;
                    $sentChannelId = (int) ($channel['id'] ?? 0);
                } else {
                    $label = (string) ($channel['channel_name'] ?? ('#' . (int) ($channel['id'] ?? 0)));
                    $taskErrors[] = $label . ': ' . ($error !== '' ? $error : 'send failed');
                }

                $logId = self::writeLog($pdo, [
                    'channel_id' => (int) ($channel['id'] ?? 0),
                    'provider' => (string) ($channel['provider'] ?? ''),
                    'status' => $ok ? 'success' : 'failed',
                    'response_code' => $responseCode,
                    'request_payload' => $result['request_payload'] ?? [],
                    'response_body' => $body,
                    'error_message' => $error,
                    'trigger_source' => 'followup_due',
                    'task_id' => $taskId,
                ]);

                $taskChannelDetails[] = [
                    'channel_id' => (int) ($channel['id'] ?? 0),
                    'channel_code' => (string) ($channel['channel_code'] ?? ''),
                    'channel_name' => (string) ($channel['channel_name'] ?? ''),
                    'provider' => (string) ($channel['provider'] ?? ''),
                    'ok' => $ok,
                    'error' => $error,
                    'status_code' => $responseCode,
                    'log_id' => $logId,
                ];
            }

            self::updateTaskNotifyStatus(
                $pdo,
                $taskId,
                $taskOk ? 'sent' : 'failed',
                $sentChannelId,
                $taskOk ? '' : implode(' | ', $taskErrors)
            );

            if ($taskOk) {
                $sent++;
            } else {
                $failed++;
            }

            $details[] = [
                'task_id' => $taskId,
                'appointment_no' => (string) ($task['appointment_no'] ?? ''),
                'customer_name' => (string) ($task['customer_name'] ?? ''),
                'ok' => $taskOk,
                'error' => $taskOk ? '' : implode(' | ', $taskErrors),
                'channels' => $taskChannelDetails,
            ];
        }

        $channelSummary = array_map(
            static fn (array $channel): array => [
                'id' => (int) ($channel['id'] ?? 0),
                'channel_code' => (string) ($channel['channel_code'] ?? ''),
                'channel_name' => (string) ($channel['channel_name'] ?? ''),
                'provider' => (string) ($channel['provider'] ?? ''),
            ],
            $channels
        );

        return [
            'channel' => isset($channelSummary[0]) ? $channelSummary[0] : null,
            'channel_ids' => array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $channelSummary)),
            'channels' => $channelSummary,
            'tasks' => count($tasks),
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'details' => $details,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listLogs(PDO $pdo, ?int $channelId, string $status, int $limit): array
    {
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT l.*, c.channel_code, c.channel_name
                FROM qiling_push_logs l
                LEFT JOIN qiling_push_channels c ON c.id = l.channel_id
                WHERE 1 = 1';
        $params = [];

        if ($channelId !== null && $channelId > 0) {
            $sql .= ' AND l.channel_id = :channel_id';
            $params['channel_id'] = $channelId;
        }

        if ($status !== '') {
            $sql .= ' AND l.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY l.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findEnabledChannel(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_push_channels
             WHERE id = :id
               AND enabled = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function firstEnabledChannel(PDO $pdo): ?array
    {
        $stmt = $pdo->query(
            'SELECT *
             FROM qiling_push_channels
             WHERE enabled = 1
             ORDER BY id ASC
             LIMIT 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listEnabledChannels(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT *
             FROM qiling_push_channels
             WHERE enabled = 1
             ORDER BY id ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findChannel(PDO $pdo, int $id, string $channelCode): ?array
    {
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM qiling_push_channels WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        if ($channelCode !== '') {
            $stmt = $pdo->prepare('SELECT * FROM qiling_push_channels WHERE channel_code = :channel_code LIMIT 1');
            $stmt->execute(['channel_code' => $channelCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        return null;
    }

    /**
     * @return PushProviderInterface|null
     */
    private static function provider(string $provider)
    {
        if ($provider === 'dingtalk') {
            return new DingTalkProvider();
        }

        if ($provider === 'feishu') {
            return new FeishuProvider();
        }

        return null;
    }

    private static function sanitizeSecurityMode(string $mode): string
    {
        $mode = trim(strtolower($mode));
        if (!in_array($mode, ['auto', 'sign', 'keyword', 'ip'], true)) {
            return 'auto';
        }

        return $mode;
    }

    /**
     * @param array<string, mixed> $task
     */
    private static function buildFollowupMessage(array $task): string
    {
        $storeName = trim((string) ($task['store_name'] ?? ''));
        $customerName = trim((string) ($task['customer_name'] ?? ''));
        $customerMobile = trim((string) ($task['customer_mobile'] ?? ''));
        $appointmentNo = trim((string) ($task['appointment_no'] ?? ''));
        $dueAt = trim((string) ($task['due_at'] ?? ''));
        $title = trim((string) ($task['title'] ?? ''));
        $content = trim((string) ($task['content'] ?? ''));

        $lines = [
            '【启灵医美养生门店】回访提醒',
            '门店：' . ($storeName !== '' ? $storeName : '-'),
            '客户：' . ($customerName !== '' ? $customerName : '-') . ($customerMobile !== '' ? ' ' . $customerMobile : ''),
            '任务：' . ($title !== '' ? $title : '回访任务'),
            '到期：' . ($dueAt !== '' ? $dueAt : '-'),
            '预约号：' . ($appointmentNo !== '' ? $appointmentNo : '-'),
        ];

        if ($content !== '') {
            $lines[] = '说明：' . $content;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findAppointment(PDO $pdo, int $appointmentId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.appointment_no, a.start_at, a.end_at, a.source_channel, a.notes,
                    c.name AS customer_name, c.mobile AS customer_mobile,
                    s.store_name, sv.service_name,
                    st.staff_no, u.username AS staff_username
             FROM qiling_appointments a
             INNER JOIN qiling_customers c ON c.id = a.customer_id
             LEFT JOIN qiling_stores s ON s.id = a.store_id
             LEFT JOIN qiling_services sv ON sv.id = a.service_id
             LEFT JOIN qiling_staff st ON st.id = a.staff_id
             LEFT JOIN qiling_users u ON u.id = st.user_id
             WHERE a.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private static function buildAppointmentCreatedMessage(array $appointment): string
    {
        $storeName = trim((string) ($appointment['store_name'] ?? ''));
        $customerName = trim((string) ($appointment['customer_name'] ?? ''));
        $customerMobile = trim((string) ($appointment['customer_mobile'] ?? ''));
        $serviceName = trim((string) ($appointment['service_name'] ?? ''));
        $appointmentNo = trim((string) ($appointment['appointment_no'] ?? ''));
        $startAt = trim((string) ($appointment['start_at'] ?? ''));
        $endAt = trim((string) ($appointment['end_at'] ?? ''));
        $sourceChannel = trim((string) ($appointment['source_channel'] ?? ''));
        $notes = trim((string) ($appointment['notes'] ?? ''));
        $staffNo = trim((string) ($appointment['staff_no'] ?? ''));
        $staffUsername = trim((string) ($appointment['staff_username'] ?? ''));

        $staffDisplay = '-';
        if ($staffUsername !== '' || $staffNo !== '') {
            $staffDisplay = trim($staffUsername . ($staffNo !== '' ? ' [' . $staffNo . ']' : ''));
        }

        $lines = [
            '【启灵医美养生门店】新预约提醒',
            '门店：' . ($storeName !== '' ? $storeName : '-'),
            '客户：' . ($customerName !== '' ? $customerName : '-') . ($customerMobile !== '' ? ' ' . $customerMobile : ''),
            '项目：' . ($serviceName !== '' ? $serviceName : '-'),
            '时间：' . ($startAt !== '' ? $startAt : '-') . ($endAt !== '' ? ' ~ ' . $endAt : ''),
            '预约号：' . ($appointmentNo !== '' ? $appointmentNo : '-'),
            '来源：' . self::appointmentSourceLabel($sourceChannel),
            '服务员工：' . $staffDisplay,
        ];

        if ($notes !== '') {
            $lines[] = '备注：' . $notes;
        }

        return implode("\n", $lines);
    }

    private static function sanitizeTriggerSource(string $source, string $fallback): string
    {
        $value = trim($source);
        if ($value === '') {
            $value = $fallback;
        }

        return self::truncate($value, 50);
    }

    private static function appointmentSourceLabel(string $sourceChannel): string
    {
        $value = trim($sourceChannel);
        if ($value === '') {
            return '-';
        }

        $map = [
            'customer_portal' => '用户端在线预约',
            'mobile' => '员工移动端',
            'admin' => '后台手工',
        ];

        return $map[$value] ?? $value;
    }

    private static function claimTaskForNotify(PDO $pdo, int $taskId, bool $retryFailed): bool
    {
        $notifyStatuses = $retryFailed ? ['pending', 'failed', 'sending'] : ['pending'];
        $placeholders = implode(',', array_fill(0, count($notifyStatuses), '?'));
        $sql = 'UPDATE qiling_followup_tasks
                SET notify_status = ?, updated_at = ?
                WHERE id = ?
                  AND status = ?
                  AND notify_status IN (' . $placeholders . ')';

        $params = [
            'sending',
            gmdate('Y-m-d H:i:s'),
            $taskId,
            'pending',
        ];
        foreach ($notifyStatuses as $item) {
            $params[] = $item;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<int, int>|null $channelIds
     * @return array<int, array<string, mixed>>
     */
    private static function resolveNotifyChannels(PDO $pdo, ?array $channelIds): array
    {
        if ($channelIds === null || $channelIds === []) {
            return self::listEnabledChannels($pdo);
        }

        $normalized = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $channelIds)));
        $normalized = array_values(array_filter($normalized, static fn (int $id): bool => $id > 0));
        if ($normalized === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_push_channels
             WHERE enabled = 1
               AND id IN (' . $placeholders . ')
             ORDER BY id ASC'
        );
        $stmt->execute($normalized);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function updateTaskNotifyStatus(PDO $pdo, int $taskId, string $notifyStatus, int $channelId, string $error): void
    {
        $stmt = $pdo->prepare(
            'UPDATE qiling_followup_tasks
             SET notify_status = :notify_status,
                 notified_at = :notified_at,
                 notify_channel_id = :notify_channel_id,
                 notify_error = :notify_error,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'notify_status' => $notifyStatus,
            'notified_at' => $notifyStatus === 'sent' ? gmdate('Y-m-d H:i:s') : null,
            'notify_channel_id' => $channelId > 0 ? $channelId : null,
            'notify_error' => $notifyStatus === 'sent' ? '' : self::truncate($error, 500),
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $taskId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeLog(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_push_logs
             (channel_id, provider, status, response_code, request_payload, response_body, error_message, trigger_source, task_id, created_at)
             VALUES
             (:channel_id, :provider, :status, :response_code, :request_payload, :response_body, :error_message, :trigger_source, :task_id, :created_at)'
        );

        $stmt->execute([
            'channel_id' => (int) ($data['channel_id'] ?? 0),
            'provider' => (string) ($data['provider'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'response_code' => (int) ($data['response_code'] ?? 0),
            'request_payload' => json_encode($data['request_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_body' => self::truncate((string) ($data['response_body'] ?? ''), 60000),
            'error_message' => self::truncate((string) ($data['error_message'] ?? ''), 1000),
            'trigger_source' => (string) ($data['trigger_source'] ?? ''),
            'task_id' => isset($data['task_id']) && is_numeric((string) $data['task_id']) ? (int) $data['task_id'] : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}
