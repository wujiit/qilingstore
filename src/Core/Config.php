<?php

declare(strict_types=1);

namespace Qiling\Core;

final class Config
{
    /** @var array<string, string> */
    private static array $items = [];

    public static function load(string $envPath): void
    {
        self::$items = self::defaults();

        if (!is_file($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $value = trim($value, "\"'");

            self::$items[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$items[$key] ?? $default;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$items;
    }

    /** @return array<string, string> */
    private static function defaults(): array
    {
        return [
            'APP_NAME' => 'Qiling Medspa System',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://localhost:8088',
            'INSTALL_ALLOW_REINSTALL' => 'false',
            'TRUST_PROXY_HEADERS' => 'false',
            'APP_KEY' => '',
            'LOGIN_MAX_FAILED_ATTEMPTS' => '5',
            'LOGIN_LOCK_SECONDS' => '900',
            'LOGIN_IP_RATE_LIMIT_WINDOW_SECONDS' => '60',
            'LOGIN_IP_RATE_LIMIT_MAX_REQUESTS' => '30',
            'LOGIN_IP_RATE_LIMIT_LOCK_SECONDS' => '600',
            'PASSWORD_MIN_LENGTH' => '8',
            'PASSWORD_MAX_LENGTH' => '128',
            'PASSWORD_REQUIRE_CLASSES' => '3',
            'PASSWORD_CHECK_LEAKED' => 'true',
            'PASSWORD_PWNED_API_ENABLED' => 'false',
            'PASSWORD_PWNED_MAX_BREACH_COUNT' => '0',
            'PASSWORD_PWNED_TIMEOUT_SECONDS' => '3',
            'PASSWORD_RESET_EMAIL_ENABLED' => 'false',
            'PASSWORD_RESET_MAIL_FROM' => '',
            'PASSWORD_RESET_MAIL_SUBJECT_PREFIX' => '[QILING]',
            'PASSWORD_RESET_REQUEST_WINDOW_SECONDS' => '900',
            'PASSWORD_RESET_REQUEST_MAX_PER_IP' => '5',
            'PASSWORD_RESET_REQUEST_MAX_PER_ACCOUNT' => '3',
            'PASSWORD_RESET_CODE_TTL_SECONDS' => '600',
            'PASSWORD_RESET_CONFIRM_MAX_FAILS' => '5',
            'PASSWORD_RESET_RETENTION_DAYS' => '30',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'qiling_medspa',
            'DB_USERNAME' => 'qiling',
            'DB_PASSWORD' => '',
            'API_TOKEN_TTL_SECONDS' => '86400',
            'CRM_WEBHOOK_ALLOWED_HOSTS' => '',
            'CRM_WEBHOOK_ALLOW_PRIVATE_NETWORK' => 'false',
            'PUSH_WEBHOOK_ALLOWED_HOSTS' => '',
            'PUSH_WEBHOOK_ALLOW_PRIVATE_NETWORK' => 'false',
            'WP_SYNC_SHARED_SECRET' => '',
            'WP_SYNC_REQUIRE_SIGNATURE' => 'true',
            'WP_SYNC_SIGNATURE_TTL_SECONDS' => '300',
            'CRON_SHARED_KEY' => '',
            'INSTALL_ADMIN_USERNAME' => 'admin',
            'INSTALL_ADMIN_PASSWORD' => '',
            'INSTALL_ADMIN_EMAIL' => 'admin@qiling.local',
            'PORTAL_TOKEN_MAX_FAILED_ATTEMPTS' => '8',
            'PORTAL_TOKEN_LOCK_SECONDS' => '900',
            'PORTAL_TOKEN_RATE_LIMIT_WINDOW_SECONDS' => '60',
            'PORTAL_TOKEN_RATE_LIMIT_MAX_REQUESTS' => '30',
            'PORTAL_QR_THIRD_PARTY_ENABLED' => 'false',
            'PAYMENT_PUBLIC_SYNC_MIN_INTERVAL_SECONDS' => '8',
            'ALIPAY_ENABLED' => 'false',
            'ALIPAY_APP_ID' => '',
            'ALIPAY_PRIVATE_KEY' => '',
            'ALIPAY_PUBLIC_KEY' => '',
            'ALIPAY_GATEWAY' => 'https://openapi.alipay.com/gateway.do',
            'ALIPAY_NOTIFY_URL' => '',
            'ALIPAY_RETURN_URL' => '',
            'ALIPAY_WEB_ENABLED' => 'true',
            'ALIPAY_F2F_ENABLED' => 'true',
            'ALIPAY_H5_ENABLED' => 'false',
            'ALIPAY_APP_ENABLED' => 'false',
            'WECHAT_ENABLED' => 'false',
            'WECHAT_APP_ID' => '',
            'WECHAT_MCH_ID' => '',
            'WECHAT_SECRET' => '',
            'WECHAT_API_KEY' => '',
            'WECHAT_JSAPI_ENABLED' => 'true',
            'WECHAT_H5_ENABLED' => 'true',
            'WECHAT_UNIFIEDORDER_URL' => 'https://api.mch.weixin.qq.com/pay/unifiedorder',
            'WECHAT_ORDERQUERY_URL' => 'https://api.mch.weixin.qq.com/pay/orderquery',
            'WECHAT_CLOSEORDER_URL' => 'https://api.mch.weixin.qq.com/pay/closeorder',
            'WECHAT_REFUND_URL' => 'https://api.mch.weixin.qq.com/secapi/pay/refund',
            'WECHAT_REFUND_NOTIFY_URL' => '',
            'WECHAT_CERT_PATH' => '',
            'WECHAT_KEY_PATH' => '',
            'WECHAT_CERT_PASSPHRASE' => '',
            'WECHAT_NOTIFY_URL' => '',
        ];
    }
}
