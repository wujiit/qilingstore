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

    public static function login(): void
    {
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
        $maxFailedAttempts = self::maxFailedAttempts();
        $lockSeconds = self::lockSeconds();

        $pdo->beginTransaction();
        try {
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
                        $retryAfter = max(1, $lockUntilTs - time());
                        $pdo->commit();
                        Response::json([
                            'message' => 'account locked',
                            'retry_after_seconds' => $retryAfter,
                        ], 429);
                        return;
                    }
                }
            }

            if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
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
                        $statusCode = 429;
                        $payload = [
                            'message' => 'account locked due to too many failed attempts',
                            'retry_after_seconds' => $lockSeconds,
                        ];
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
                Response::json(['message' => 'account disabled'], 403);
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
                    'last_login_ip' => self::resolveClientIp(),
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
                if ($ip !== '') {
                    return $ip;
                }
            }

            $real = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
            if ($real !== '') {
                return trim($real);
            }
        }

        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
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
}
