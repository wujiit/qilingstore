<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Auth;
use Qiling\Core\Database;
use Qiling\Core\MobileMenuService;
use Qiling\Core\SystemSettingService;
use Qiling\Support\Response;

final class MobileController
{
    public static function menu(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $settings = SystemSettingService::all(Database::pdo());
        $roleKey = (string) ($user['role_key'] ?? '');
        $resolved = MobileMenuService::resolveForRole($settings, $roleKey);

        Response::json([
            'role_key' => $resolved['role_key'],
            'source' => $resolved['source'],
            'tabs' => $resolved['tabs'],
            'subtabs' => $resolved['subtabs'],
            'tab_options' => MobileMenuService::tabOptions(),
            'subtab_options' => MobileMenuService::subtabOptions(),
        ]);
    }
}

