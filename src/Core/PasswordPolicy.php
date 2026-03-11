<?php

declare(strict_types=1);

namespace Qiling\Core;

final class PasswordPolicy
{
    /**
     * Top leaked/common passwords for offline deny check.
     * @var array<int, string>
     */
    private const COMMON_LEAKED_PASSWORDS = [
        '123456',
        '123456789',
        '12345678',
        '12345',
        '1234567',
        '123123',
        '000000',
        '00000000',
        '111111',
        '11111111',
        '666666',
        '888888',
        '987654321',
        '654321',
        'qwerty',
        'qwerty123',
        'qwertyuiop',
        'qwe123',
        'asdfgh',
        'asdfghjkl',
        'zxcvbnm',
        '1q2w3e4r',
        '1qaz2wsx',
        'zaq12wsx',
        'qazwsx',
        'abc123',
        'abc123456',
        'password',
        'password1',
        'password123',
        'passw0rd',
        'p@ssw0rd',
        'welcome',
        'welcome1',
        'welcome123',
        'iloveyou',
        'letmein',
        'dragon',
        'monkey',
        'football',
        'baseball',
        'superman',
        'starwars',
        'trustno1',
        'freedom',
        'princess',
        'master',
        'hello',
        'ninja',
        'mustang',
        'whatever',
        'charlie',
        'donald',
        'changeme',
        'default',
        'temp1234',
        'test1234',
        'admin',
        'admin123',
        'admin@123',
        'root',
        'root123',
        'login',
    ];

    /**
     * @param array<string, mixed> $context
     */
    public static function validate(string $password, string $field = 'password', array $context = []): ?string
    {
        $password = trim($password);

        $minLength = max(8, self::toInt(Config::get('PASSWORD_MIN_LENGTH', '8'), 8));
        $maxLength = max($minLength, self::toInt(Config::get('PASSWORD_MAX_LENGTH', '128'), 128));
        $requiredClasses = min(4, max(2, self::toInt(Config::get('PASSWORD_REQUIRE_CLASSES', '3'), 3)));

        $length = strlen($password);
        if ($length < $minLength) {
            return sprintf('%s must be at least %d chars', $field, $minLength);
        }
        if ($length > $maxLength) {
            return sprintf('%s must be at most %d chars', $field, $maxLength);
        }
        if (preg_match('/\s/u', $password) === 1) {
            return sprintf('%s must not contain spaces', $field);
        }

        $classes = 0;
        $classes += preg_match('/[A-Z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[a-z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[0-9]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^a-zA-Z0-9]/', $password) === 1 ? 1 : 0;
        if ($classes < $requiredClasses) {
            return sprintf('%s must include at least %d of uppercase, lowercase, number and symbol', $field, $requiredClasses);
        }

        if (self::isTooSimilarToContext($password, $context)) {
            return sprintf('%s is too similar to account information', $field);
        }

        if (self::toBool(Config::get('PASSWORD_CHECK_LEAKED', 'true'))) {
            if (self::isCommonLeakedPassword($password) || self::isPwnedLeakedPassword($password)) {
                return sprintf('%s is too common or leaked', $field);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function isTooSimilarToContext(string $password, array $context): bool
    {
        $normalizedPassword = strtolower($password);
        $tokens = [];

        foreach ($context as $value) {
            if (!is_scalar($value) || $value === null) {
                continue;
            }
            foreach (self::tokenizeContextValue((string) $value) as $token) {
                if (strlen($token) >= 4) {
                    $tokens[$token] = $token;
                }
            }
        }

        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($normalizedPassword, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function tokenizeContextValue(string $value): array
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return [];
        }

        $tokens = [$value];
        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);
            if ($local !== '') {
                $tokens[] = $local;
            }
            if ($domain !== '') {
                $tokens[] = $domain;
                $domainParts = explode('.', $domain);
                if ($domainParts !== []) {
                    $tokens[] = (string) ($domainParts[0] ?? '');
                }
            }
        }

        $parts = preg_split('/[^a-z0-9]+/', $value);
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $tokens[] = $part;
                }
            }
        }

        $result = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                $result[$token] = $token;
            }
        }

        return array_values($result);
    }

    private static function isCommonLeakedPassword(string $password): bool
    {
        if (preg_match('/^(.)\1{7,}$/', $password) === 1) {
            return true;
        }

        $normalized = strtolower(trim($password));
        if ($normalized === '') {
            return true;
        }

        if (in_array($normalized, self::COMMON_LEAKED_PASSWORDS, true)) {
            return true;
        }

        $collapsed = preg_replace('/[^a-z0-9]/', '', $normalized);
        if (is_string($collapsed) && $collapsed !== '' && in_array($collapsed, self::COMMON_LEAKED_PASSWORDS, true)) {
            return true;
        }

        return false;
    }

    private static function isPwnedLeakedPassword(string $password): bool
    {
        if (!self::toBool(Config::get('PASSWORD_PWNED_API_ENABLED', 'false'))) {
            return false;
        }

        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);
        if ($prefix === '' || $suffix === '') {
            return false;
        }

        $timeout = max(1, min(8, self::toInt(Config::get('PASSWORD_PWNED_TIMEOUT_SECONDS', '3'), 3)));
        $maxBreachCount = max(0, self::toInt(Config::get('PASSWORD_PWNED_MAX_BREACH_COUNT', '0'), 0));
        $url = 'https://api.pwnedpasswords.com/range/' . $prefix;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => implode("\r\n", [
                    'Add-Padding: true',
                    'User-Agent: qiling-medspa-password-policy',
                ]),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response) || $response === '') {
            return false;
        }

        $status = self::parseStatusCode($http_response_header ?? []);
        if ($status < 200 || $status >= 300) {
            return false;
        }

        $lines = preg_split('/\r\n|\n|\r/', $response);
        if (!is_array($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$candidateSuffix, $countRaw] = explode(':', $line, 2);
            if (!hash_equals($suffix, strtoupper(trim($candidateSuffix)))) {
                continue;
            }
            $count = (int) trim($countRaw);
            return $count > $maxBreachCount;
        }

        return false;
    }

    /**
     * @param array<int, string> $responseHeaders
     */
    private static function parseStatusCode(array $responseHeaders): int
    {
        if ($responseHeaders === []) {
            return 0;
        }

        $first = (string) ($responseHeaders[0] ?? '');
        if (preg_match('/\s(\d{3})\s?/', $first, $matches) !== 1) {
            return 0;
        }

        return (int) ($matches[1] ?? 0);
    }

    private static function toBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function toInt(?string $value, int $default): int
    {
        if ($value === null) {
            return $default;
        }
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }
}
