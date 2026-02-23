<?php

declare(strict_types=1);

namespace Qiling\Core\Payment;

use Qiling\Core\Config;

final class AlipayClient
{
    private string $appId;
    private string $privateKey;
    private string $publicKey;
    private string $gateway;
    private bool $enabled;
    private bool $webEnabled;
    private bool $f2fEnabled;
    private bool $h5Enabled;
    private bool $appEnabled;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->appId = trim((string) ($config['alipay_app_id'] ?? Config::get('ALIPAY_APP_ID', '')));
        $this->privateKey = trim((string) ($config['alipay_private_key'] ?? Config::get('ALIPAY_PRIVATE_KEY', '')));
        $this->publicKey = trim((string) ($config['alipay_public_key'] ?? Config::get('ALIPAY_PUBLIC_KEY', '')));
        $this->gateway = trim((string) ($config['alipay_gateway'] ?? Config::get('ALIPAY_GATEWAY', 'https://openapi.alipay.com/gateway.do')));
        $this->enabled = $this->toBool($config['alipay_enabled'] ?? Config::get('ALIPAY_ENABLED', 'false'));
        $this->webEnabled = $this->toBool($config['alipay_web_enabled'] ?? Config::get('ALIPAY_WEB_ENABLED', 'true'));
        $this->f2fEnabled = $this->toBool($config['alipay_f2f_enabled'] ?? Config::get('ALIPAY_F2F_ENABLED', 'true'));
        $this->h5Enabled = $this->toBool($config['alipay_h5_enabled'] ?? Config::get('ALIPAY_H5_ENABLED', 'false'));
        $this->appEnabled = $this->toBool($config['alipay_app_enabled'] ?? Config::get('ALIPAY_APP_ENABLED', 'false'));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function assertConfigured(): void
    {
        if ($this->appId === '' || $this->privateKey === '' || $this->publicKey === '') {
            throw new \RuntimeException('支付宝配置不完整，请填写 AppID / 应用私钥 / 支付宝公钥');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        string $scene,
        string $outTradeNo,
        float $amount,
        string $subject,
        string $notifyUrl,
        string $returnUrl
    ): array {
        $scene = strtolower(trim($scene));
        if ($scene === 'web') {
            $scene = 'page';
        } elseif ($scene === 'h5') {
            $scene = 'wap';
        }
        if (!in_array($scene, ['page', 'wap', 'f2f', 'app'], true)) {
            $scene = 'f2f';
        }
        if ($scene === 'page' && !$this->webEnabled) {
            return ['ok' => false, 'error' => '支付宝网页支付已关闭'];
        }
        if ($scene === 'f2f' && !$this->f2fEnabled) {
            return ['ok' => false, 'error' => '支付宝当面付已关闭'];
        }
        if ($scene === 'wap' && !$this->h5Enabled) {
            return ['ok' => false, 'error' => '支付宝 H5 支付已关闭'];
        }
        if ($scene === 'app' && !$this->appEnabled) {
            return ['ok' => false, 'error' => '支付宝 APP 支付已关闭'];
        }

        $method = 'alipay.trade.precreate';
        $bizContent = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => sprintf('%.2f', $amount),
            'subject' => $subject,
        ];

        if ($scene === 'page') {
            $method = 'alipay.trade.page.pay';
            $bizContent['product_code'] = 'FAST_INSTANT_TRADE_PAY';
        } elseif ($scene === 'wap') {
            $method = 'alipay.trade.wap.pay';
            $bizContent['product_code'] = 'QUICK_WAP_WAY';
        } elseif ($scene === 'app') {
            $method = 'alipay.trade.app.pay';
            $bizContent['product_code'] = 'QUICK_MSECURITY_PAY';
        }

        $params = [
            'app_id' => $this->appId,
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $notifyUrl,
            'biz_content' => (string) json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (in_array($scene, ['page', 'wap'], true) && $returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }

        $params['sign'] = $this->generateSign($params);

        if (in_array($scene, ['page', 'wap'], true)) {
            return [
                'ok' => true,
                'scene' => $scene,
                'pay_url' => rtrim($this->gateway, '?') . '?' . http_build_query($params),
                'gateway_response' => null,
            ];
        }
        if ($scene === 'app') {
            return [
                'ok' => true,
                'scene' => $scene,
                'app_order_string' => http_build_query($params),
                'gateway_response' => null,
            ];
        }

        $response = $this->postForm($this->gateway, $params);
        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'alipay response parse failed',
                'gateway_response' => $response,
            ];
        }

        $node = $decoded['alipay_trade_precreate_response'] ?? null;
        if (!is_array($node)) {
            return [
                'ok' => false,
                'error' => 'alipay response missing',
                'gateway_response' => $decoded,
            ];
        }

