<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\SystemUpgradeService;
use Qiling\Support\Response;

final class SystemUpgradeController
{
    public static function status(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);

        try {
            $payload = SystemUpgradeService::status(Database::pdo());
            Response::json($payload);
        } catch (\Throwable $e) {
            Response::serverError('load upgrade status failed', $e);
        }
    }

    public static function run(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);

        try {
            $result = SystemUpgradeService::run(
                Database::pdo(),
                defined('QILING_SYSTEM_ROOT') ? (string) QILING_SYSTEM_ROOT : dirname(__DIR__, 2),
                (int) ($user['id'] ?? 0)
            );

            Audit::log(
                (int) ($user['id'] ?? 0),
                'system.upgrade.run',
                'system_upgrade',
                (int) ($result['log_id'] ?? 0),
                'Run system upgrade',
                ['summary' => $result['summary'] ?? []]
            );

            Response::json($result);
        } catch (\Throwable $e) {
            Response::serverError('run upgrade failed', $e);
        }
    }
}
