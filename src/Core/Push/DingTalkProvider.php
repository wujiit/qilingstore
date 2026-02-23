<?php

declare(strict_types=1);

namespace Qiling\Core\Push;

use Qiling\Core\HttpClient;

final class DingTalkProvider implements PushProviderInterface
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
                'error' => 'dingtalk keyword required for keyword mode',
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
            'msgtype' => 'text',
            'text' => [
                'content' => $message,
            ],
        ];

        $webhookUrl = $this->signedWebhookUrl($channel);
        if ($webhookUrl === '') {
            return [
                'ok' => false,
                'error' => 'invalid dingtalk webhook url',
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
        if (is_array($decoded) && isset($decoded['errcode']) && (int) $decoded['errcode'] !== 0) {
            return [
                'ok' => false,
                'error' => (string) ($decoded['errmsg'] ?? 'dingtalk_api_error'),
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

    /**
     * @param array<string, mixed> $channel
     */
    private function signedWebhookUrl(array $channel): string
    {
        $url = trim((string) ($channel['webhook_url'] ?? ''));
        if ($url === '') {
            return '';
        }

        $securityMode = $this->securityMode($channel);
        $secret = trim((string) ($channel['secret'] ?? ''));

        if ($securityMode === 'sign' && $secret === '') {
            return '';
        }

        if (!in_array($securityMode, ['auto', 'sign'], true) || $secret === '') {
            return $url;
        }

        $timestamp = (string) (int) floor(microtime(true) * 1000);
        $stringToSign = $timestamp . "\n" . $secret;
        $sign = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));

        $urlNoSign = preg_replace('/([&?])(timestamp|sign)=[^&]*/', '$1', $url) ?? $url;
        $urlNoSign = rtrim((string) $urlNoSign, '?&');

        $separator = strpos($urlNoSign, '?') !== false ? '&' : '?';

        return $urlNoSign . $separator . 'timestamp=' . rawurlencode($timestamp) . '&sign=' . rawurlencode($sign);
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
