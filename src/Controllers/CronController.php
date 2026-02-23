<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Audit;
use Qiling\Core\Config;
use Qiling\Core\Database;
use Qiling\Core\FollowupService;
use Qiling\Core\Push\PushService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CronController
{
    public static function followupGenerate(): void
    {
        self::requireCronKey();

        $input = self::input();
        $storeId = Request::int($input, 'store_id', 0);
        $limit = Request::int($input, 'limit', 200);

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $result = FollowupService::generateForCompletedAppointments(
                $pdo,
                $limit,
                $storeId > 0 ? $storeId : null
            );
            Audit::log(0, 'cron.followup.generate', 'followup', 0, 'Cron generate followup tasks', [
                'store_id' => $storeId > 0 ? $storeId : null,
                'limit' => $limit,
                'appointments' => (int) ($result['appointments'] ?? 0),
                'generated' => (int) ($result['generated'] ?? 0),
                'reactivated' => (int) ($result['reactivated'] ?? 0),
            ]);

            $pdo->commit();
            Response::json([
                'ok' => true,
                'job' => 'followup_generate',
                'store_id' => $storeId > 0 ? $storeId : null,
                'limit' => $limit,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('cron followup generate failed', $e);
        }
    }

    public static function followupNotify(): void
    {
        self::requireCronKey();

        $input = self::input();
        $channelIdsMeta = self::parseChannelIds($input);
        $channelIds = $channelIdsMeta['ids'];
        if ($channelIdsMeta['provided'] && $channelIds === []) {
            Response::json(['message' => 'channel_ids invalid'], 422);
            return;
        }
        $storeId = Request::int($input, 'store_id', 0);
        $limit = Request::int($input, 'limit', 100);
        $retryFailed = Request::int($input, 'retry_failed', 0) === 1;

        try {
            $result = PushService::notifyDueFollowupTasks(
                Database::pdo(),
                $channelIds === [] ? null : $channelIds,
                $storeId > 0 ? $storeId : null,
                $limit,
                $retryFailed
            );

            Audit::log(0, 'cron.followup.notify', 'followup_task', 0, 'Cron notify due followup tasks', [
                'channel_id' => count($channelIds) === 1 ? $channelIds[0] : null,
                'channel_ids' => $channelIds === [] ? null : $channelIds,
                'store_id' => $storeId > 0 ? $storeId : null,
                'limit' => $limit,
                'retry_failed' => $retryFailed ? 1 : 0,
                'tasks' => (int) ($result['tasks'] ?? 0),
                'sent' => (int) ($result['sent'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
            ]);

            $status = ((int) ($result['failed'] ?? 0) > 0) ? 207 : 200;
            Response::json([
                'ok' => true,
                'job' => 'followup_notify',
                'channel_id' => count($channelIds) === 1 ? $channelIds[0] : null,
                'channel_ids' => $channelIds === [] ? null : $channelIds,
                'store_id' => $storeId > 0 ? $storeId : null,
                'limit' => $limit,
                'retry_failed' => $retryFailed,
                'result' => $result,
            ], $status);
        } catch (\RuntimeException $e) {
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::serverError('cron followup notify failed', $e);
        }
    }

    public static function followupRun(): void
    {
        self::requireCronKey();

        $input = self::input();
        $storeId = Request::int($input, 'store_id', 0);
        $generateLimit = Request::int($input, 'generate_limit', 200);
        $notifyLimit = Request::int($input, 'notify_limit', 100);
        $channelIdsMeta = self::parseChannelIds($input);
        $channelIds = $channelIdsMeta['ids'];
        if ($channelIdsMeta['provided'] && $channelIds === []) {
            Response::json(['message' => 'channel_ids invalid'], 422);
            return;
        }
        $retryFailed = Request::int($input, 'retry_failed', 0) === 1;

        $generatePdo = Database::pdo();
        $generatePdo->beginTransaction();

        try {
            $generateResult = FollowupService::generateForCompletedAppointments(
                $generatePdo,
                $generateLimit,
                $storeId > 0 ? $storeId : null
            );
            $generatePdo->commit();
        } catch (\Throwable $e) {
            if ($generatePdo->inTransaction()) {
                $generatePdo->rollBack();
            }
            Response::serverError('cron followup run generate phase failed', $e);
            return;
        }

        try {
            $notifyResult = PushService::notifyDueFollowupTasks(
                Database::pdo(),
                $channelIds === [] ? null : $channelIds,
                $storeId > 0 ? $storeId : null,
                $notifyLimit,
                $retryFailed
            );
        } catch (\Throwable $e) {
            Response::serverError('cron followup run notify phase failed', $e);
            return;
        }

        Audit::log(0, 'cron.followup.run', 'followup', 0, 'Cron run followup generate + notify', [
            'store_id' => $storeId > 0 ? $storeId : null,
            'generate_limit' => $generateLimit,
            'notify_limit' => $notifyLimit,
            'channel_id' => count($channelIds) === 1 ? $channelIds[0] : null,
            'channel_ids' => $channelIds === [] ? null : $channelIds,
            'retry_failed' => $retryFailed ? 1 : 0,
            'generated' => (int) ($generateResult['generated'] ?? 0),
            'reactivated' => (int) ($generateResult['reactivated'] ?? 0),
            'notify_sent' => (int) ($notifyResult['sent'] ?? 0),
            'notify_failed' => (int) ($notifyResult['failed'] ?? 0),
        ]);

        $status = ((int) ($notifyResult['failed'] ?? 0) > 0) ? 207 : 200;
        Response::json([
            'ok' => true,
            'job' => 'followup_run',
            'store_id' => $storeId > 0 ? $storeId : null,
            'generate_limit' => $generateLimit,
            'notify_limit' => $notifyLimit,
            'channel_id' => count($channelIds) === 1 ? $channelIds[0] : null,
            'channel_ids' => $channelIds === [] ? null : $channelIds,
            'retry_failed' => $retryFailed,
            'generate_result' => $generateResult,
            'notify_result' => $notifyResult,
        ], $status);
    }

    /** @return array<string, mixed> */
    private static function input(): array
    {
        $query = is_array($_GET) ? $_GET : [];
        return array_merge($query, Request::jsonBody());
    }

    private static function requireCronKey(): void
    {
        $expect = (string) Config::get('CRON_SHARED_KEY', '');
        if ($expect === '') {
            Response::json(['message' => 'CRON_SHARED_KEY is missing'], 500);
            exit;
        }

        $header = $_SERVER['HTTP_X_QILING_CRON_KEY'] ?? '';
        $provided = is_string($header) ? trim($header) : '';

        $allowQuery = in_array(
            strtolower(trim((string) Config::get('CRON_ALLOW_QUERY_KEY', 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        if ($provided === '' && $allowQuery && isset($_GET['key']) && is_string($_GET['key'])) {
            $provided = trim($_GET['key']);
        }

        if ($provided === '' || !hash_equals($expect, $provided)) {
            Response::json(['message' => 'Forbidden'], 403);
            exit;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ids:array<int, int>,provided:bool}
     */
    private static function parseChannelIds(array $input): array
    {
        $provided = false;
        $ids = [];

        if (array_key_exists('channel_ids', $input)) {
            $provided = true;
            $raw = $input['channel_ids'];
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    if (is_numeric($item)) {
                        $ids[] = (int) $item;
                    }
                }
            } elseif (is_string($raw)) {
                $parts = preg_split('/[\s,，]+/', trim($raw));
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        if ($part !== '' && is_numeric($part)) {
                            $ids[] = (int) $part;
                        }
                    }
                }
            } elseif (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }

        $single = Request::int($input, 'channel_id', 0);
        if ($single > 0) {
            $provided = true;
            $ids[] = $single;
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        return [
            'ids' => $ids,
            'provided' => $provided,
        ];
    }
}
