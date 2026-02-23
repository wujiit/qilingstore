<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\Push\PushService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class PushController
{
    public static function channels(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);

        $rows = PushService::listChannels(Database::pdo());
        Response::json(['data' => $rows]);
    }

    public static function upsertChannel(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);
        $data = Request::jsonBody();

        $payload = [
            'id' => Request::int($data, 'id', 0),
            'channel_code' => Request::str($data, 'channel_code'),
            'channel_name' => Request::str($data, 'channel_name'),
            'provider' => Request::str($data, 'provider'),
            'webhook_url' => Request::str($data, 'webhook_url'),
            'secret' => Request::str($data, 'secret'),
            'secret_provided' => array_key_exists('secret', $data),
            'keyword' => Request::str($data, 'keyword'),
            'security_mode' => Request::str($data, 'security_mode', 'auto'),
            'enabled' => Request::int($data, 'enabled', 1),
        ];

        try {
            $result = PushService::upsertChannel(Database::pdo(), $payload);
            Audit::log((int) $user['id'], 'push.channel.upsert', 'push_channel', (int) ($result['id'] ?? 0), 'Upsert push channel', $result);
            Response::json($result, (bool) ($result['created'] ?? false) ? 201 : 200);
        } catch (\RuntimeException $e) {
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::serverError('upsert push channel failed', $e);
        }
    }

    public static function test(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);
        $data = Request::jsonBody();

        $channelId = Request::int($data, 'channel_id', 0);
        $message = Request::str($data, 'message');

        if ($channelId <= 0) {
            Response::json(['message' => 'channel_id is required'], 422);
            return;
        }

        try {
            $result = PushService::sendTest(Database::pdo(), $channelId, $message);
            Audit::log((int) $user['id'], 'push.test', 'push_channel', $channelId, 'Send push test message', $result);
            Response::json($result, (bool) ($result['ok'] ?? false) ? 200 : 502);
        } catch (\RuntimeException $e) {
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::serverError('send push test failed', $e);
        }
    }

    public static function logs(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);

        $channelId = isset($_GET['channel_id']) && is_numeric($_GET['channel_id']) ? (int) $_GET['channel_id'] : null;
        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;

        $rows = PushService::listLogs(Database::pdo(), $channelId, $status, $limit);
        Response::json(['data' => $rows]);
    }

    public static function notifyFollowupDue(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $channelIdsMeta = self::parseChannelIds($data);
        $channelIds = $channelIdsMeta['ids'];
        if ($channelIdsMeta['provided'] && $channelIds === []) {
            Response::json(['message' => 'channel_ids invalid'], 422);
            return;
        }

        $rawStoreId = Request::int($data, 'store_id', 0);
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $rawStoreId > 0 ? $rawStoreId : null);
        $limit = Request::int($data, 'limit', 100);
        $retryFailed = Request::int($data, 'retry_failed', 0) === 1;

        try {
            $result = PushService::notifyDueFollowupTasks(
                Database::pdo(),
                $channelIds === [] ? null : $channelIds,
                $scopeStoreId,
                $limit,
                $retryFailed
            );

            Audit::log((int) $user['id'], 'followup.notify.dispatch', 'followup_task', 0, 'Dispatch due followup notifications', [
                'channel_id' => count($channelIds) === 1 ? $channelIds[0] : null,
                'channel_ids' => $channelIds === [] ? null : $channelIds,
                'store_id' => $scopeStoreId,
                'limit' => $limit,
                'retry_failed' => $retryFailed ? 1 : 0,
                'tasks' => $result['tasks'] ?? 0,
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
            ]);

            $status = ((int) ($result['failed'] ?? 0) > 0) ? 207 : 200;
            Response::json($result, $status);
        } catch (\RuntimeException $e) {
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::serverError('dispatch followup notifications failed', $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ids:array<int, int>,provided:bool}
     */
    private static function parseChannelIds(array $data): array
    {
        $provided = false;
        $ids = [];

        if (array_key_exists('channel_ids', $data)) {
            $provided = true;
            $raw = $data['channel_ids'];
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

        $single = Request::int($data, 'channel_id', 0);
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
