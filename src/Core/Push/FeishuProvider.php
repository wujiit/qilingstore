<?php

declare(strict_types=1);

namespace Qiling\Core\Push;

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

        $response = HttpClient::postJson($webhookUrl, $payload, [], 10);
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
}
