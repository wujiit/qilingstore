<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Auth;
use Qiling\Core\Config;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class AuthController
{
    /** @var array{has_lock:bool,has_token_version:bool}|null */
    private static ?array $loginSchema = null;
    private static ?bool $hasLoginIpGuardTable = null;

    public static function login(): void
    {
        self::sendNoStoreHeaders();
        $data = Request::jsonBody();
        $username = Request::str($data, 'username');
        $password = Request::str($data, 'password');

        if ($username === '' || $password === '') {
            Response::json(['message' => 'username and password are required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $schema = self::resolveLoginSchema($pdo);
        $hasLockSecurity = $schema['has_lock'];
        $hasTokenVersion = $schema['has_token_version'];
        $now = gmdate('Y-m-d H:i:s');
        $clientIp = self::resolveClientIp();
        $maxFailedAttempts = self::maxFailedAttempts();
        $lockSeconds = self::lockSeconds();

        $pdo->beginTransaction();
        try {
            if (!self::consumeLoginIpRateLimit($pdo, $clientIp, $now)) {
                $pdo->commit();
                Response::json(['message' => 'too many requests'], 429);
                return;
            }

            $columns = 'u.id, u.username, u.email, u.password_hash, u.role_key, u.status';
            if ($hasLockSecurity) {
                $columns .= ', u.login_failed_attempts, u.login_lock_until';
            } else {
                $columns .= ', 0 AS login_failed_attempts, NULL AS login_lock_until';
            }
            if ($hasTokenVersion) {
                $columns .= ', u.token_version';
            } else {
                $columns .= ', 1 AS token_version';
            }

            $stmt = $pdo->prepare(
                'SELECT ' . $columns . '
                 FROM qiling_users u
                 WHERE u.username = :username
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                'username' => $username,
            ]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($hasLockSecurity && is_array($user)) {
                $lockUntil = (string) ($user['login_lock_until'] ?? '');
                if ($lockUntil !== '') {
                    $lockUntilTs = strtotime($lockUntil);
                    if ($lockUntilTs !== false && $lockUntilTs > time()) {
                        $pdo->commit();
                        // Keep login error surface uniform to reduce account probing.
                        Response::json(['message' => 'invalid credentials'], 401);
                        return;
                    }
                }
            }

            $passwordMatched = false;
            if (is_array($user)) {
                $passwordMatched = password_verify($password, (string) $user['password_hash']);
            } else {
                // Consume similar hash verify cost for nonexistent users.
                password_verify($password, self::fakePasswordHash());
            }

            if (!is_array($user) || !$passwordMatched) {
                if (is_array($user)) {
                    if (!$hasLockSecurity) {
                        $pdo->commit();
                        Response::json(['message' => 'invalid credentials'], 401);
                        return;
                    }

                    $failedAttempts = max(0, (int) ($user['login_failed_attempts'] ?? 0)) + 1;
                    $lockUntil = null;
                    $statusCode = 401;
                    $payload = ['message' => 'invalid credentials'];

                    if ($failedAttempts >= $maxFailedAttempts) {
                        $lockUntilTs = time() + $lockSeconds;
                        $lockUntil = gmdate('Y-m-d H:i:s', $lockUntilTs);
                        $failedAttempts = 0;
                        $statusCode = 401;
                        $payload = ['message' => 'invalid credentials'];
                    }

                    $updateFailed = $pdo->prepare(
                        'UPDATE qiling_users
                         SET login_failed_attempts = :login_failed_attempts,
                             login_lock_until = :login_lock_until,
                             updated_at = :updated_at
                         WHERE id = :id'
                    );
                    $updateFailed->execute([
                        'login_failed_attempts' => $failedAttempts,
                        'login_lock_until' => $lockUntil,
                        'updated_at' => $now,
                        'id' => (int) $user['id'],
                    ]);
                    $pdo->commit();
                    Response::json($payload, $statusCode);
                    return;
                }

                $pdo->commit();
                Response::json(['message' => 'invalid credentials'], 401);
                return;
            }

            if (($user['status'] ?? '') !== 'active') {
                $pdo->commit();
                Response::json(['message' => 'invalid credentials'], 401);
                return;
            }

            $token = Auth::issueToken((int) $user['id'], (int) ($user['token_version'] ?? 1));
            if ($token === '') {
                $pdo->commit();
                Response::json(['message' => 'APP_KEY is missing'], 500);
                return;
            }

            if ($hasLockSecurity) {
                $updateSuccess = $pdo->prepare(
                    'UPDATE qiling_users
                     SET login_failed_attempts = 0,
                         login_lock_until = NULL,
                         last_login_at = :last_login_at,
                         last_login_ip = :last_login_ip,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateSuccess->execute([
                    'last_login_at' => $now,
                    'last_login_ip' => $clientIp,
                    'updated_at' => $now,
                    'id' => (int) $user['id'],
                ]);
            } else {
                $updateSuccess = $pdo->prepare(
                    'UPDATE qiling_users
                     SET updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateSuccess->execute([
                    'updated_at' => $now,
                    'id' => (int) $user['id'],
                ]);
            }

            $staffStoreStmt = $pdo->prepare(
                'SELECT COALESCE(store_id, 0)
                 FROM qiling_staff
                 WHERE user_id = :user_id
                   AND status = :status
                 LIMIT 1'
            );
            $staffStoreStmt->execute([
                'user_id' => (int) $user['id'],
                'status' => 'active',
            ]);
            $staffStoreId = (int) ($staffStoreStmt->fetchColumn() ?: 0);

            $rolePermissionStmt = $pdo->prepare(
                'SELECT permissions_json
                 FROM qiling_roles
                 WHERE role_key = :role_key
                   AND status = :status
                 LIMIT 1'
            );
            $rolePermissionStmt->execute([
                'role_key' => (string) ($user['role_key'] ?? ''),
                'status' => 'active',
            ]);
            $permissionsRaw = $rolePermissionStmt->fetchColumn();
            $permissions = self::decodePermissions($permissionsRaw);

            $pdo->commit();

            Response::json([
                'token' => $token,
                'user' => [
                    'id' => (int) $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role_key' => $user['role_key'],
                    'status' => $user['status'],
                    'staff_store_id' => $staffStoreId,
                    'permissions' => $permissions,
                ],
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('login failed', $e);
        }
    }

    public static function me(): void
    {
        self::sendNoStoreHeaders();
        $user = Auth::requireUser(Auth::userFromBearerToken());
        Response::json(['user' => $user]);
    }

    /**
     * @return array{has_lock:bool,has_token_version:bool}
     */
    private static function resolveLoginSchema(PDO $pdo): array
    {
        if (is_array(self::$loginSchema)) {
            return self::$loginSchema;
        }

        $hasLock = self::hasColumn($pdo, 'qiling_users', 'login_failed_attempts')
            && self::hasColumn($pdo, 'qiling_users', 'login_lock_until')
            && self::hasColumn($pdo, 'qiling_users', 'last_login_at')
            && self::hasColumn($pdo, 'qiling_users', 'last_login_ip');
        $hasTokenVersion = self::hasColumn($pdo, 'qiling_users', 'token_version');

        self::$loginSchema = [
            'has_lock' => $hasLock,
            'has_token_version' => $hasTokenVersion,
        ];
        return self::$loginSchema;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
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

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $stmt->execute([
            'table_name' => $table,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function maxFailedAttempts(): int
    {
        $raw = (string) Config::get('LOGIN_MAX_FAILED_ATTEMPTS', '5');
        $v = is_numeric($raw) ? (int) $raw : 5;
        return max(3, min($v, 20));
    }

    private static function lockSeconds(): int
    {
        $raw = (string) Config::get('LOGIN_LOCK_SECONDS', '900');
        $v = is_numeric($raw) ? (int) $raw : 900;
        return max(60, min($v, 86400));
    }

    private static function loginIpWindowSeconds(): int
    {
        $raw = (string) Config::get('LOGIN_IP_RATE_LIMIT_WINDOW_SECONDS', '60');
        $v = is_numeric($raw) ? (int) $raw : 60;
        return max(10, min($v, 3600));
    }

    private static function loginIpMaxRequests(): int
    {
        $raw = (string) Config::get('LOGIN_IP_RATE_LIMIT_MAX_REQUESTS', '30');
        $v = is_numeric($raw) ? (int) $raw : 30;
        return max(5, min($v, 300));
    }

    private static function loginIpLockSeconds(): int
    {
        $raw = (string) Config::get('LOGIN_IP_RATE_LIMIT_LOCK_SECONDS', '600');
        $v = is_numeric($raw) ? (int) $raw : 600;
        return max(30, min($v, 86400));
    }

    private static function hasLoginIpGuardTable(PDO $pdo): bool
    {
        if (is_bool(self::$hasLoginIpGuardTable)) {
            return self::$hasLoginIpGuardTable;
        }
        self::$hasLoginIpGuardTable = self::tableExists($pdo, 'qiling_auth_ip_guards');
        return self::$hasLoginIpGuardTable;
    }

    private static function consumeLoginIpRateLimit(PDO $pdo, string $clientIp, string $now): bool
    {
        if ($clientIp === '' || $clientIp === 'unknown') {
            return true;
        }
        if (!self::hasLoginIpGuardTable($pdo)) {
            // Backward compatible: if table not migrated yet, skip IP throttle.
            return true;
        }

        $select = $pdo->prepare(
            'SELECT ip_address, window_started_at, window_request_count, locked_until
             FROM qiling_auth_ip_guards
             WHERE ip_address = :ip_address
             LIMIT 1
             FOR UPDATE'
        );
        $select->execute(['ip_address' => $clientIp]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        $nowTs = time();
        $windowSeconds = self::loginIpWindowSeconds();
        $maxRequests = self::loginIpMaxRequests();
        $lockSeconds = self::loginIpLockSeconds();

        if (!is_array($row)) {
            $insert = $pdo->prepare(
                'INSERT INTO qiling_auth_ip_guards
                 (ip_address, window_started_at, window_request_count, locked_until, created_at, updated_at)
                 VALUES
                 (:ip_address, :window_started_at, :window_request_count, NULL, :created_at, :updated_at)'
            );
            $insert->execute([
                'ip_address' => $clientIp,
                'window_started_at' => $now,
                'window_request_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return true;
        }

        $lockedUntilRaw = trim((string) ($row['locked_until'] ?? ''));
        if ($lockedUntilRaw !== '') {
            $lockedUntilTs = strtotime($lockedUntilRaw);
            if ($lockedUntilTs !== false && $lockedUntilTs > $nowTs) {
                return false;
            }
        }

        $windowStartedRaw = trim((string) ($row['window_started_at'] ?? ''));
        $windowStartedTs = $windowStartedRaw !== '' ? strtotime($windowStartedRaw) : false;
        $requestCount = max(0, (int) ($row['window_request_count'] ?? 0));

        if ($windowStartedTs === false || ($nowTs - $windowStartedTs) >= $windowSeconds) {
            $requestCount = 1;
            $windowStarted = $now;
        } else {
            $requestCount++;
            $windowStarted = gmdate('Y-m-d H:i:s', $windowStartedTs);
        }

        if ($requestCount > $maxRequests) {
            $lockUntil = gmdate('Y-m-d H:i:s', $nowTs + $lockSeconds);
            $update = $pdo->prepare(
                'UPDATE qiling_auth_ip_guards
                 SET window_started_at = :window_started_at,
                     window_request_count = :window_request_count,
                     locked_until = :locked_until,
                     updated_at = :updated_at
                 WHERE ip_address = :ip_address'
            );
            $update->execute([
                'window_started_at' => $now,
                'window_request_count' => 0,
                'locked_until' => $lockUntil,
                'updated_at' => $now,
                'ip_address' => $clientIp,
            ]);
            return false;
        }

        $update = $pdo->prepare(
            'UPDATE qiling_auth_ip_guards
             SET window_started_at = :window_started_at,
                 window_request_count = :window_request_count,
                 locked_until = NULL,
                 updated_at = :updated_at
             WHERE ip_address = :ip_address'
        );
        $update->execute([
            'window_started_at' => $windowStarted,
            'window_request_count' => $requestCount,
            'updated_at' => $now,
            'ip_address' => $clientIp,
        ]);
        return true;
    }

    private static function resolveClientIp(): string
    {
        $trustProxy = in_array(
            strtolower(trim((string) Config::get('TRUST_PROXY_HEADERS', 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        if ($trustProxy) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $parts = explode(',', $forwarded);
                $ip = trim((string) ($parts[0] ?? ''));
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }

            $real = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
            $real = trim($real);
            if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
                return $real;
            }
        }

        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }
        return 'unknown';
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

    private static function fakePasswordHash(): string
    {
        /** @var string|null $hash */
        static $hash = null;
        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        $generated = password_hash('qiling_fake_password_probe_only', PASSWORD_BCRYPT);
        $hash = is_string($generated) && $generated !== '' ? $generated : 'x';
        return $hash;
    }

    private static function sendNoStoreHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
