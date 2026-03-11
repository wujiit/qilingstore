<?php

declare(strict_types=1);

namespace Qiling\Core\Push;

use Qiling\Core\Config;
use Qiling\Core\HttpClient;

final class FeishuProvider implements PushProviderInterface
{
    /**
     * @param array<string, mixed> $channel
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function send(array $channel, string $message, array $args = []): array
    {
        $securityMode = $this->securityMode($channel);
        $keyword = trim((string) ($channel['keyword'] ?? ''));

        if ($securityMode === 'keyword' && $keyword === '') {
            return [
                'ok' => false,
                'error' => 'feishu keyword required for keyword mode',
                'status_code' => 0,
                'body' => '',
                'request_payload' => [],
                'webhook_url' => (string) ($channel['webhook_url'] ?? ''),
            ];
        }

        if (in_array($securityMode, ['auto', 'keyword'], true) && $keyword !== '' && strpos($message, $keyword) === false) {
            $message = '[' . $keyword . '] ' . $message;
        }

        $payload = [
            'msg_type' => 'text',
            'content' => [
                'text' => $message,
            ],
        ];

        $secret = trim((string) ($channel['secret'] ?? ''));
        if ($securityMode === 'sign' && $secret === '') {
            return [
                'ok' => false,
                'error' => 'feishu secret required for sign mode',
                'status_code' => 0,
                'body' => '',
                'request_payload' => $payload,
                'webhook_url' => (string) ($channel['webhook_url'] ?? ''),
            ];
        }

        if (in_array($securityMode, ['auto', 'sign'], true) && $secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = $this->buildSign($timestamp, $secret);
        }

        $webhookUrl = trim((string) ($channel['webhook_url'] ?? ''));
        if ($webhookUrl === '') {
            return [
                'ok' => false,
                'error' => 'invalid feishu webhook url',
                'status_code' => 0,
                'body' => '',
                'request_payload' => $payload,
                'webhook_url' => '',
            ];
        }
        if (!$this->isHttpsUrl($webhookUrl)) {
            return [
                'ok' => false,
                'error' => 'webhook_url must be https',
                'status_code' => 0,
                'body' => '',
                'request_payload' => $payload,
                'webhook_url' => $webhookUrl,
            ];
        }

        $response = HttpClient::postJson($webhookUrl, $payload, [], 10, $this->httpClientOptions());
        $responseError = trim((string) ($response['error'] ?? ''));
        if ($responseError !== '') {
            return [
                'ok' => false,
                'error' => $responseError,
                'status_code' => (int) ($response['status_code'] ?? 0),
                'body' => '',
                'request_payload' => $payload,
                'webhook_url' => $webhookUrl,
            ];
        }
        $statusCode = (int) ($response['status_code'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'ok' => false,
                'error' => 'http_error',
                'status_code' => $statusCode,
                'body' => $body,
                'request_payload' => $payload,
                'webhook_url' => $webhookUrl,
            ];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['code']) && (int) $decoded['code'] !== 0) {
            return [
                'ok' => false,
                'error' => (string) ($decoded['msg'] ?? 'feishu_api_error'),
                'status_code' => $statusCode,
                'body' => $body,
                'request_payload' => $payload,
                'webhook_url' => $webhookUrl,
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'status_code' => $statusCode,
            'body' => $body,
            'request_payload' => $payload,
            'webhook_url' => $webhookUrl,
        ];
    }

    private function buildSign(string $timestamp, string $secret): string
    {
        $stringToSign = $timestamp . "\n" . $secret;
        $hash = hash_hmac('sha256', '', $stringToSign, true);

        return base64_encode($hash);
    }

    /**
     * @param array<string, mixed> $channel
     */
    private function securityMode(array $channel): string
    {
        $mode = (string) ($channel['security_mode'] ?? 'auto');
        if (!in_array($mode, ['auto', 'sign', 'keyword', 'ip'], true)) {
            return 'auto';
        }

        return $mode;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpClientOptions(): array
    {
        $allowPrivateNetwork = $this->toBool((string) Config::get('PUSH_WEBHOOK_ALLOW_PRIVATE_NETWORK', 'false'));

        return [
            'block_private_network' => !$allowPrivateNetwork,
            'disallow_redirects' => true,
            'allowed_hosts' => $this->allowedWebhookHosts(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedWebhookHosts(): array
    {
        $raw = trim((string) Config::get('PUSH_WEBHOOK_ALLOWED_HOSTS', ''));
        if ($raw === '') {
            return [];
        }

        $hosts = [];
        $parts = preg_split('/[\s,;]+/', $raw);
        if (!is_array($parts)) {
            return [];
        }
        foreach ($parts as $part) {
            $host = strtolower(trim((string) $part));
            if ($host !== '') {
                $hosts[$host] = $host;
            }
        }

        return array_values($hosts);
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        $host = trim((string) ($parts['host'] ?? ''));
        return $scheme === 'https' && $host !== '';
    }
}
