<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\Config;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\MobileMenuService;
use Qiling\Core\SystemSettingService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class SystemSettingsController
{
    public static function show(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);

        $settings = SystemSettingService::all(Database::pdo());
        Response::json(self::payload($settings));
    }

    public static function update(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);
        $data = Request::jsonBody();

        $updates = [];

        if (array_key_exists('admin_entry_path', $data)) {
            $raw = is_string($data['admin_entry_path']) ? $data['admin_entry_path'] : '';
            $path = SystemSettingService::normalizeAdminEntryPath($raw);
            if ($path === 'admin' && trim($raw) !== '' && strtolower(trim($raw, '/ ')) !== 'admin') {
                Response::json(['message' => 'admin_entry_path 格式无效，仅支持 3-40 位字母数字下划线中划线'], 422);
                return;
            }
            $updates['admin_entry_path'] = $path;
        }

        if (array_key_exists('front_site_enabled', $data)) {
            $updates['front_site_enabled'] = ((int) ($data['front_site_enabled'] ?? 0) === 1) ? '1' : '0';
        }

        if (array_key_exists('front_maintenance_message', $data)) {
            $message = trim((string) ($data['front_maintenance_message'] ?? ''));
            if (mb_strlen($message) > 500) {
                $message = mb_substr($message, 0, 500);
            }
            $updates['front_maintenance_message'] = $message;
        }

        if (array_key_exists('front_allow_ips', $data)) {
            $rawAllow = trim((string) ($data['front_allow_ips'] ?? ''));
            $ipList = SystemSettingService::parseAllowIps($rawAllow);
            foreach ($ipList as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    Response::json(['message' => 'front_allow_ips 包含非法 IP: ' . $ip], 422);
                    return;
                }
            }
            $updates['front_allow_ips'] = implode("\n", $ipList);
        }

        if (array_key_exists('security_headers_enabled', $data)) {
            $updates['security_headers_enabled'] = ((int) ($data['security_headers_enabled'] ?? 0) === 1) ? '1' : '0';
        }

        if (array_key_exists('mobile_role_menu_json', $data)) {
            $rawMenuJson = trim((string) ($data['mobile_role_menu_json'] ?? ''));
            if ($rawMenuJson === '') {
                $updates['mobile_role_menu_json'] = '';
            } else {
                try {
                    $normalized = MobileMenuService::normalizeForInput($rawMenuJson);
                } catch (\RuntimeException $e) {
                    Response::json(['message' => $e->getMessage()], 422);
                    return;
                }
                $updates['mobile_role_menu_json'] = (string) json_encode(
                    $normalized,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        if (empty($updates)) {
            Response::json(['message' => 'no valid setting fields to update'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            SystemSettingService::upsert($pdo, $updates, (int) $user['id']);
            Audit::log((int) $user['id'], 'system.settings.update', 'system_settings', 0, 'Update system settings', $updates);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('update system settings failed', $e);
            return;
        }

        $settings = SystemSettingService::all(Database::pdo());
        Response::json(self::payload($settings));
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, mixed>
     */
    private static function payload(array $settings): array
    {
        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        $adminPath = $settings['admin_entry_path'] ?? 'admin';
        $mobileRoleMap = MobileMenuService::readRoleMap($settings);
        $mobileRoleMenuJson = json_encode(
            $mobileRoleMap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return [
            'settings' => [
                'admin_entry_path' => $adminPath,
                'front_site_enabled' => ($settings['front_site_enabled'] ?? '1') === '1' ? 1 : 0,
                'front_maintenance_message' => $settings['front_maintenance_message'] ?? '',
                'front_allow_ips' => $settings['front_allow_ips'] ?? '',
                'security_headers_enabled' => ($settings['security_headers_enabled'] ?? '1') === '1' ? 1 : 0,
                'mobile_role_menu_json' => $mobileRoleMenuJson !== false ? $mobileRoleMenuJson : '',
            ],
            'derived' => [
                'admin_entry_url' => $appUrl !== '' ? ($appUrl . '/' . $adminPath) : ('/' . $adminPath),
            ],
        ];
    }
}
