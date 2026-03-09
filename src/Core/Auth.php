<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use Qiling\Support\Response;

final class Auth
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

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
        $tokenVersion = (int) ($payload['tv'] ?? 1);

        if ($uid <= 0 || $exp < time()) {
            return null;
        }
        if ($tokenVersion <= 0) {
            $tokenVersion = 1;
        }

        $pdo = Database::pdo();
        $hasTokenVersion = self::hasColumn($pdo, 'qiling_users', 'token_version');
        $tokenVersionSelect = $hasTokenVersion ? 'u.token_version' : '1 AS token_version';

        $stmt = $pdo->prepare(
            'SELECT u.id, u.username, u.email, u.role_key, u.status,
                    COALESCE(s.store_id, 0) AS staff_store_id,
                    r.permissions_json,
                    ' . $tokenVersionSelect . '
             FROM qiling_users u
             LEFT JOIN qiling_staff s ON s.user_id = u.id AND s.status = :staff_status
             LEFT JOIN qiling_roles r ON r.role_key = u.role_key AND r.status = :role_status
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $uid,
            'staff_status' => 'active',
            'role_status' => 'active',
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user) || ($user['status'] ?? '') !== 'active') {
            return null;
        }
        if ($hasTokenVersion && (int) ($user['token_version'] ?? 1) !== $tokenVersion) {
            return null;
        }

        $user['permissions'] = self::decodePermissions($user['permissions_json'] ?? null);
        unset($user['permissions_json'], $user['token_version']);

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

    public static function issueToken(int $userId, int $tokenVersion = 1): string
    {
        $ttl = (int) (Config::get('API_TOKEN_TTL_SECONDS', '86400') ?? '86400');
        $appKey = (string) Config::get('APP_KEY', '');
        if ($appKey === '') {
            return '';
        }
        $tokenVersion = max(1, $tokenVersion);

        $payload = [
            'uid' => $userId,
            'tv' => $tokenVersion,
            'exp' => time() + max($ttl, 60),
        ];

        $payload64 = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload64, $appKey);

        return $payload64 . '.' . $signature;
    }

    public static function bumpTokenVersion(PDO $pdo, int $userId, ?string $updatedAt = null): void
    {
        if ($userId <= 0 || !self::hasColumn($pdo, 'qiling_users', 'token_version')) {
            return;
        }

        $ts = $updatedAt !== null && trim($updatedAt) !== '' ? $updatedAt : gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE qiling_users
             SET token_version = token_version + 1,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'updated_at' => $ts,
            'id' => $userId,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function permissions(array $user): array
    {
        $raw = $user['permissions'] ?? null;
        if (is_array($raw)) {
            $items = [];
            foreach ($raw as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $perm = trim($item);
                if ($perm !== '') {
                    $items[] = $perm;
                }
            }
            return array_values(array_unique($items));
        }
        return [];
    }

    public static function hasPermission(array $user, string $permission): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return true;
        }

        $roleKey = (string) ($user['role_key'] ?? '');
        if ($roleKey === 'admin') {
            return true;
        }

        $permissions = self::permissions($user);
        if ($permissions === []) {
            return false;
        }

        if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) {
            return true;
        }

        $parts = explode('.', $permission);
        while (count($parts) > 1) {
            array_pop($parts);
            $wildcard = implode('.', $parts) . '.*';
            if (in_array($wildcard, $permissions, true)) {
                return true;
            }
        }

        $module = explode('.', $permission, 2)[0] ?? '';
        if ($module !== '' && in_array($module, $permissions, true)) {
            return true;
        }

        return false;
    }

    public static function requirePermission(array $user, string $permission, string $message = 'Forbidden'): void
    {
        if (self::hasPermission($user, $permission)) {
            return;
        }

        Response::json(['message' => $message], 403);
        exit;
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

    /**
     * @return array<int, string>
     */
    private static function decodePermissions(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $perm = trim($item);
            if ($perm !== '') {
                $items[] = $perm;
            }
        }

        return array_values(array_unique($items));
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, self::$columnCache)) {
            return self::$columnCache[$cacheKey];
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        self::$columnCache[$cacheKey] = (int) $stmt->fetchColumn() > 0;
        return self::$columnCache[$cacheKey];
    }
}
