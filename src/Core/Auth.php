<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use Qiling\Support\Response;

final class Auth
{
    /** @return array<string, mixed>|null */
    public static function userFromBearerToken(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.*)$/i', (string) $header, $matches)) {
            return null;
        }

        $token = trim((string) ($matches[1] ?? ''));
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        $payload64 = $parts[0];
        $signature = $parts[1];

        $appKey = Config::get('APP_KEY', '');
        if ($appKey === '') {
            return null;
        }

        $expect = hash_hmac('sha256', $payload64, $appKey);
        if (!hash_equals($expect, $signature)) {
            return null;
        }

        $payloadJson = base64_decode(strtr($payload64, '-_', '+/'), true);
        if (!is_string($payloadJson)) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        $exp = (int) ($payload['exp'] ?? 0);
        $uid = (int) ($payload['uid'] ?? 0);

        if ($uid <= 0 || $exp < time()) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.username, u.email, u.role_key, u.status,
                    COALESCE(s.store_id, 0) AS staff_store_id
             FROM qiling_users u
             LEFT JOIN qiling_staff s ON s.user_id = u.id AND s.status = :staff_status
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $uid,
            'staff_status' => 'active',
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user) || ($user['status'] ?? '') !== 'active') {
            return null;
        }

        return $user;
    }

    /** @param array<string, mixed>|null $user */
    public static function requireUser(?array $user): array
    {
        if (is_array($user)) {
            return $user;
        }

        Response::json(['message' => 'Unauthorized'], 401);
        exit;
    }

    public static function issueToken(int $userId): string
    {
        $ttl = (int) (Config::get('API_TOKEN_TTL_SECONDS', '86400') ?? '86400');
        $appKey = (string) Config::get('APP_KEY', '');
        if ($appKey === '') {
            return '';
        }

        $payload = [
            'uid' => $userId,
            'exp' => time() + max($ttl, 60),
        ];

        $payload64 = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload64, $appKey);

        return $payload64 . '.' . $signature;
    }

    public static function requireWpSyncSecret(string $rawBody = ''): void
    {
        $secret = $_SERVER['HTTP_X_QILING_WP_SECRET'] ?? '';
        $expect = (string) Config::get('WP_SYNC_SHARED_SECRET', '');

        if ($expect === '' || !hash_equals($expect, (string) $secret)) {
            Response::json(['message' => 'Forbidden'], 403);
            exit;
        }

        $requireSignature = self::toBool((string) Config::get('WP_SYNC_REQUIRE_SIGNATURE', 'true'));
        if (!$requireSignature) {
            return;
        }

        $tsRaw = trim((string) ($_SERVER['HTTP_X_QILING_WP_TS'] ?? ''));
        $sign = trim((string) ($_SERVER['HTTP_X_QILING_WP_SIGN'] ?? ''));
        if ($tsRaw === '' || $sign === '' || !ctype_digit($tsRaw)) {
            Response::json(['message' => 'Forbidden'], 403);
            exit;
        }

        $ts = (int) $tsRaw;
        $ttlRaw = (string) Config::get('WP_SYNC_SIGNATURE_TTL_SECONDS', '300');
        $ttl = is_numeric($ttlRaw) ? (int) $ttlRaw : 300;
        $ttl = max(60, min($ttl, 3600));
        if (abs(time() - $ts) > $ttl) {
            Response::json(['message' => 'Forbidden'], 403);
            exit;
        }

        $payload = $tsRaw . '.' . $rawBody;
        $expectedSign = hash_hmac('sha256', $payload, $expect);
        if (!hash_equals($expectedSign, $sign)) {
            Response::json(['message' => 'Forbidden'], 403);
            exit;
        }
    }

    private static function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
