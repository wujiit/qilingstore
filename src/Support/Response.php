<?php

declare(strict_types=1);

namespace Qiling\Support;

use Qiling\Core\Config;

final class Response
{
    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function serverError(string $message, \Throwable $e): void
    {
        $payload = ['message' => $message];
        if (self::isDebugEnabled()) {
            $payload['error'] = $e->getMessage();
        }
        self::json($payload, 500);
    }

    private static function isDebugEnabled(): bool
    {
        $raw = strtolower(trim((string) Config::get('APP_DEBUG', 'false')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
