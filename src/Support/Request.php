<?php

declare(strict_types=1);

namespace Qiling\Support;

final class Request
{
    private static ?string $cachedRawBody = null;

    public static function rawBody(): string
    {
        if (self::$cachedRawBody !== null) {
            return self::$cachedRawBody;
        }

        $raw = file_get_contents('php://input');
        self::$cachedRawBody = is_string($raw) ? $raw : '';
        return self::$cachedRawBody;
    }

    /** @return array<string, mixed> */
    public static function jsonBody(): array
    {
        $raw = self::rawBody();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<int, string> */
    public static function strList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }
}
