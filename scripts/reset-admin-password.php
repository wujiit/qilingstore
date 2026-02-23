<?php

declare(strict_types=1);

use PDO;
use Qiling\Core\Config;
use Qiling\Core\Database;

$root = dirname(__DIR__);
$envPath = $root . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, ".env not found, please run install first.\n");
    exit(1);
}

require_once $root . '/src/bootstrap.php';

$options = parseOptions($argv);
if (($options['help'] ?? false) === true) {
    printHelp();
    exit(0);
}

$pdo = Database::pdo();
ensureLoginSecurityColumns($pdo);

if (($options['list'] ?? false) === true) {
    listAdmins($pdo);
    exit(0);
}

$usernameInput = trim((string) ($options['username'] ?? ''));
$username = $usernameInput;
if ($username === '') {
    $username = trim((string) Config::get('INSTALL_ADMIN_USERNAME', 'admin'));
}

$password = trim((string) ($options['password'] ?? ''));
if ($password !== '' && strlen($password) < 6) {
    fwrite(STDERR, "--password must be at least 6 chars.\n");
    exit(1);
}

$now = gmdate('Y-m-d H:i:s');
$target = findUserByUsername($pdo, $username);

if (!is_array($target) && $usernameInput === '') {
    $target = findFirstAdminUser($pdo);
}

if ($password === '') {
    $password = generateTempPassword();
}

if (is_array($target)) {
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
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role_key' => 'admin',
        'status' => 'active',
        'updated_at' => $now,
        'id' => (int) $target['id'],
    ]);

    echo "Admin password reset success\n";
    echo "User ID: " . (int) $target['id'] . "\n";
    echo "Username: " . (string) $target['username'] . "\n";
    echo "Temp Password: " . $password . "\n";
    echo "Please login and change password immediately.\n";
    exit(0);
}

$email = trim((string) Config::get('INSTALL_ADMIN_EMAIL', 'admin@qiling.local'));
if ($email === '') {
    $email = 'admin@qiling.local';
}
$email = uniqueEmail($pdo, $email);

$insert = $pdo->prepare(
    'INSERT INTO qiling_users
     (username, email, password_hash, role_key, status, login_failed_attempts, login_lock_until, created_at, updated_at)
     VALUES
     (:username, :email, :password_hash, :role_key, :status, 0, NULL, :created_at, :updated_at)'
);
$insert->execute([
    'username' => $username,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'role_key' => 'admin',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);

$newId = (int) $pdo->lastInsertId();
echo "Admin user created and password initialized\n";
echo "User ID: {$newId}\n";
echo "Username: {$username}\n";
echo "Email: {$email}\n";
echo "Temp Password: {$password}\n";
echo "Please login and change password immediately.\n";
exit(0);

/**
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function parseOptions(array $argv): array
{
    $result = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = (string) $argv[$i];
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $arg = substr($arg, 2);
        if ($arg === 'help' || $arg === 'list') {
            $result[$arg] = true;
            continue;
        }
        $eqPos = strpos($arg, '=');
        if ($eqPos !== false) {
            $key = trim(substr($arg, 0, $eqPos));
            $value = trim(substr($arg, $eqPos + 1));
            if ($key !== '') {
                $result[$key] = $value;
            }
            continue;
        }

        $key = trim($arg);
        $next = $argv[$i + 1] ?? '';
        if ($key !== '' && is_string($next) && strpos($next, '--') !== 0) {
            $result[$key] = trim($next);
            $i++;
        }
    }

    return $result;
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php scripts/reset-admin-password.php [--username=admin] [--password=YourPassword]\n";
    echo "  php scripts/reset-admin-password.php --list\n";
    echo "Options:\n";
    echo "  --username   target username, default INSTALL_ADMIN_USERNAME or first admin\n";
    echo "  --password   target password, if empty then auto-generate a temp password\n";
    echo "  --list       list all admin users\n";
    echo "  --help       show this help\n";
}

function ensureLoginSecurityColumns(PDO $pdo): void
{
    ensureColumn($pdo, 'qiling_users', 'login_failed_attempts', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_users', 'login_lock_until', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_users', 'last_login_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_users', 'last_login_ip', "VARCHAR(64) NOT NULL DEFAULT ''");
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
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

/**
 * @return array<string, mixed>|null
 */
function findUserByUsername(PDO $pdo, string $username): ?array
{
    if ($username === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, role_key
         FROM qiling_users
         WHERE username = :username
         LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function findFirstAdminUser(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, role_key
         FROM qiling_users
         WHERE role_key = :role_key
         ORDER BY id ASC
         LIMIT 1'
    );
    $stmt->execute(['role_key' => 'admin']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function uniqueEmail(PDO $pdo, string $email): string
{
    $email = trim($email);
    if ($email === '') {
        $email = 'admin@qiling.local';
    }

    $stmt = $pdo->prepare('SELECT id FROM qiling_users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetchColumn() === false) {
        return $email;
    }

    $parts = explode('@', $email, 2);
    $name = $parts[0] !== '' ? $parts[0] : 'admin';
    $domain = $parts[1] ?? 'qiling.local';
    return $name . '+' . gmdate('YmdHis') . '@' . $domain;
}

function generateTempPassword(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghijkmnpqrstuvwxyz';
    $len = strlen($alphabet);
    $result = '';
    for ($i = 0; $i < 10; $i++) {
        $result .= $alphabet[random_int(0, $len - 1)];
    }
    return $result;
}

function listAdmins(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'SELECT id, username, email, status, updated_at
         FROM qiling_users
         WHERE role_key = :role_key
         ORDER BY id ASC'
    );
    $stmt->execute(['role_key' => 'admin']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || count($rows) === 0) {
        echo "No admin users found.\n";
        return;
    }

    echo "Admin users:\n";
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $username = (string) ($row['username'] ?? '');
        $email = (string) ($row['email'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $updatedAt = (string) ($row['updated_at'] ?? '');
        echo "- #{$id} {$username} <{$email}> status={$status} updated_at={$updatedAt}\n";
    }
}
