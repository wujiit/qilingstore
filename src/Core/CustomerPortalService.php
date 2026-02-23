<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class CustomerPortalService
{
    private static bool $tableReady = false;

    public static function ensureTables(PDO $pdo): void
    {
        if (self::$tableReady) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS qiling_customer_portal_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                token_prefix VARCHAR(16) NOT NULL DEFAULT \'\',
                customer_id BIGINT UNSIGNED NOT NULL,
                store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                note VARCHAR(120) NOT NULL DEFAULT \'\',
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                expire_at DATETIME NULL,
                last_used_at DATETIME NULL,
                use_count INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_qiling_customer_portal_tokens_hash (token_hash),
                KEY idx_qiling_customer_portal_tokens_customer_id (customer_id),
                KEY idx_qiling_customer_portal_tokens_store_id (store_id),
                KEY idx_qiling_customer_portal_tokens_status (status),
                KEY idx_qiling_customer_portal_tokens_expire_at (expire_at),
                CONSTRAINT fk_qiling_customer_portal_tokens_customer FOREIGN KEY (customer_id) REFERENCES qiling_customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS qiling_customer_portal_ip_guards (
                ip_address VARCHAR(64) NOT NULL,
                fail_count INT NOT NULL DEFAULT 0,
                first_failed_at DATETIME NULL,
                locked_until DATETIME NULL,
                window_started_at DATETIME NULL,
                window_request_count INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (ip_address),
                KEY idx_qiling_customer_portal_ip_guards_locked_until (locked_until),
                KEY idx_qiling_customer_portal_ip_guards_window_started_at (window_started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        self::$tableReady = true;
    }

    public static function hashToken(string $token): string
    {
        $appKey = (string) Config::get('APP_KEY', '');
        if ($appKey !== '') {
            return hash_hmac('sha256', $token, $appKey);
        }

        return hash('sha256', $token);
    }

    public static function generateToken(): string
    {
        $len = random_int(4, 6);
        $min = 10 ** ($len - 1);
        $max = (10 ** $len) - 1;
        return (string) random_int($min, $max);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findCustomer(PDO $pdo, int $customerId, string $customerNo, string $mobile): ?array
    {
        if ($customerId <= 0 && $customerNo === '' && $mobile === '') {
            return null;
        }

        $sql = 'SELECT c.id, c.customer_no, c.store_id, c.name, c.mobile, c.status, s.store_name
                FROM qiling_customers c
                LEFT JOIN qiling_stores s ON s.id = c.store_id
                WHERE 1 = 1';
        $params = [];

        if ($customerId > 0) {
            $sql .= ' AND c.id = :id';
            $params['id'] = $customerId;
        }
        if ($customerNo !== '') {
            $sql .= ' AND c.customer_no = :customer_no';
            $params['customer_no'] = $customerNo;
        }
        if ($mobile !== '') {
            $sql .= ' AND c.mobile = :mobile';
            $params['mobile'] = $mobile;
        }

        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function createToken(
        PDO $pdo,
        int $customerId,
        int $storeId,
        int $creatorUserId,
        ?string $expireAt,
        string $note = '',
        string $customToken = ''
    ): array {
        self::ensureTables($pdo);
        $specifiedToken = trim($customToken);
        if ($specifiedToken !== '' && !self::isNumericPinToken($specifiedToken)) {
            throw new \RuntimeException('token must be 4-6 digits');
        }
        $attempts = $specifiedToken !== '' ? 1 : 240;

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_customer_portal_tokens
             (token_hash, token_prefix, customer_id, store_id, created_by, note, status, expire_at, last_used_at, use_count, created_at, updated_at)
             VALUES
             (:token_hash, :token_prefix, :customer_id, :store_id, :created_by, :note, :status, :expire_at, NULL, 0, :created_at, :updated_at)'
        );

        for ($i = 0; $i < $attempts; $i++) {
            $token = $specifiedToken !== '' ? $specifiedToken : self::generateToken();
            $tokenHash = self::hashToken($token);
            $tokenPrefix = substr($token, 0, 16);
            $now = gmdate('Y-m-d H:i:s');

            try {
                $stmt->execute([
                    'token_hash' => $tokenHash,
                    'token_prefix' => $tokenPrefix,
                    'customer_id' => $customerId,
                    'store_id' => $storeId,
                    'created_by' => $creatorUserId > 0 ? $creatorUserId : null,
                    'note' => $note,
                    'status' => 'active',
                    'expire_at' => $expireAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return [
                    'id' => (int) $pdo->lastInsertId(),
                    'token' => $token,
                    'token_prefix' => $tokenPrefix,
                    'expire_at' => $expireAt,
                    'status' => 'active',
                ];
            } catch (\PDOException $e) {
                $message = strtolower((string) $e->getMessage());
                $isDup = str_contains($message, '1062') || str_contains($message, 'duplicate');
                if (!$isDup) {
                    throw $e;
                }
                if ($specifiedToken !== '') {
                    throw new \RuntimeException('token already exists');
                }
            }
        }

        throw new \RuntimeException('create token failed');
    }

    private static function isNumericPinToken(string $token): bool
    {
        return preg_match('/^\d{4,6}$/', $token) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveActiveToken(PDO $pdo, string $token): ?array
    {
        self::ensureTables($pdo);

        $tokenHash = self::hashToken($token);
        $stmt = $pdo->prepare(
            'SELECT t.id, t.customer_id, t.store_id, t.status, t.expire_at, t.last_used_at, t.use_count, t.note,
                    c.customer_no, c.name AS customer_name, c.mobile AS customer_mobile, c.status AS customer_status,
                    s.store_name
             FROM qiling_customer_portal_tokens t
             INNER JOIN qiling_customers c ON c.id = t.customer_id
             LEFT JOIN qiling_stores s ON s.id = c.store_id
             WHERE t.token_hash = :token_hash
               AND t.status = :status
               AND (t.expire_at IS NULL OR t.expire_at >= :now)
             LIMIT 1'
        );
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'token_hash' => $tokenHash,
            'status' => 'active',
            'now' => $now,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function touchTokenUse(PDO $pdo, int $tokenId): void
    {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare(
            'UPDATE qiling_customer_portal_tokens
             SET use_count = use_count + 1,
                 last_used_at = :last_used_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'id' => $tokenId,
            'last_used_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findTokenById(PDO $pdo, int $tokenId): ?array
    {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare(
            'SELECT id, customer_id, store_id, status, expire_at, last_used_at, use_count, note, created_at
             FROM qiling_customer_portal_tokens
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $tokenId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function revokeToken(PDO $pdo, int $tokenId): void
    {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare(
            'UPDATE qiling_customer_portal_tokens
             SET status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $tokenId,
            'status' => 'revoked',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function revokeActiveTokensByCustomer(PDO $pdo, int $customerId): int
    {
        self::ensureTables($pdo);
        if ($customerId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'UPDATE qiling_customer_portal_tokens
             SET status = :status,
                 updated_at = :updated_at
             WHERE customer_id = :customer_id
               AND status = :status_active'
        );
        $stmt->execute([
            'status' => 'revoked',
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'customer_id' => $customerId,
            'status_active' => 'active',
        ]);

        return $stmt->rowCount();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listTokens(PDO $pdo, ?int $scopeStoreId, ?int $customerId, string $status, int $limit): array
    {
        self::ensureTables($pdo);

        $sql = 'SELECT t.id, t.customer_id, t.store_id, t.status, t.expire_at, t.last_used_at, t.use_count, t.note, t.created_at,
                       c.customer_no, c.name AS customer_name, c.mobile AS customer_mobile, s.store_name
                FROM qiling_customer_portal_tokens t
                INNER JOIN qiling_customers c ON c.id = t.customer_id
                LEFT JOIN qiling_stores s ON s.id = t.store_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND t.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($customerId !== null && $customerId > 0) {
            $sql .= ' AND t.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($status !== '') {
            if ($status === 'expired') {
                $sql .= ' AND t.status = :status_active AND t.expire_at IS NOT NULL AND t.expire_at < :now';
                $params['status_active'] = 'active';
                $params['now'] = gmdate('Y-m-d H:i:s');
            } else {
                $sql .= ' AND t.status = :status';
                $params['status'] = $status;
            }
        }

        $limit = max(1, min($limit, 300));
        $sql .= ' ORDER BY t.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listIpGuards(PDO $pdo, bool $lockedOnly, string $ipFilter, int $limit): array
    {
        self::ensureTables($pdo);

        $sql = 'SELECT ip_address, fail_count, first_failed_at, locked_until, window_started_at, window_request_count, updated_at
                FROM qiling_customer_portal_ip_guards
                WHERE 1 = 1';
        $params = [];

        if ($lockedOnly) {
            $sql .= ' AND locked_until IS NOT NULL AND locked_until > :now';
            $params['now'] = gmdate('Y-m-d H:i:s');
        }

        $ipFilter = trim($ipFilter);
        if ($ipFilter !== '') {
            $sql .= ' AND ip_address LIKE :ip_address';
            $params['ip_address'] = '%' . $ipFilter . '%';
        }

        $limit = max(1, min($limit, 300));
        $sql .= ' ORDER BY updated_at DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listTokenLockGuards(PDO $pdo, ?int $scopeStoreId, ?int $customerId, bool $lockedOnly, int $limit): array
    {
        self::ensureTables($pdo);

        $sql = 'SELECT g.ip_address AS guard_key, g.fail_count, g.first_failed_at, g.locked_until, g.updated_at,
                       t.id AS token_id, t.token_prefix, t.customer_id, t.store_id, t.status AS token_status, t.expire_at,
                       c.customer_no, c.name AS customer_name, c.mobile AS customer_mobile, s.store_name
                FROM qiling_customer_portal_ip_guards g
                INNER JOIN qiling_customer_portal_tokens t ON t.token_hash = g.ip_address
                INNER JOIN qiling_customers c ON c.id = t.customer_id
                LEFT JOIN qiling_stores s ON s.id = t.store_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND t.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($customerId !== null && $customerId > 0) {
            $sql .= ' AND t.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($lockedOnly) {
            $sql .= ' AND g.locked_until IS NOT NULL AND g.locked_until > :now';
            $params['now'] = gmdate('Y-m-d H:i:s');
        }

        $limit = max(1, min($limit, 300));
        $sql .= ' ORDER BY g.updated_at DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public static function unlockTokenGuardsByCustomer(PDO $pdo, int $customerId): int
    {
        self::ensureTables($pdo);
        if ($customerId <= 0) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE qiling_customer_portal_ip_guards g
             INNER JOIN qiling_customer_portal_tokens t ON t.token_hash = g.ip_address
             SET g.fail_count = 0,
                 g.first_failed_at = NULL,
                 g.locked_until = NULL,
                 g.window_started_at = :window_started_at,
                 g.window_request_count = 0,
                 g.updated_at = :updated_at
             WHERE t.customer_id = :customer_id'
        );
        $stmt->execute([
            'window_started_at' => $now,
            'updated_at' => $now,
            'customer_id' => $customerId,
        ]);

        return $stmt->rowCount();
    }

    public static function unlockIpGuard(PDO $pdo, string $ip): bool
    {
        self::ensureTables($pdo);
        $ip = self::normalizeIpAddress($ip);
        if ($ip === '') {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE qiling_customer_portal_ip_guards
             SET fail_count = 0,
                 first_failed_at = NULL,
                 locked_until = NULL,
                 window_started_at = :window_started_at,
                 window_request_count = 0,
                 updated_at = :updated_at
             WHERE ip_address = :ip_address'
        );
        $stmt->execute([
            'window_started_at' => $now,
            'updated_at' => $now,
            'ip_address' => $ip,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{allowed:bool,retry_after_seconds:int}
     */
    public static function checkAccessRateLimit(PDO $pdo, string $ip): array
    {
        self::ensureTables($pdo);
        $ip = self::normalizeIpAddress($ip);
        $nowTs = time();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $windowSeconds = self::rateLimitWindowSeconds();
        $maxRequests = self::rateLimitMaxRequests();

        $stmt = $pdo->prepare(
            'SELECT fail_count, first_failed_at, locked_until, window_started_at, window_request_count
             FROM qiling_customer_portal_ip_guards
             WHERE ip_address = :ip
             LIMIT 1'
        );
        $stmt->execute(['ip' => $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $insert = $pdo->prepare(
                'INSERT INTO qiling_customer_portal_ip_guards
                 (ip_address, fail_count, first_failed_at, locked_until, window_started_at, window_request_count, created_at, updated_at)
                 VALUES
                 (:ip_address, 0, NULL, NULL, :window_started_at, :window_request_count, :created_at, :updated_at)'
            );
            $insert->execute([
                'ip_address' => $ip,
                'window_started_at' => $now,
                'window_request_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'allowed' => true,
                'retry_after_seconds' => 0,
            ];
        }

        $lockedUntilTs = strtotime((string) ($row['locked_until'] ?? ''));
        if ($lockedUntilTs !== false && $lockedUntilTs > $nowTs) {
            return [
                'allowed' => false,
                'retry_after_seconds' => max(1, $lockedUntilTs - $nowTs),
            ];
        }

        $windowStartedAt = (string) ($row['window_started_at'] ?? '');
        $windowStartedTs = strtotime($windowStartedAt);
        $withinWindow = $windowStartedTs !== false && ($nowTs - $windowStartedTs) < $windowSeconds;

        $nextCount = $withinWindow ? ((int) ($row['window_request_count'] ?? 0) + 1) : 1;
        $nextWindowStarted = $withinWindow ? $windowStartedAt : $now;

        $update = $pdo->prepare(
            'UPDATE qiling_customer_portal_ip_guards
             SET window_started_at = :window_started_at,
                 window_request_count = :window_request_count,
                 updated_at = :updated_at
             WHERE ip_address = :ip_address'
        );
        $update->execute([
            'window_started_at' => $nextWindowStarted,
            'window_request_count' => $nextCount,
            'updated_at' => $now,
            'ip_address' => $ip,
        ]);

        if ($nextCount > $maxRequests) {
            $startTs = strtotime($nextWindowStarted);
            $elapsed = $startTs === false ? 0 : max(0, $nowTs - $startTs);
            return [
                'allowed' => false,
                'retry_after_seconds' => max(1, $windowSeconds - $elapsed),
            ];
        }

        return [
            'allowed' => true,
            'retry_after_seconds' => 0,
        ];
    }

    /**
     * @return array{locked:bool,retry_after_seconds:int}
     */
    public static function recordAuthFailure(PDO $pdo, string $ip): array
    {
        self::ensureTables($pdo);
        $ip = self::normalizeIpAddress($ip);
        $nowTs = time();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $lockSeconds = self::lockSeconds();
        $maxFailedAttempts = self::maxFailedAttempts();

        $stmt = $pdo->prepare(
            'SELECT fail_count, first_failed_at, locked_until
             FROM qiling_customer_portal_ip_guards
             WHERE ip_address = :ip
             LIMIT 1'
        );
        $stmt->execute(['ip' => $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $insert = $pdo->prepare(
                'INSERT INTO qiling_customer_portal_ip_guards
                 (ip_address, fail_count, first_failed_at, locked_until, window_started_at, window_request_count, created_at, updated_at)
                 VALUES
                 (:ip_address, :fail_count, :first_failed_at, NULL, :window_started_at, :window_request_count, :created_at, :updated_at)'
            );
            $insert->execute([
                'ip_address' => $ip,
                'fail_count' => 1,
                'first_failed_at' => $now,
                'window_started_at' => $now,
                'window_request_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'locked' => false,
                'retry_after_seconds' => 0,
            ];
        }

        $lockedUntilTs = strtotime((string) ($row['locked_until'] ?? ''));
        if ($lockedUntilTs !== false && $lockedUntilTs > $nowTs) {
            return [
                'locked' => true,
                'retry_after_seconds' => max(1, $lockedUntilTs - $nowTs),
            ];
        }

        $firstFailedAt = (string) ($row['first_failed_at'] ?? '');
        $firstFailedTs = strtotime($firstFailedAt);
        $currentFailCount = (int) ($row['fail_count'] ?? 0);
        if ($firstFailedTs === false || ($nowTs - $firstFailedTs) > $lockSeconds) {
            $currentFailCount = 0;
            $firstFailedAt = $now;
        }

        $nextFailCount = $currentFailCount + 1;
        if ($nextFailCount >= $maxFailedAttempts) {
            $lockUntil = gmdate('Y-m-d H:i:s', $nowTs + $lockSeconds);
            $update = $pdo->prepare(
                'UPDATE qiling_customer_portal_ip_guards
                 SET fail_count = 0,
                     first_failed_at = NULL,
                     locked_until = :locked_until,
                     updated_at = :updated_at
                 WHERE ip_address = :ip_address'
            );
            $update->execute([
                'locked_until' => $lockUntil,
                'updated_at' => $now,
                'ip_address' => $ip,
            ]);

            return [
                'locked' => true,
                'retry_after_seconds' => $lockSeconds,
            ];
        }

        $update = $pdo->prepare(
            'UPDATE qiling_customer_portal_ip_guards
             SET fail_count = :fail_count,
                 first_failed_at = :first_failed_at,
                 locked_until = NULL,
                 updated_at = :updated_at
             WHERE ip_address = :ip_address'
        );
        $update->execute([
            'fail_count' => $nextFailCount,
            'first_failed_at' => $firstFailedAt,
            'updated_at' => $now,
            'ip_address' => $ip,
        ]);

        return [
            'locked' => false,
            'retry_after_seconds' => 0,
        ];
    }

    public static function clearAuthFailures(PDO $pdo, string $ip): void
    {
        self::ensureTables($pdo);
        $ip = self::normalizeIpAddress($ip);

        $stmt = $pdo->prepare(
            'UPDATE qiling_customer_portal_ip_guards
             SET fail_count = 0,
                 first_failed_at = NULL,
                 locked_until = NULL,
                 updated_at = :updated_at
             WHERE ip_address = :ip_address'
        );
        $stmt->execute([
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'ip_address' => $ip,
        ]);
    }

    private static function normalizeIpAddress(string $ip): string
    {
        $value = trim($ip);
        if ($value === '') {
            return 'unknown';
        }

        if (strlen($value) > 64) {
            return substr($value, 0, 64);
        }

        return $value;
    }

    private static function maxFailedAttempts(): int
    {
        $raw = (string) Config::get('PORTAL_TOKEN_MAX_FAILED_ATTEMPTS', '8');
        $value = is_numeric($raw) ? (int) $raw : 8;
        return max(3, min($value, 30));
    }

    private static function lockSeconds(): int
    {
        $raw = (string) Config::get('PORTAL_TOKEN_LOCK_SECONDS', '900');
        $value = is_numeric($raw) ? (int) $raw : 900;
        return max(60, min($value, 86400));
    }

    private static function rateLimitWindowSeconds(): int
    {
        $raw = (string) Config::get('PORTAL_TOKEN_RATE_LIMIT_WINDOW_SECONDS', '60');
        $value = is_numeric($raw) ? (int) $raw : 60;
        return max(10, min($value, 3600));
    }

    private static function rateLimitMaxRequests(): int
    {
        $raw = (string) Config::get('PORTAL_TOKEN_RATE_LIMIT_MAX_REQUESTS', '30');
        $value = is_numeric($raw) ? (int) $raw : 30;
        return max(5, min($value, 300));
    }
}
