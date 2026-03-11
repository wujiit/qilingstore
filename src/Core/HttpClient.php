<?php

declare(strict_types=1);

namespace Qiling\Core;

final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeoutSeconds = 10, array $options = []): array
    {
        $urlValidation = self::validateUrl($url, $options);
        if (!(bool) ($urlValidation['ok'] ?? false)) {
            return [
                'status_code' => 0,
                'body' => '',
                'request_payload' => $payload,
                'error' => (string) ($urlValidation['error'] ?? 'invalid url'),
            ];
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headerLines = [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($body),
        ];

        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $disallowRedirects = self::toBool($options['disallow_redirects'] ?? true);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => max(1, $timeoutSeconds),
                'ignore_errors' => true,
                'follow_location' => $disallowRedirects ? 0 : 1,
                'max_redirects' => $disallowRedirects ? 0 : 3,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents((string) ($urlValidation['url'] ?? $url), false, $context);
        $responseBody = is_string($responseBody) ? $responseBody : '';

        $statusCode = self::parseStatusCode($http_response_header ?? []);

        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
            'request_payload' => $payload,
            'error' => '',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok:bool,url:string,error:string}
     */
    private static function validateUrl(string $url, array $options): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'url' => '', 'error' => 'url is required'];
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['ok' => false, 'url' => '', 'error' => 'url parse failed'];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['ok' => false, 'url' => '', 'error' => 'url must be http/https'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'url' => '', 'error' => 'url auth info is forbidden'];
        }

        $allowedHosts = self::normalizeAllowedHosts($options['allowed_hosts'] ?? []);
        $requireAllowedHosts = self::toBool($options['require_allowed_hosts'] ?? false);
        if ($requireAllowedHosts && $allowedHosts === []) {
            return ['ok' => false, 'url' => '', 'error' => 'allowed_hosts is required'];
        }
        if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
            return ['ok' => false, 'url' => '', 'error' => 'host is not in allowlist'];
        }

        if (self::toBool($options['block_private_network'] ?? false)) {
            if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
                return ['ok' => false, 'url' => '', 'error' => 'private host is forbidden'];
            }

            $ips = self::resolveHostIps($host);
            if ($ips === []) {
                return ['ok' => false, 'url' => '', 'error' => 'host resolve failed'];
            }
            foreach ($ips as $ip) {
                if (self::isPrivateOrReservedIp($ip)) {
                    return ['ok' => false, 'url' => '', 'error' => 'private or reserved ip is forbidden'];
                }
            }
        }

        return ['ok' => true, 'url' => $url, 'error' => ''];
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private static function normalizeAllowedHosts(mixed $raw): array
    {
        $hosts = [];
        if (is_string($raw)) {
            $parts = preg_split('/[\s,;]+/', trim($raw));
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $host = strtolower(trim((string) $part));
                    if ($host !== '') {
                        $hosts[$host] = $host;
                    }
                }
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $host = strtolower(trim($item));
                if ($host !== '') {
                    $hosts[$host] = $host;
                }
            }
        }
        return array_values($hosts);
    }

    /**
     * @return array<int, string>
     */
    private static function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ip = trim((string) $ip);
                if ($ip !== '') {
                    $ips[$ip] = $ip;
                }
            }
        }

        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ip = trim((string) ($record['ipv6'] ?? ''));
                    if ($ip !== '') {
                        $ips[$ip] = $ip;
                    }
                }
            }
        }

        return array_values($ips);
    }

    private static function isPrivateOrReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
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
