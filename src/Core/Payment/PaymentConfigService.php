<?php

declare(strict_types=1);

namespace Qiling\Core\Payment;

use PDO;
use Qiling\Core\Config;
use Qiling\Core\SystemSettingService;

final class PaymentConfigService
{
    /**
     * @return array<string, mixed>
     */
    public static function runtime(PDO $pdo): array
    {
        $settings = SystemSettingService::all($pdo);

        $alipayPrivateKey = self::pick(
            (string) ($settings['alipay_private_key'] ?? ''),
            (string) Config::get('ALIPAY_PRIVATE_KEY', '')
        );
        $alipayPublicKey = self::pick(
            (string) ($settings['alipay_public_key'] ?? ''),
            (string) Config::get('ALIPAY_PUBLIC_KEY', '')
        );

        $wechatSecret = self::pick(
            (string) ($settings['wechat_secret'] ?? ''),
            (string) Config::get('WECHAT_SECRET', '')
        );
        $wechatApiKey = self::pick(
            (string) ($settings['wechat_api_key'] ?? ''),
            (string) Config::get('WECHAT_API_KEY', '')
        );
        $wechatCertContent = self::normalizeMultiline(self::pick(
            (string) ($settings['wechat_cert_content'] ?? ''),
            ''
        ));
        $wechatKeyContent = self::normalizeMultiline(self::pick(
            (string) ($settings['wechat_key_content'] ?? ''),
            ''
        ));

        return [
            'alipay_enabled' => self::pickBool($settings['alipay_enabled'] ?? '', (string) Config::get('ALIPAY_ENABLED', 'false')),
            'alipay_app_id' => self::pick((string) ($settings['alipay_app_id'] ?? ''), (string) Config::get('ALIPAY_APP_ID', '')),
            'alipay_private_key' => $alipayPrivateKey,
            'alipay_public_key' => $alipayPublicKey,
            'alipay_web_enabled' => self::pickBool($settings['alipay_web_enabled'] ?? '', (string) Config::get('ALIPAY_WEB_ENABLED', 'true')),
            'alipay_f2f_enabled' => self::pickBool($settings['alipay_f2f_enabled'] ?? '', (string) Config::get('ALIPAY_F2F_ENABLED', 'true')),
            'alipay_h5_enabled' => self::pickBool($settings['alipay_h5_enabled'] ?? '', (string) Config::get('ALIPAY_H5_ENABLED', 'false')),
            'alipay_app_enabled' => self::pickBool($settings['alipay_app_enabled'] ?? '', (string) Config::get('ALIPAY_APP_ENABLED', 'false')),
            'alipay_gateway' => self::pick((string) ($settings['alipay_gateway'] ?? ''), (string) Config::get('ALIPAY_GATEWAY', 'https://openapi.alipay.com/gateway.do')),
            'alipay_notify_url' => self::pick((string) ($settings['alipay_notify_url'] ?? ''), (string) Config::get('ALIPAY_NOTIFY_URL', '')),
            'alipay_return_url' => self::pick((string) ($settings['alipay_return_url'] ?? ''), (string) Config::get('ALIPAY_RETURN_URL', '')),

            'wechat_enabled' => self::pickBool($settings['wechat_enabled'] ?? '', (string) Config::get('WECHAT_ENABLED', 'false')),
            'wechat_app_id' => self::pick((string) ($settings['wechat_app_id'] ?? ''), (string) Config::get('WECHAT_APP_ID', '')),
            'wechat_mch_id' => self::pick((string) ($settings['wechat_mch_id'] ?? ''), (string) Config::get('WECHAT_MCH_ID', '')),
            'wechat_secret' => $wechatSecret,
            'wechat_api_key' => $wechatApiKey,
            'wechat_jsapi_enabled' => self::pickBool($settings['wechat_jsapi_enabled'] ?? '', (string) Config::get('WECHAT_JSAPI_ENABLED', 'true')),
            'wechat_h5_enabled' => self::pickBool($settings['wechat_h5_enabled'] ?? '', (string) Config::get('WECHAT_H5_ENABLED', 'true')),
            'wechat_notify_url' => self::pick((string) ($settings['wechat_notify_url'] ?? ''), (string) Config::get('WECHAT_NOTIFY_URL', '')),
            'wechat_refund_notify_url' => self::pick((string) ($settings['wechat_refund_notify_url'] ?? ''), (string) Config::get('WECHAT_REFUND_NOTIFY_URL', '')),
            'wechat_unifiedorder_url' => self::pick((string) ($settings['wechat_unifiedorder_url'] ?? ''), (string) Config::get('WECHAT_UNIFIEDORDER_URL', 'https://api.mch.weixin.qq.com/pay/unifiedorder')),
            'wechat_orderquery_url' => self::pick((string) ($settings['wechat_orderquery_url'] ?? ''), (string) Config::get('WECHAT_ORDERQUERY_URL', 'https://api.mch.weixin.qq.com/pay/orderquery')),
            'wechat_closeorder_url' => self::pick((string) ($settings['wechat_closeorder_url'] ?? ''), (string) Config::get('WECHAT_CLOSEORDER_URL', 'https://api.mch.weixin.qq.com/pay/closeorder')),
            'wechat_refund_url' => self::pick((string) ($settings['wechat_refund_url'] ?? ''), (string) Config::get('WECHAT_REFUND_URL', 'https://api.mch.weixin.qq.com/secapi/pay/refund')),
            'wechat_cert_content' => $wechatCertContent,
            'wechat_key_content' => $wechatKeyContent,
            'wechat_cert_passphrase' => self::pick((string) ($settings['wechat_cert_passphrase'] ?? ''), (string) Config::get('WECHAT_CERT_PASSPHRASE', '')),
            'wechat_cert_path' => (string) Config::get('WECHAT_CERT_PATH', ''),
            'wechat_key_path' => (string) Config::get('WECHAT_KEY_PATH', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminPayload(PDO $pdo): array
    {
        $cfg = self::runtime($pdo);

        return [
            'alipay' => [
                'enabled' => self::toIntBool($cfg['alipay_enabled']),
                'app_id' => (string) ($cfg['alipay_app_id'] ?? ''),
                'web_enabled' => self::toIntBool($cfg['alipay_web_enabled']),
                'f2f_enabled' => self::toIntBool($cfg['alipay_f2f_enabled']),
                'h5_enabled' => self::toIntBool($cfg['alipay_h5_enabled']),
                'app_enabled' => self::toIntBool($cfg['alipay_app_enabled']),
                'gateway' => (string) ($cfg['alipay_gateway'] ?? ''),
                'notify_url' => (string) ($cfg['alipay_notify_url'] ?? ''),
                'return_url' => (string) ($cfg['alipay_return_url'] ?? ''),
                'has_private_key' => ((string) ($cfg['alipay_private_key'] ?? '') !== '') ? 1 : 0,
                'has_public_key' => ((string) ($cfg['alipay_public_key'] ?? '') !== '') ? 1 : 0,
            ],
            'wechat' => [
                'enabled' => self::toIntBool($cfg['wechat_enabled']),
                'app_id' => (string) ($cfg['wechat_app_id'] ?? ''),
                'mch_id' => (string) ($cfg['wechat_mch_id'] ?? ''),
                'jsapi_enabled' => self::toIntBool($cfg['wechat_jsapi_enabled']),
                'h5_enabled' => self::toIntBool($cfg['wechat_h5_enabled']),
                'notify_url' => (string) ($cfg['wechat_notify_url'] ?? ''),
                'refund_notify_url' => (string) ($cfg['wechat_refund_notify_url'] ?? ''),
                'unifiedorder_url' => (string) ($cfg['wechat_unifiedorder_url'] ?? ''),
                'orderquery_url' => (string) ($cfg['wechat_orderquery_url'] ?? ''),
                'closeorder_url' => (string) ($cfg['wechat_closeorder_url'] ?? ''),
                'refund_url' => (string) ($cfg['wechat_refund_url'] ?? ''),
                'has_secret' => ((string) ($cfg['wechat_secret'] ?? '') !== '') ? 1 : 0,
                'has_api_key' => ((string) ($cfg['wechat_api_key'] ?? '') !== '') ? 1 : 0,
                'has_cert_content' => ((string) ($cfg['wechat_cert_content'] ?? '') !== '') ? 1 : 0,
                'has_key_content' => ((string) ($cfg['wechat_key_content'] ?? '') !== '') ? 1 : 0,
                'cert_passphrase_set' => ((string) ($cfg['wechat_cert_passphrase'] ?? '') !== '') ? 1 : 0,
                'cert_path' => (string) ($cfg['wechat_cert_path'] ?? ''),
                'key_path' => (string) ($cfg['wechat_key_path'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(PDO $pdo, array $data, int $actorUserId): void
    {
        $updates = [];

        self::setBoolField($updates, $data, 'alipay_enabled');
        self::setTextField($updates, $data, 'alipay_app_id', 128);
        self::setBoolField($updates, $data, 'alipay_web_enabled');
        self::setBoolField($updates, $data, 'alipay_f2f_enabled');
        self::setBoolField($updates, $data, 'alipay_h5_enabled');
        self::setBoolField($updates, $data, 'alipay_app_enabled');
        self::setUrlField($updates, $data, 'alipay_gateway', 255, true);
        self::setUrlField($updates, $data, 'alipay_notify_url', 255, true);
        self::setUrlField($updates, $data, 'alipay_return_url', 255, true);
        self::setSecretField($updates, $data, 'alipay_private_key', 20000);
        self::setSecretField($updates, $data, 'alipay_public_key', 20000);

        self::setBoolField($updates, $data, 'wechat_enabled');
        self::setTextField($updates, $data, 'wechat_app_id', 128);
        self::setTextField($updates, $data, 'wechat_mch_id', 128);
        self::setBoolField($updates, $data, 'wechat_jsapi_enabled');
        self::setBoolField($updates, $data, 'wechat_h5_enabled');
        self::setUrlField($updates, $data, 'wechat_notify_url', 255, true);
        self::setUrlField($updates, $data, 'wechat_refund_notify_url', 255, true);
        self::setUrlField($updates, $data, 'wechat_unifiedorder_url', 255, true);
        self::setUrlField($updates, $data, 'wechat_orderquery_url', 255, true);
        self::setUrlField($updates, $data, 'wechat_closeorder_url', 255, true);
        self::setUrlField($updates, $data, 'wechat_refund_url', 255, true);
        self::setSecretField($updates, $data, 'wechat_secret', 255);
        self::setSecretField($updates, $data, 'wechat_api_key', 255);
        self::setSecretField($updates, $data, 'wechat_cert_content', 50000, true);
        self::setSecretField($updates, $data, 'wechat_key_content', 50000, true);
        self::setSecretField($updates, $data, 'wechat_cert_passphrase', 255);

        if (empty($updates)) {
            throw new \RuntimeException('未检测到可保存的支付配置字段');
        }

        SystemSettingService::upsert($pdo, $updates, $actorUserId);
    }

    /**
     * @param array<string, string> $updates
     * @param array<string, mixed> $input
     */
    private static function setBoolField(array &$updates, array $input, string $key): void
    {
        if (!array_key_exists($key, $input)) {
            return;
        }
        $updates[$key] = self::toIntBool($input[$key]) === 1 ? '1' : '0';
    }

    /**
     * @param array<string, string> $updates
     * @param array<string, mixed> $input
     */
    private static function setTextField(array &$updates, array $input, string $key, int $maxLen): void
    {
        if (!array_key_exists($key, $input)) {
            return;
        }
        $v = trim((string) ($input[$key] ?? ''));
        if (mb_strlen($v) > $maxLen) {
            $v = mb_substr($v, 0, $maxLen);
        }
        $updates[$key] = $v;
    }

    /**
     * @param array<string, string> $updates
     * @param array<string, mixed> $input
     */
    private static function setUrlField(array &$updates, array $input, string $key, int $maxLen, bool $allowEmpty = false): void
    {
        if (!array_key_exists($key, $input)) {
            return;
        }
        $v = trim((string) ($input[$key] ?? ''));
        if ($v === '' && $allowEmpty) {
            $updates[$key] = '';
            return;
        }
        if ($v === '') {
            return;
        }
        if (mb_strlen($v) > $maxLen) {
            $v = mb_substr($v, 0, $maxLen);
        }
        if (filter_var($v, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException($key . ' 不是有效 URL');
        }
        $updates[$key] = $v;
    }

    /**
     * @param array<string, string> $updates
     * @param array<string, mixed> $input
     */
    private static function setSecretField(array &$updates, array $input, string $key, int $maxLen, bool $normalizeMultiline = false): void
    {
        $clearKey = $key . '_clear';
        $shouldClear = array_key_exists($clearKey, $input) && self::toIntBool($input[$clearKey]) === 1;
        $hasValue = array_key_exists($key, $input);

        if (!$hasValue && !$shouldClear) {
            return;
        }

        if ($shouldClear) {
            $updates[$key] = '';
            return;
        }

        $v = trim((string) ($input[$key] ?? ''));
        if ($v === '') {
            return;
        }

        if ($normalizeMultiline) {
            $v = self::normalizeMultiline($v);
        }
        if (mb_strlen($v) > $maxLen) {
            $v = mb_substr($v, 0, $maxLen);
        }
        $updates[$key] = $v;
    }

    private static function normalizeMultiline(string $text): string
    {
        $v = str_replace(["\r\n", "\r"], "\n", $text);
        $v = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $v);
        return trim($v);
    }

    private static function pick(string $primary, string $fallback): string
    {
        return trim($primary) !== '' ? trim($primary) : trim($fallback);
    }

    private static function pickBool(string $primary, string $fallback): bool
    {
        if (trim($primary) !== '') {
            return self::toIntBool($primary) === 1;
        }
        return self::toIntBool($fallback) === 1;
    }

    private static function toIntBool($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