        $code = (string) ($node['code'] ?? '');
        if ($code !== '10000') {
            return [
                'ok' => false,
                'error' => (string) ($node['sub_msg'] ?? $node['msg'] ?? 'alipay precreate failed'),
                'gateway_response' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'scene' => $scene,
            'qr_code' => (string) ($node['qr_code'] ?? ''),
            'gateway_response' => $decoded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $outTradeNo): array
    {
        $result = $this->request(
            'alipay.trade.query',
            [
                'out_trade_no' => $outTradeNo,
            ]
        );
        if (empty($result['ok'])) {
            return $result;
        }

        $node = is_array($result['node'] ?? null) ? $result['node'] : [];
        $status = (string) ($node['trade_status'] ?? '');
        $tradeNo = (string) ($node['trade_no'] ?? '');
        $totalAmount = round((float) ($node['total_amount'] ?? 0), 2);

        return [
            'ok' => true,
            'trade_status' => $status,
            'trade_no' => $tradeNo,
            'total_amount' => $totalAmount,
            'is_paid' => in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true),
            'is_closed' => $status === 'TRADE_CLOSED',
            'gateway_response' => $result['gateway_response'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function close(string $outTradeNo): array
    {
        $result = $this->request(
            'alipay.trade.close',
            [
                'out_trade_no' => $outTradeNo,
            ]
        );
        if (empty($result['ok'])) {
            return $result;
        }

        return [
            'ok' => true,
            'gateway_response' => $result['gateway_response'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(string $outTradeNo, string $outRequestNo, float $refundAmount, string $reason = ''): array
    {
        $result = $this->request(
            'alipay.trade.refund',
            [
                'out_trade_no' => $outTradeNo,
                'refund_amount' => sprintf('%.2f', $refundAmount),
                'out_request_no' => $outRequestNo,
                'refund_reason' => $reason,
            ]
        );
        if (empty($result['ok'])) {
            return $result;
        }

        $node = is_array($result['node'] ?? null) ? $result['node'] : [];
        return [
            'ok' => true,
            'refund_fee' => round((float) ($node['refund_fee'] ?? 0), 2),
            'trade_no' => (string) ($node['trade_no'] ?? ''),
            'gateway_response' => $result['gateway_response'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyNotify(array $payload): bool
    {
        if (!isset($payload['sign'])) {
            return false;
        }

        $sign = (string) $payload['sign'];
        unset($payload['sign'], $payload['sign_type']);

        $content = $this->buildSignContent($payload);
        $publicKey = $this->normalizePublicKey($this->publicKey);
        if ($publicKey === '') {
            return false;
        }

        $res = openssl_pkey_get_public($publicKey);
        if ($res === false) {
            return false;
        }

        $verified = openssl_verify($content, base64_decode($sign, true) ?: '', $res, OPENSSL_ALGO_SHA256);
        return $verified === 1;
    }

    private function toBool($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $bizContent
     * @return array<string, mixed>
     */
    private function request(string $method, array $bizContent): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => (string) json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $this->generateSign($params);

        $response = $this->postForm($this->gateway, $params);
        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'alipay response parse failed',
                'gateway_response' => $response,
            ];
        }

        $nodeKey = str_replace('.', '_', $method) . '_response';
        $node = $decoded[$nodeKey] ?? null;
        if (!is_array($node)) {
            return [
                'ok' => false,
                'error' => 'alipay response missing',
                'gateway_response' => $decoded,
            ];
        }

        $code = (string) ($node['code'] ?? '');
        if ($code !== '10000') {
            return [
                'ok' => false,
                'error' => (string) ($node['sub_msg'] ?? $node['msg'] ?? 'alipay request failed'),
                'gateway_response' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'node' => $node,
            'gateway_response' => $decoded,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function generateSign(array $params): string
    {
        $content = $this->buildSignContent($params);
        $privateKey = $this->normalizePrivateKey($this->privateKey);
        if ($privateKey === '') {
            throw new \RuntimeException('invalid alipay private key');
        }

        $res = openssl_pkey_get_private($privateKey);
        if ($res === false) {
            throw new \RuntimeException('load alipay private key failed');
        }

        $sign = '';
        $ok = openssl_sign($content, $sign, $res, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('generate alipay sign failed');
        }

        return base64_encode($sign);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildSignContent(array $params): string
    {
        unset($params['sign']);
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || is_array($v)) {
                continue;
            }
            $pairs[] = $k . '=' . (string) $v;
        }

        return implode('&', $pairs);
    }

    private function normalizePrivateKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $key);
        if ($key === '') {
            return '';
        }

        if (strpos($key, 'BEGIN') !== false) {
            return $key;
        }

        $body = trim(preg_replace('/\s+/', '', $key) ?? '');
        return "-----BEGIN PRIVATE KEY-----\n" . wordwrap($body, 64, "\n", true) . "\n-----END PRIVATE KEY-----";
    }

    private function normalizePublicKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $key);
        if ($key === '') {
            return '';
        }

        if (strpos($key, 'BEGIN') !== false) {
            return $key;
        }

        $body = trim(preg_replace('/\s+/', '', $key) ?? '');
        return "-----BEGIN PUBLIC KEY-----\n" . wordwrap($body, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function postForm(string $url, array $params, int $timeoutSeconds = 12): array
    {
        $body = http_build_query($params);
        $headerLines = [
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            'Content-Length: ' . strlen($body),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => max(1, $timeoutSeconds),
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $responseBody = is_string($responseBody) ? $responseBody : '';

        return [
            'status_code' => $this->parseStatusCode($http_response_header ?? []),
            'body' => $responseBody,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function parseStatusCode(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }

        $first = (string) ($headers[0] ?? '');
        if (preg_match('/\s(\d{3})\s?/', $first, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
