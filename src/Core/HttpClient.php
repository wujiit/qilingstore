<?php

declare(strict_types=1);

namespace Qiling\Core;

final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeoutSeconds = 10): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headerLines = [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($body),
        ];

        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

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

        $statusCode = self::parseStatusCode($http_response_header ?? []);

        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
            'request_payload' => $payload,
        ];
    }

    /**
     * @param array<int, string> $responseHeaders
     */
    private static function parseStatusCode(array $responseHeaders): int
    {
        if (empty($responseHeaders)) {
            return 0;
        }

        $first = (string) ($responseHeaders[0] ?? '');
        if (preg_match('/\s(\d{3})\s?/', $first, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
