<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use RuntimeException;

final class PasswordResetService
{
    private const REQUEST_CHANNEL_EMAIL = 'email';

    private static bool $tableEnsured = false;
    private static bool $cleanupDone = false;
    /** @var bool|null */
    private static ?bool $hasLoginSecurityColumns = null;

    public static function requestEmailReset(PDO $pdo, string $account, string $email, string $requestIp): void
    {
        self::ensureTable($pdo);
        self::cleanupStaleRequests($pdo);

        if (!self::emailResetEnabled()) {
            return;
        }

        $account = trim($account);
        $email = strtolower(trim($email));
        if ($account === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $user = self::findActiveUser($pdo, $account, $email);
        if (!is_array($user)) {
            return;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        if (self::isIpRateLimited($pdo, $requestIp) || self::isUserRateLimited($pdo, $userId)) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $expireAt = gmdate('Y-m-d H:i:s', time() + self::codeTtlSeconds());
        $code = self::generateCode();

        // Keep only the latest active code for one account.
        $expireOld = $pdo->prepare(
            'UPDATE qiling_password_reset_requests
             SET used_at = :used_at,
                 updated_at = :updated_at
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $expireOld->execute([
            'used_at' => $now,
            'updated_at' => $now,
            'user_id' => $userId,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO qiling_password_reset_requests
             (user_id, channel, receiver, code_hash, expire_at, used_at, fail_count, request_ip, requested_at, created_at, updated_at)
             VALUES
             (:user_id, :channel, :receiver, :code_hash, :expire_at, NULL, 0, :request_ip, :requested_at, :created_at, :updated_at)'
        );
        $insert->execute([
            'user_id' => $userId,
            'channel' => self::REQUEST_CHANNEL_EMAIL,
            'receiver' => (string) ($user['email'] ?? ''),
            'code_hash' => self::hashCode($code),
            'expire_at' => $expireAt,
            'request_ip' => self::safeIp($requestIp),
            'requested_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        $sent = self::sendVerificationEmail(
            (string) ($user['email'] ?? ''),
            (string) ($user['username'] ?? ''),
            $code,
            self::codeTtlSeconds()
        );

        if (!$sent) {
            $invalidate = $pdo->prepare(
                'UPDATE qiling_password_reset_requests
                 SET used_at = :used_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $invalidate->execute([
                'used_at' => $now,
                'updated_at' => $now,
                'id' => $requestId,
            ]);
            return;
        }

        Audit::log(0, 'auth.password.reset.request', 'user', $userId, 'Request password reset code', [
            'channel' => self::REQUEST_CHANNEL_EMAIL,
            'request_id' => $requestId,
        ]);
    }

    public static function confirmEmailReset(
        PDO $pdo,
        string $account,
        string $email,
        string $code,
        string $newPassword,
        string $requestIp
    ): int {
        self::ensureTable($pdo);
        self::cleanupStaleRequests($pdo);

        if (!self::emailResetEnabled()) {
            throw new RuntimeException('password reset is disabled');
        }

        $account = trim($account);
        $email = strtolower(trim($email));
        $code = trim($code);
        $newPassword = trim($newPassword);
        if ($account === '' || $email === '' || $code === '' || $newPassword === '') {
            throw new RuntimeException('account, email, code, new_password are required');
        }
        $passwordError = PasswordPolicy::validate($newPassword, 'new_password', [
            'account' => $account,
            'email' => $email,
        ]);
        if ($passwordError !== null) {
            throw new RuntimeException($passwordError);
        }

        $user = self::findActiveUser($pdo, $account, $email);
        if (!is_array($user)) {
            throw new RuntimeException('invalid reset code');
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('invalid reset code');
        }

        $hasLoginSecurityColumns = self::loginSecurityColumnsReady($pdo);

        $now = gmdate('Y-m-d H:i:s');
        $hash = self::hashCode($code);
        $maxFails = self::confirmMaxFails();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id, code_hash, fail_count, expire_at
                 FROM qiling_password_reset_requests
                 WHERE user_id = :user_id
                   AND channel = :channel
                   AND receiver = :receiver
                   AND used_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                'user_id' => $userId,
                'channel' => self::REQUEST_CHANNEL_EMAIL,
                'receiver' => $email,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('invalid reset code');
            }

            $requestId = (int) ($row['id'] ?? 0);
            $failCount = max(0, (int) ($row['fail_count'] ?? 0));
            $expireAt = (string) ($row['expire_at'] ?? '');
            $expired = $expireAt !== '' && strtotime($expireAt) !== false && (int) strtotime($expireAt) < time();
            if ($expired || $failCount >= $maxFails) {
                $invalidate = $pdo->prepare(
                    'UPDATE qiling_password_reset_requests
                     SET used_at = :used_at,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $invalidate->execute([
                    'used_at' => $now,
                    'updated_at' => $now,
                    'id' => $requestId,
                ]);
                throw new RuntimeException('invalid reset code');
            }

            $storedHash = (string) ($row['code_hash'] ?? '');
            if (!hash_equals($storedHash, $hash)) {
                $nextFail = $failCount + 1;
                $failUpdate = $pdo->prepare(
                    'UPDATE qiling_password_reset_requests
                     SET fail_count = :fail_count,
                         used_at = :used_at,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $failUpdate->execute([
                    'fail_count' => $nextFail,
                    'used_at' => $nextFail >= $maxFails ? $now : null,
                    'updated_at' => $now,
                    'id' => $requestId,
                ]);
                throw new RuntimeException('invalid reset code');
            }

            $updateSql = 'UPDATE qiling_users
                 SET password_hash = :password_hash';
            if ($hasLoginSecurityColumns) {
                $updateSql .= ',
                     login_failed_attempts = 0,
                     login_lock_until = NULL';
            }
            $updateSql .= ',
                     updated_at = :updated_at
                 WHERE id = :id';
            $updateUser = $pdo->prepare($updateSql);
            $updateUser->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                'updated_at' => $now,
                'id' => $userId,
            ]);
            Auth::bumpTokenVersion($pdo, $userId, $now);

            $consume = $pdo->prepare(
                'UPDATE qiling_password_reset_requests
                 SET used_at = :used_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $consume->execute([
                'used_at' => $now,
                'updated_at' => $now,
                'id' => $requestId,
            ]);

            Audit::log(0, 'auth.password.reset.success', 'user', $userId, 'Reset password by email code', [
                'request_id' => $requestId,
                'request_ip' => self::safeIp($requestIp),
            ]);

            $pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('password reset failed');
        }
    }

    public static function resolveClientIp(): string
    {
        $trustProxy = self::toBool((string) Config::get('TRUST_PROXY_HEADERS', 'false'));
        if ($trustProxy) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $parts = explode(',', $forwarded);
                $ip = trim((string) ($parts[0] ?? ''));
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }

            $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
            if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
                return $realIp;
            }
        }

        $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false) {
            return $remoteAddr;
        }
        return 'unknown';
    }

    private static function emailResetEnabled(): bool
    {
        return self::toBool((string) Config::get('PASSWORD_RESET_EMAIL_ENABLED', 'false'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findActiveUser(PDO $pdo, string $account, string $email): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, username, email, status
             FROM qiling_users
             WHERE status = :status
               AND email = :email
               AND (username = :account OR email = :account)
             LIMIT 1'
        );
        $stmt->execute([
            'status' => 'active',
            'email' => $email,
            'account' => $account,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function isIpRateLimited(PDO $pdo, string $requestIp): bool
    {
        $windowSeconds = self::requestWindowSeconds();
        $max = self::requestMaxPerIp();
        if ($max <= 0) {
            return false;
        }

        $from = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM qiling_password_reset_requests
             WHERE request_ip = :request_ip
               AND requested_at >= :from_time'
        );
        $stmt->execute([
            'request_ip' => self::safeIp($requestIp),
            'from_time' => $from,
        ]);

        return (int) $stmt->fetchColumn() >= $max;
    }

    private static function isUserRateLimited(PDO $pdo, int $userId): bool
    {
        $windowSeconds = self::requestWindowSeconds();
        $max = self::requestMaxPerAccount();
        if ($max <= 0) {
            return false;
        }

        $from = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM qiling_password_reset_requests
             WHERE user_id = :user_id
               AND requested_at >= :from_time'
        );
        $stmt->execute([
            'user_id' => $userId,
            'from_time' => $from,
        ]);

        return (int) $stmt->fetchColumn() >= $max;
    }

    private static function hashCode(string $code): string
    {
        $appKey = (string) Config::get('APP_KEY', '');
        $secret = $appKey !== '' ? $appKey : 'qiling-password-reset';
        return hash_hmac('sha256', $code, $secret);
    }

    private static function generateCode(): string
    {
        $n = random_int(0, 999999);
        return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private static function sendVerificationEmail(string $to, string $username, string $code, int $ttlSeconds): bool
    {
        $to = trim($to);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $from = trim((string) Config::get('PASSWORD_RESET_MAIL_FROM', ''));
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $from = 'no-reply@localhost';
        }

        $subjectPrefix = trim((string) Config::get('PASSWORD_RESET_MAIL_SUBJECT_PREFIX', '[QILING]'));
        $subjectRaw = ($subjectPrefix !== '' ? ($subjectPrefix . ' ') : '') . '管理员密码找回验证码';
        $subject = '=?UTF-8?B?' . base64_encode($subjectRaw) . '?=';

        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        $safeUser = $username !== '' ? $username : '管理员';
        $body = "你好，{$safeUser}：\n\n";
        $body .= "你正在找回启灵系统后台密码。\n";
        $body .= "验证码：{$code}\n";
        $body .= "有效期：{$minutes} 分钟（一次性使用）。\n\n";
        $body .= "如果不是你本人操作，请忽略本邮件，并尽快检查系统安全配置。\n";

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from,
        ];

        return mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private static function ensureTable(PDO $pdo): void
    {
        if (self::$tableEnsured) {
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $stmt->execute([
            'table_name' => 'qiling_password_reset_requests',
        ]);
        if ((int) $stmt->fetchColumn() <= 0) {
            throw new RuntimeException('password reset schema is not ready, please run system upgrade');
        }

        self::$tableEnsured = true;
    }

    private static function cleanupStaleRequests(PDO $pdo): void
    {
        if (self::$cleanupDone) {
            return;
        }
        self::$cleanupDone = true;

        $days = self::retentionDays();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        try {
            $stmt = $pdo->prepare(
                'DELETE FROM qiling_password_reset_requests
                 WHERE (used_at IS NOT NULL AND updated_at < :cutoff_used)
                    OR (expire_at IS NOT NULL AND expire_at < :cutoff_expired)'
            );
            $stmt->execute([
                'cutoff_used' => $cutoff,
                'cutoff_expired' => $cutoff,
            ]);
        } catch (\Throwable) {
            // Best-effort cleanup; do not interrupt request flow.
        }
    }

    private static function loginSecurityColumnsReady(PDO $pdo): bool
    {
        if (self::$hasLoginSecurityColumns !== null) {
            return self::$hasLoginSecurityColumns;
        }

        self::$hasLoginSecurityColumns = self::hasColumn($pdo, 'qiling_users', 'login_failed_attempts')
            && self::hasColumn($pdo, 'qiling_users', 'login_lock_until');
        return self::$hasLoginSecurityColumns;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $check = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $check->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $check->fetchColumn() > 0;
    }

    private static function requestWindowSeconds(): int
    {
        $raw = (string) Config::get('PASSWORD_RESET_REQUEST_WINDOW_SECONDS', '900');
        $v = is_numeric($raw) ? (int) $raw : 900;
        return max(300, min($v, 3600));
    }

    private static function requestMaxPerIp(): int
    {
        $raw = (string) Config::get('PASSWORD_RESET_REQUEST_MAX_PER_IP', '5');
        $v = is_numeric($raw) ? (int) $raw : 5;
        return max(1, min($v, 100));
    }

    private static function requestMaxPerAccount(): int
    {
        $raw = (string) Config::get('PASSWORD_RESET_REQUEST_MAX_PER_ACCOUNT', '3');
        $v = is_numeric($raw) ? (int) $raw : 3;
        return max(1, min($v, 50));
    }

    private static function codeTtlSeconds(): int
    {
        $raw = (string) Config::get('PASSWORD_RESET_CODE_TTL_SECONDS', '600');
        $v = is_numeric($raw) ? (int) $raw : 600;
        return max(60, min($v, 1800));
    }

    private static function confirmMaxFails(): int
    {
        $raw = (string) Config::get('PASSWORD_RESET_CONFIRM_MAX_FAILS', '5');
        $v = is_numeric($raw) ? (int) $raw : 5;
        return max(3, min($v, 10));
    }

    private static function retentionDays(): int
    {
        $raw = (string) Config::get('PASSWORD_RESET_RETENTION_DAYS', '30');
        $v = is_numeric($raw) ? (int) $raw : 30;
        return max(7, min($v, 365));
    }

    private static function safeIp(string $requestIp): string
    {
        $ip = trim($requestIp);
        if ($ip === '') {
            return 'unknown';
        }
        if (strlen($ip) > 64) {
            return substr($ip, 0, 64);
        }
        return $ip;
    }

    private static function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
