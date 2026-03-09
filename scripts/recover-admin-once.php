<?php

declare(strict_types=1);

/**
 * 一次性网页找回管理员密码（应急文件）
 *
 * 使用方式：
 * 1) 把本文件上传到服务器 public 目录，例如：public/recover-admin-once.php
 * 2) 修改下面的 $recoverySecret 为高强度随机字符串
 * 3) 可选：配置 $allowedUsernames / $expiresAtUtc / $allowedIps
 * 4) 浏览器访问该文件，输入安全口令 + 管理员账号 + 新密码，提交重置
 * 5) 重置完成后，立刻删除该文件
 */

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\Config;
use Qiling\Core\Database;

$recoverySecret = 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';
$allowForceAdmin = false;
$allowedUsernames = ['admin'];
$expiresAtUtc = ''; // 例如：'2026-12-31 23:59:59'
$allowedIps = [];   // 例如：['127.0.0.1', '10.0.0.8']

$bootstrapPath = '';
$bootstrapCandidates = [
    __DIR__ . '/../src/bootstrap.php',
    __DIR__ . '/../../src/bootstrap.php',
    dirname(__DIR__) . '/src/bootstrap.php',
];
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $bootstrapPath = $candidate;
        break;
    }
}
if ($bootstrapPath === '') {
    http_response_code(500);
    echo 'bootstrap not found. please put this file under project public/ directory.';
    exit;
}

require_once $bootstrapPath;

header('X-Robots-Tag: noindex, nofollow');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($recoverySecret === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET') {
            throw new RuntimeException('请先编辑文件并设置 recoverySecret');
        }
        if ($expiresAtUtc !== '') {
            $expireTs = parseUtcDateTime($expiresAtUtc);
            if ($expireTs <= time()) {
                throw new RuntimeException('恢复文件已过期，请重新部署新文件');
            }
        }
        if (!isAllowedIp($allowedIps, resolveClientIp())) {
            throw new RuntimeException('当前 IP 不允许访问恢复页面');
        }

        $inputSecret = trim((string) ($_POST['secret'] ?? ''));
        if (!hash_equals($recoverySecret, $inputSecret)) {
            throw new RuntimeException('安全口令不正确');
        }

        $username = trim((string) ($_POST['username'] ?? 'admin'));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        if ($username === '') {
            throw new RuntimeException('管理员账号不能为空');
        }
        if (!isAllowedUsername($username, $allowedUsernames)) {
            throw new RuntimeException('当前恢复文件不允许重置该账号');
        }
        if (strlen($newPassword) < 8) {
            throw new RuntimeException('新密码至少 8 位');
        }
        if (!hash_equals($newPassword, $confirmPassword)) {
            throw new RuntimeException('两次输入的新密码不一致');
        }

        $pdo = Database::pdo();
        ensureLoginSecurityColumns($pdo);

        $stmt = $pdo->prepare(
            'SELECT id, username, email, role_key
             FROM qiling_users
             WHERE username = :username
             LIMIT 1
             FOR UPDATE'
        );
        $pdo->beginTransaction();
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw new RuntimeException('管理员账号不存在：' . $username);
        }
        $currentRole = trim((string) ($user['role_key'] ?? ''));
        if (!$allowForceAdmin && $currentRole !== 'admin') {
            throw new RuntimeException('仅允许重置 admin 角色账号；如需强制提权请手动开启 allowForceAdmin');
        }

        $now = gmdate('Y-m-d H:i:s');
        $update = $pdo->prepare(
            'UPDATE qiling_users
             SET password_hash = :password_hash,
                 role_key = :role_key,
                 status = :status,
                 login_failed_attempts = 0,
                 login_lock_until = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'role_key' => $allowForceAdmin ? 'admin' : $currentRole,
            'status' => 'active',
            'updated_at' => $now,
            'id' => (int) $user['id'],
        ]);
        Auth::bumpTokenVersion($pdo, (int) $user['id'], $now);

        Audit::log(0, 'admin.recover_once', 'user', (int) $user['id'], 'Reset admin password by one-time web file', [
            'username' => $username,
            'ip' => resolveClientIp(),
        ]);

        $pdo->commit();
        $success = '重置成功：' . $username . '。请立刻删除该恢复文件。';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

function ensureLoginSecurityColumns(PDO $pdo): void
{
    ensureColumn($pdo, 'qiling_users', 'login_failed_attempts', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_users', 'login_lock_until', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_users', 'last_login_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_users', 'last_login_ip', "VARCHAR(64) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'qiling_users', 'token_version', 'INT NOT NULL DEFAULT 1');
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
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

    if ((int) $check->fetchColumn() > 0) {
        return;
    }

    $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition);
}

function resolveClientIp(): string
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
        $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '') {
            return $realIp;
        }
    }
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote !== '' ? $remote : 'unknown';
}

function isAllowedUsername(string $username, array $allowedUsernames): bool
{
    if ($username === '') {
        return false;
    }
    if ($allowedUsernames === []) {
        return true;
    }

    foreach ($allowedUsernames as $item) {
        if (!is_string($item)) {
            continue;
        }
        if (strcasecmp(trim($item), $username) === 0) {
            return true;
        }
    }

    return false;
}

function isAllowedIp(array $allowedIps, string $ip): bool
{
    if ($allowedIps === []) {
        return true;
    }
    if ($ip === '') {
        return false;
    }

    foreach ($allowedIps as $item) {
        if (!is_string($item)) {
            continue;
        }
        if (trim($item) === $ip) {
            return true;
        }
    }

    return false;
}

function parseUtcDateTime(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $timezone = new DateTimeZone('UTC');
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
    if (!$dt instanceof DateTimeImmutable) {
        return 0;
    }

    return $dt->getTimestamp();
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理员应急找回（一次性）</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f7fa; color: #1f2937; }
        .wrap { max-width: 560px; margin: 36px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #dbe2ea; border-radius: 12px; padding: 18px; box-shadow: 0 8px 30px rgba(15, 23, 42, 0.07); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 8px 0; line-height: 1.6; color: #4b5563; }
        .alert { border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; font-size: 14px; }
        .ok { background: #ecfdf3; border: 1px solid #86efac; color: #166534; }
        .err { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .warn { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; }
        label { display: block; margin-top: 10px; font-size: 14px; color: #334155; }
        input { width: 100%; box-sizing: border-box; margin-top: 6px; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        button { margin-top: 14px; width: 100%; border: 0; border-radius: 8px; padding: 11px; background: #0f766e; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; }
        code { background: #0f172a; color: #bfdbfe; padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>管理员应急找回（一次性）</h1>
        <p>本页面仅用于紧急情况下重置管理员密码。完成后请立刻删除该文件。</p>
        <?php if ($recoverySecret === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET'): ?>
            <div class="alert warn">你还没有修改文件中的 <code>$recoverySecret</code>，当前不可用。</div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post">
            <label>安全口令（文件内配置）</label>
            <input type="password" name="secret" required>

            <label>管理员账号（username）</label>
            <input type="text" name="username" value="admin" required>

            <label>新密码（至少8位）</label>
            <input type="password" name="new_password" minlength="8" required>

            <label>确认新密码</label>
            <input type="password" name="confirm_password" minlength="8" required>

            <button type="submit">立即重置管理员密码</button>
        </form>
        <p>建议流程：重置成功 -> 测试登录 -> 删除本文件。</p>
    </div>
</div>
</body>
</html>
