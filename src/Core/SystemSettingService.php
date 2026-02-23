<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class SystemSettingService
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'admin_entry_path' => 'admin',
        'front_site_enabled' => '1',
        'front_maintenance_message' => '系统维护中，请稍后访问。',
        'front_allow_ips' => '',
        'security_headers_enabled' => '1',
        'mobile_role_menu_json' => '',
        'alipay_enabled' => '0',
        'alipay_app_id' => '',
        'alipay_private_key' => '',
        'alipay_public_key' => '',
        'alipay_web_enabled' => '1',
        'alipay_f2f_enabled' => '1',
        'alipay_h5_enabled' => '0',
        'alipay_app_enabled' => '0',
        'alipay_gateway' => '',
        'alipay_notify_url' => '',
        'alipay_return_url' => '',
        'wechat_enabled' => '0',
        'wechat_app_id' => '',
        'wechat_mch_id' => '',
        'wechat_secret' => '',
        'wechat_api_key' => '',
        'wechat_jsapi_enabled' => '1',
        'wechat_h5_enabled' => '1',
        'wechat_notify_url' => '',
        'wechat_refund_notify_url' => '',
        'wechat_unifiedorder_url' => '',
        'wechat_orderquery_url' => '',
        'wechat_closeorder_url' => '',
        'wechat_refund_url' => '',
        'wechat_cert_content' => '',
        'wechat_key_content' => '',
        'wechat_cert_passphrase' => '',
    ];

    private static bool $tableEnsured = false;

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * @return array<string, string>
     */
    public static function all(PDO $pdo): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->query('SELECT setting_key, setting_value FROM qiling_system_settings');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = self::DEFAULTS;
        foreach ($rows as $row) {
            $key = isset($row['setting_key']) ? trim((string) $row['setting_key']) : '';
            if ($key === '' || !array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $settings[$key] = (string) ($row['setting_value'] ?? '');
        }

        $settings['admin_entry_path'] = self::normalizeAdminEntryPath($settings['admin_entry_path']);

        return $settings;
    }

    public static function get(PDO $pdo, string $key, string $fallback = ''): string
    {
        $settings = self::all($pdo);
        return $settings[$key] ?? $fallback;
    }

    public static function adminEntryPath(PDO $pdo): string
    {
        return self::normalizeAdminEntryPath(self::get($pdo, 'admin_entry_path', 'admin'));
    }

    /**
     * @param array<string, string> $updates
     */
    public static function upsert(PDO $pdo, array $updates, int $actorUserId): void
    {
        self::ensureTable($pdo);

        if (empty($updates)) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_system_settings
             (setting_key, setting_value, updated_by, created_at, updated_at)
             VALUES
             (:setting_key, :setting_value, :updated_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)'
        );

        foreach ($updates as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by' => $actorUserId > 0 ? $actorUserId : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function normalizeAdminEntryPath(string $path): string
    {
        $path = strtolower(trim($path));
        $path = trim($path, "/ \t\n\r\0\x0B");

        if ($path === '') {
            return 'admin';
        }

        if (!preg_match('/^[a-z0-9_-]{3,40}$/', $path)) {
            return 'admin';
        }

        $reserved = [
            'api',
            'health',
            'install',
            'install.php',
            'index.php',
            'admin-assets',
            'admin_assets',
            'assets',
        ];
        if (in_array($path, $reserved, true)) {
            return 'admin';
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    public static function parseAllowIps(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(string $item): string => trim($item),
            preg_split('/[\n,，]+/', $raw) ?: []
        ), static fn(string $item): bool => $item !== '')));
    }

    private static function ensureTable(PDO $pdo): void
    {
        if (self::$tableEnsured) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS qiling_system_settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT NULL,
                updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_qiling_system_settings_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        self::$tableEnsured = true;
    }
}
