<?php

declare(strict_types=1);

const QILING_INSTALL_MIN_PHP = '8.1.0';

$projectRoot = dirname(__DIR__);
$envExamplePath = $projectRoot . '/.env.example';
$envPath = $projectRoot . '/.env';
$schemaPath = $projectRoot . '/sql/schema.sql';
$installLockPath = $projectRoot . '/storage/install.lock';

$appUrlDefault = detectAppUrl();
$defaults = [
    'app_url' => $appUrlDefault,
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'qiling_medspa',
    'db_user' => 'root',
    'db_pass' => '',
    'admin_username' => 'admin',
    'admin_password' => '',
    'admin_email' => 'admin@example.com',
    'wp_sync_secret' => '',
];

$message = '';
$error = '';
$success = false;

$installed = detectInstalled($envPath);
$existingEnv = parseEnvFile($envPath);
$allowWebReinstall = toBoolValue((string) ($existingEnv['INSTALL_ALLOW_REINSTALL'] ?? 'false'));
$autoLockError = '';
if (!isInstallLocked($installLockPath) && !empty($installed['installed'])) {
    try {
        writeInstallLock($installLockPath);
    } catch (Throwable $e) {
        $autoLockError = $e->getMessage();
    }
}
$locked = isInstallLocked($installLockPath);
$force = isset($_GET['force']) && $_GET['force'] === '1';
if (!empty($installed['installed']) && !$locked && $autoLockError !== '') {
    $error = '检测到系统已安装，但安装锁自动补写失败：' . $autoLockError
        . '。请先手动创建/修复安装锁文件后再访问。';
}

$data = $defaults;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'app_url' => trim((string) ($_POST['app_url'] ?? $defaults['app_url'])),
        'db_host' => trim((string) ($_POST['db_host'] ?? $defaults['db_host'])),
        'db_port' => trim((string) ($_POST['db_port'] ?? $defaults['db_port'])),
        'db_name' => trim((string) ($_POST['db_name'] ?? $defaults['db_name'])),
        'db_user' => trim((string) ($_POST['db_user'] ?? $defaults['db_user'])),
        'db_pass' => (string) ($_POST['db_pass'] ?? $defaults['db_pass']),
        'admin_username' => trim((string) ($_POST['admin_username'] ?? $defaults['admin_username'])),
        'admin_password' => (string) ($_POST['admin_password'] ?? $defaults['admin_password']),
        'admin_email' => trim((string) ($_POST['admin_email'] ?? $defaults['admin_email'])),
        'wp_sync_secret' => trim((string) ($_POST['wp_sync_secret'] ?? $defaults['wp_sync_secret'])),
    ];

    try {
        if (!empty($installed['installed']) && !$locked && $autoLockError !== '') {
            throw new RuntimeException('安装锁异常，已禁止安装操作：' . $autoLockError);
        }

        if ($locked) {
            throw new RuntimeException('安装向导已锁定。请先删除文件后再操作：' . $installLockPath);
        }
        if (!empty($installed['installed'])) {
            if (!$allowWebReinstall) {
                throw new RuntimeException('系统已安装。为安全起见，网页安装向导默认禁止重装。');
            }
            if (!$force) {
                throw new RuntimeException('系统已安装且已开启网页重装权限。如确需重装，请使用 ?force=1 打开本页。');
            }
        }

        foreach (environmentChecks($projectRoot, $envExamplePath, $schemaPath) as $check) {
            if (empty($check['ok'])) {
                throw new RuntimeException('环境检测未通过：' . (string) $check['name']);
            }
        }

        validateInput($data);
        installSystem($data, $projectRoot, $envExamplePath, $envPath, $schemaPath);
        writeInstallLock($installLockPath);
        $locked = true;
        $success = true;
        $message = '安装完成。安装向导已锁定，如需重装请先删除文件：' . $installLockPath;
        $installed = ['installed' => true, 'reason' => 'ok'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$checks = environmentChecks($projectRoot, $envExamplePath, $schemaPath);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function detectAppUrl(): string
{
    $https = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : 'localhost';
    if ($host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host;
}

/**
 * @return array<string, mixed>
 */
function detectInstalled(string $envPath): array
{
    if (!is_file($envPath)) {
        return ['installed' => false, 'reason' => 'env_missing'];
    }

    $env = parseEnvFile($envPath);
    $dbHost = (string) ($env['DB_HOST'] ?? '');
    $dbPort = (string) ($env['DB_PORT'] ?? '3306');
    $dbName = (string) ($env['DB_DATABASE'] ?? '');
    $dbUser = (string) ($env['DB_USERNAME'] ?? '');
    $dbPass = (string) ($env['DB_PASSWORD'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        return ['installed' => false, 'reason' => 'env_incomplete'];
    }

    try {
        $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $stmt = $pdo->query("SHOW TABLES LIKE 'qiling_users'");
        if (!$stmt->fetchColumn()) {
            return ['installed' => false, 'reason' => 'table_missing'];
        }

        $countStmt = $pdo->query('SELECT COUNT(*) FROM qiling_users');
        $count = (int) $countStmt->fetchColumn();

        return ['installed' => $count > 0, 'reason' => $count > 0 ? 'ok' : 'empty'];
    } catch (Throwable $e) {
        return ['installed' => false, 'reason' => 'db_unreachable', 'error' => $e->getMessage()];
    }
}

function isInstallLocked(string $lockPath): bool
{
    return is_file($lockPath);
}

function writeInstallLock(string $lockPath): void
{
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('创建安装锁目录失败: ' . $dir);
        }
    }

    $content = "locked_at=" . gmdate('c') . "\n";
    $content .= 'host=' . (string) ($_SERVER['HTTP_HOST'] ?? 'cli') . "\n";
    if (file_put_contents($lockPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('写入安装锁失败: ' . $lockPath);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function environmentChecks(string $projectRoot, string $envExamplePath, string $schemaPath): array
{
    $checks = [];
    $checks[] = checkItem('PHP >= ' . QILING_INSTALL_MIN_PHP, version_compare(PHP_VERSION, QILING_INSTALL_MIN_PHP, '>='), '当前: ' . PHP_VERSION);
    $checks[] = checkItem('PDO 扩展', extension_loaded('pdo'), '');
    $checks[] = checkItem('pdo_mysql 扩展', extension_loaded('pdo_mysql'), '');
    $checks[] = checkItem('JSON 扩展', extension_loaded('json'), '');
    $checks[] = checkItem('OpenSSL 扩展', extension_loaded('openssl'), '');
    $checks[] = checkItem('mbstring 扩展', extension_loaded('mbstring'), '');
    $checks[] = checkItem('.env.example 可读', is_readable($envExamplePath), $envExamplePath);
    $checks[] = checkItem('schema.sql 可读', is_readable($schemaPath), $schemaPath);
    $checks[] = checkItem('项目目录可写', is_writable($projectRoot), $projectRoot);

    return $checks;
}

/**
 * @return array<string, mixed>
 */
function checkItem(string $name, bool $ok, string $detail): array
{
    return [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

/**
 * @param array<string, string> $data
 */
function validateInput(array $data): void
{
    $required = ['app_url', 'db_host', 'db_port', 'db_name', 'db_user', 'admin_username', 'admin_password', 'admin_email'];
    foreach ($required as $field) {
        if (trim((string) ($data[$field] ?? '')) === '') {
            throw new RuntimeException('字段不能为空: ' . $field);
        }
    }

    if (!is_numeric($data['db_port'])) {
        throw new RuntimeException('数据库端口必须是数字');
    }

    $port = (int) $data['db_port'];
    if ($port <= 0 || $port > 65535) {
        throw new RuntimeException('数据库端口范围不正确');
    }

    if (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('管理员邮箱格式不正确');
    }

    if (strlen($data['admin_password']) < 8) {
        throw new RuntimeException('管理员密码至少 8 位');
    }
}

/**
 * @param array<string, string> $data
 */
function installSystem(array $data, string $projectRoot, string $envExamplePath, string $envPath, string $schemaPath): void
{
    if (!is_file($envExamplePath)) {
        throw new RuntimeException('.env.example 不存在');
    }
    if (!is_file($schemaPath)) {
        throw new RuntimeException('schema.sql 不存在');
    }

    $dbHost = $data['db_host'];
    $dbPort = $data['db_port'];
    $dbName = $data['db_name'];
    $dbUser = $data['db_user'];
    $dbPass = $data['db_pass'];

    $serverDsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';charset=utf8mb4';
    $serverPdo = new PDO($serverDsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $createDbError = '';
    try {
        $serverPdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        $createDbError = $e->getMessage();
    }

    $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        if ($createDbError !== '') {
            throw new RuntimeException('数据库连接失败，且创建数据库失败：' . $createDbError . '；连接错误：' . $e->getMessage());
        }
        throw $e;
    }

    $schemaSql = file_get_contents($schemaPath);
    if (!is_string($schemaSql) || trim($schemaSql) === '') {
        throw new RuntimeException('schema.sql 读取失败');
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $schemaSql);
    if (!is_array($statements)) {
        throw new RuntimeException('schema.sql 解析失败');
    }

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    ensureColumn($pdo, 'qiling_appointment_consumes', 'rolled_back_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_operator_user_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_note', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_before_sessions', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_appointment_consumes', 'rollback_after_sessions', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_users', 'login_failed_attempts', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_users', 'login_lock_until', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_users', 'last_login_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_users', 'last_login_ip', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'qiling_users', 'token_version', 'INT NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'qiling_followup_tasks', 'notify_status', 'VARCHAR(20) NOT NULL DEFAULT \'pending\'');
    ensureColumn($pdo, 'qiling_followup_tasks', 'notified_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_followup_tasks', 'notify_channel_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_followup_tasks', 'notify_error', 'VARCHAR(500) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'qiling_services', 'supports_online_booking', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_report_daily_channel', 'paid_customers', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'channel', 'VARCHAR(20) NOT NULL DEFAULT \'email\'');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'receiver', 'VARCHAR(160) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'code_hash', 'CHAR(64) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'expire_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'used_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'fail_count', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'request_ip', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'requested_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'created_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_password_reset_requests', 'updated_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_companies', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_crm_companies', 'archived_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_companies', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_companies', 'deleted_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_companies', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_companies', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_companies', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_companies', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
    ensureColumn($pdo, 'qiling_crm_contacts', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_crm_contacts', 'archived_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_contacts', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_contacts', 'deleted_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_contacts', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_contacts', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_contacts', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_contacts', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
    ensureColumn($pdo, 'qiling_crm_leads', 'visibility_scope', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
    ensureColumn($pdo, 'qiling_crm_leads', 'public_pool_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_leads', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'qiling_crm_leads', 'archived_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_leads', 'archived_by', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_leads', 'deleted_at', 'DATETIME NULL');
    ensureColumn($pdo, 'qiling_crm_leads', 'deleted_by', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_leads', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_leads', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_leads', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
    ensureColumn($pdo, 'qiling_crm_deals', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_deals', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_deals', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
    ensureColumn($pdo, 'qiling_crm_activities', 'owner_team_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_activities', 'owner_department_id', 'BIGINT UNSIGNED DEFAULT NULL');
    ensureColumn($pdo, 'qiling_crm_activities', 'visibility_level', 'VARCHAR(20) NOT NULL DEFAULT \'private\'');
    ensureColumn($pdo, 'qiling_crm_assignment_rules', 'last_pick_index', 'INT NOT NULL DEFAULT -1');
    ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_lookup', 'INDEX idx_qiling_pwd_reset_lookup (user_id, channel, receiver, used_at, id)');
    ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_user', 'INDEX idx_qiling_pwd_reset_user (user_id, requested_at)');
    ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_ip', 'INDEX idx_qiling_pwd_reset_ip (request_ip, requested_at)');
    ensureIndex($pdo, 'qiling_password_reset_requests', 'idx_qiling_pwd_reset_expire_used', 'INDEX idx_qiling_pwd_reset_expire_used (expire_at, used_at)');

    ensureIndex($pdo, 'qiling_crm_pipelines', 'idx_qiling_crm_pipelines_status', 'INDEX idx_qiling_crm_pipelines_status (status)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_owner_status', 'INDEX idx_qiling_crm_companies_owner_status (owner_user_id, status, id)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_status_id', 'INDEX idx_qiling_crm_companies_status_id (status, id)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_country', 'INDEX idx_qiling_crm_companies_country (country_code)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_type', 'INDEX idx_qiling_crm_companies_type (company_type)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_created_at', 'INDEX idx_qiling_crm_companies_created_at (created_at)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_company_name', 'INDEX idx_qiling_crm_companies_company_name (company_name)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_created_by_id', 'INDEX idx_qiling_crm_companies_created_by_id (created_by, id)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_company_deleted_id', 'INDEX idx_qiling_crm_companies_company_deleted_id (company_name, deleted_at, id)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_owner_deleted_archived_id', 'INDEX idx_qiling_crm_companies_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_companies', 'ft_qiling_crm_companies_search', 'FULLTEXT INDEX ft_qiling_crm_companies_search (company_name, website, industry)');
    ensureIndex($pdo, 'qiling_crm_companies', 'idx_qiling_crm_companies_deleted_archived', 'INDEX idx_qiling_crm_companies_deleted_archived (deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_owner_status', 'INDEX idx_qiling_crm_contacts_owner_status (owner_user_id, status, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_status_id', 'INDEX idx_qiling_crm_contacts_status_id (status, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_company', 'INDEX idx_qiling_crm_contacts_company (company_id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_mobile', 'INDEX idx_qiling_crm_contacts_mobile (mobile)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_email', 'INDEX idx_qiling_crm_contacts_email (email)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_created_at', 'INDEX idx_qiling_crm_contacts_created_at (created_at)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_whatsapp', 'INDEX idx_qiling_crm_contacts_whatsapp (whatsapp)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_created_by_id', 'INDEX idx_qiling_crm_contacts_created_by_id (created_by, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_mobile_deleted_id', 'INDEX idx_qiling_crm_contacts_mobile_deleted_id (mobile, deleted_at, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_email_deleted_id', 'INDEX idx_qiling_crm_contacts_email_deleted_id (email, deleted_at, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_whatsapp_deleted_id', 'INDEX idx_qiling_crm_contacts_whatsapp_deleted_id (whatsapp, deleted_at, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_owner_deleted_archived_id', 'INDEX idx_qiling_crm_contacts_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_company_deleted_archived_id', 'INDEX idx_qiling_crm_contacts_company_deleted_archived_id (company_id, deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'ft_qiling_crm_contacts_search', 'FULLTEXT INDEX ft_qiling_crm_contacts_search (contact_name, mobile, email, whatsapp)');
    ensureIndex($pdo, 'qiling_crm_contacts', 'idx_qiling_crm_contacts_deleted_archived', 'INDEX idx_qiling_crm_contacts_deleted_archived (deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_owner_status', 'INDEX idx_qiling_crm_leads_owner_status (owner_user_id, status, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_status_id', 'INDEX idx_qiling_crm_leads_status_id (status, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_owner_intent_id', 'INDEX idx_qiling_crm_leads_owner_intent_id (owner_user_id, intent_level, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_next_followup', 'INDEX idx_qiling_crm_leads_next_followup (next_followup_at, status)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_mobile', 'INDEX idx_qiling_crm_leads_mobile (mobile)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_email', 'INDEX idx_qiling_crm_leads_email (email)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_company', 'INDEX idx_qiling_crm_leads_company (related_company_id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_contact', 'INDEX idx_qiling_crm_leads_contact (related_contact_id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_visibility_owner', 'INDEX idx_qiling_crm_leads_visibility_owner (visibility_scope, owner_user_id, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_public_pool', 'INDEX idx_qiling_crm_leads_public_pool (visibility_scope, public_pool_at, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_created_by_id', 'INDEX idx_qiling_crm_leads_created_by_id (created_by, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_company_name_deleted_id', 'INDEX idx_qiling_crm_leads_company_name_deleted_id (company_name, deleted_at, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_mobile_deleted_id', 'INDEX idx_qiling_crm_leads_mobile_deleted_id (mobile, deleted_at, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_email_deleted_id', 'INDEX idx_qiling_crm_leads_email_deleted_id (email, deleted_at, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_owner_deleted_archived_id', 'INDEX idx_qiling_crm_leads_owner_deleted_archived_id (owner_user_id, deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_created_deleted_archived_id', 'INDEX idx_qiling_crm_leads_created_deleted_archived_id (created_by, deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_visibility_deleted_archived_pool', 'INDEX idx_qiling_crm_leads_visibility_deleted_archived_pool (visibility_scope, deleted_at, is_archived, public_pool_at, id)');
    ensureIndex($pdo, 'qiling_crm_leads', 'ft_qiling_crm_leads_search', 'FULLTEXT INDEX ft_qiling_crm_leads_search (lead_name, mobile, email, company_name)');
    ensureIndex($pdo, 'qiling_crm_leads', 'idx_qiling_crm_leads_deleted_archived', 'INDEX idx_qiling_crm_leads_deleted_archived (deleted_at, is_archived, id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_owner_status', 'INDEX idx_qiling_crm_deals_owner_status (owner_user_id, deal_status, id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_status_id', 'INDEX idx_qiling_crm_deals_status_id (deal_status, id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_pipeline_stage', 'INDEX idx_qiling_crm_deals_pipeline_stage (pipeline_key, stage_key)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_pipeline_stage_id', 'INDEX idx_qiling_crm_deals_pipeline_stage_id (pipeline_key, stage_key, id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_expected_close', 'INDEX idx_qiling_crm_deals_expected_close (expected_close_date)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_owner_id', 'INDEX idx_qiling_crm_deals_owner_id (owner_user_id, id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_company', 'INDEX idx_qiling_crm_deals_company (company_id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_contact', 'INDEX idx_qiling_crm_deals_contact (contact_id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_lead', 'INDEX idx_qiling_crm_deals_lead (lead_id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'idx_qiling_crm_deals_created_by_id', 'INDEX idx_qiling_crm_deals_created_by_id (created_by, id)');
    ensureIndex($pdo, 'qiling_crm_deals', 'ft_qiling_crm_deals_name', 'FULLTEXT INDEX ft_qiling_crm_deals_name (deal_name)');
    ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_owner_status_due', 'INDEX idx_qiling_crm_activities_owner_status_due (owner_user_id, status, due_at, id)');
    ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_status_due_id', 'INDEX idx_qiling_crm_activities_status_due_id (status, due_at, id)');
    ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_entity', 'INDEX idx_qiling_crm_activities_entity (entity_type, entity_id, id)');
    ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_owner_id', 'INDEX idx_qiling_crm_activities_owner_id (owner_user_id, id)');
    ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_created_by_id', 'INDEX idx_qiling_crm_activities_created_by_id (created_by, id)');
    ensureIndex($pdo, 'qiling_crm_activities', 'idx_qiling_crm_activities_due_status', 'INDEX idx_qiling_crm_activities_due_status (due_at, status)');
    ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_entity', 'INDEX idx_qiling_crm_transfer_logs_entity (entity_type, entity_id, id)');
    ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_entity_type_id', 'INDEX idx_qiling_crm_transfer_logs_entity_type_id (entity_type, id)');
    ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_to_owner', 'INDEX idx_qiling_crm_transfer_logs_to_owner (to_owner_user_id, id)');
    ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_from_owner', 'INDEX idx_qiling_crm_transfer_logs_from_owner (from_owner_user_id, id)');
    ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_created_by', 'INDEX idx_qiling_crm_transfer_logs_created_by (created_by, id)');
    ensureIndex($pdo, 'qiling_crm_transfer_logs', 'idx_qiling_crm_transfer_logs_created_at', 'INDEX idx_qiling_crm_transfer_logs_created_at (created_at)');
    ensureIndex($pdo, 'qiling_crm_assignment_rules', 'idx_qiling_crm_assignment_rules_entity_enabled', 'INDEX idx_qiling_crm_assignment_rules_entity_enabled (entity_type, enabled, id)');
    ensureIndex($pdo, 'qiling_crm_assignment_rules', 'idx_qiling_crm_assignment_rules_source_scope', 'INDEX idx_qiling_crm_assignment_rules_source_scope (source_scope, enabled, id)');

    $now = gmdate('Y-m-d H:i:s');
    $crmPermissionsView = [
        'crm',
        'crm.dashboard.view',
        'crm.pipelines.view',
        'crm.companies.view',
        'crm.contacts.view',
        'crm.leads.view',
        'crm.deals.view',
        'crm.activities.view',
        'crm.org.view',
        'crm.custom_fields.view',
        'crm.form_config.view',
        'crm.reminders.view',
    ];
    $crmPermissionsEdit = [
        'crm.companies.edit',
        'crm.contacts.edit',
        'crm.leads.edit',
        'crm.leads.convert',
        'crm.deals.edit',
        'crm.activities.edit',
        'crm.org.edit',
        'crm.custom_fields.edit',
        'crm.form_config.edit',
        'crm.reminders.edit',
    ];
    $crmPermissionsManage = [
        'crm.scope.all',
        'crm.pipelines.manage',
        'crm.leads.assign',
        'crm.governance.manage',
        'crm.assignment_rules.view',
        'crm.assignment_rules.edit',
        'crm.assignment_rules.manage',
        'crm.transfer_logs.view',
    ];

    $roles = [
        ['admin', '系统管理员', array_merge(['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users', 'system'], $crmPermissionsView, $crmPermissionsEdit, $crmPermissionsManage)],
        ['manager', '门店经理', array_merge(['dashboard', 'stores', 'staff', 'customers', 'services', 'packages', 'member_cards', 'orders', 'appointments', 'followup', 'push', 'commissions', 'reports', 'points', 'open_gifts', 'coupon_groups', 'transfers', 'prints', 'wp_users'], $crmPermissionsView, $crmPermissionsEdit, $crmPermissionsManage)],
        ['consultant', '顾问', array_merge(['dashboard', 'customers', 'member_cards', 'orders', 'appointments', 'followup', 'reports', 'points', 'prints'], $crmPermissionsView, $crmPermissionsEdit)],
        ['therapist', '护理师', array_merge(['dashboard', 'customers', 'appointments', 'followup', 'performance'], $crmPermissionsView, $crmPermissionsEdit)],
        ['reception', '前台', array_merge(['dashboard', 'customers', 'orders', 'appointments', 'followup'], $crmPermissionsView, $crmPermissionsEdit)],
    ];

    $roleStmt = $pdo->prepare(
        'INSERT INTO qiling_roles (role_key, role_name, permissions_json, is_system, status, created_at, updated_at)
         VALUES (:role_key, :role_name, :permissions_json, 1, :status, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), permissions_json = VALUES(permissions_json), updated_at = VALUES(updated_at)'
    );
    foreach ($roles as $role) {
        [$roleKey, $roleName, $permissions] = $role;
        $roleStmt->execute([
            'role_key' => $roleKey,
            'role_name' => $roleName,
            'permissions_json' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $storeStmt = $pdo->prepare('SELECT id FROM qiling_stores WHERE store_code = :store_code LIMIT 1');
    $storeStmt->execute(['store_code' => 'QLS00001']);
    $storeId = (int) $storeStmt->fetchColumn();
    if ($storeId <= 0) {
        $insertStore = $pdo->prepare(
            'INSERT INTO qiling_stores (store_code, store_name, contact_name, contact_phone, address, open_time, close_time, status, created_at, updated_at)
             VALUES (:store_code, :store_name, :contact_name, :contact_phone, :address, :open_time, :close_time, :status, :created_at, :updated_at)'
        );
        $insertStore->execute([
            'store_code' => 'QLS00001',
            'store_name' => '默认门店',
            'contact_name' => '',
            'contact_phone' => '',
            'address' => '',
            'open_time' => '09:00',
            'close_time' => '21:00',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $storeId = (int) $pdo->lastInsertId();
    }

    $planStmt = $pdo->prepare(
        'INSERT INTO qiling_followup_plans (store_id, trigger_type, plan_name, schedule_days_json, enabled, created_at, updated_at)
         VALUES (:store_id, :trigger_type, :plan_name, :schedule_days_json, :enabled, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            plan_name = VALUES(plan_name),
            schedule_days_json = VALUES(schedule_days_json),
            enabled = VALUES(enabled),
            updated_at = VALUES(updated_at)'
    );
    $planStmt->execute([
        'store_id' => 0,
        'trigger_type' => 'appointment_completed',
        'plan_name' => '默认回访计划',
        'schedule_days_json' => json_encode([1, 3, 7], JSON_UNESCAPED_UNICODE),
        'enabled' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $crmPipelineStmt = $pdo->prepare(
        'INSERT INTO qiling_crm_pipelines
         (pipeline_key, pipeline_name, stages_json, is_system, status, created_at, updated_at)
         VALUES
         (:pipeline_key, :pipeline_name, :stages_json, 1, :status, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            pipeline_name = VALUES(pipeline_name),
            stages_json = VALUES(stages_json),
            status = VALUES(status),
            updated_at = VALUES(updated_at)'
    );
    $crmPipelineStmt->execute([
        'pipeline_key' => 'default',
        'pipeline_name' => '默认销售管道',
        'stages_json' => json_encode([
            ['key' => 'new', 'name' => '新建线索', 'sort' => 10],
            ['key' => 'contacted', 'name' => '已触达', 'sort' => 20],
            ['key' => 'qualified', 'name' => '已确认需求', 'sort' => 30],
            ['key' => 'proposal', 'name' => '方案/报价', 'sort' => 40],
            ['key' => 'negotiation', 'name' => '商务谈判', 'sort' => 50],
            ['key' => 'won', 'name' => '赢单', 'sort' => 60],
            ['key' => 'lost', 'name' => '输单', 'sort' => 70],
        ], JSON_UNESCAPED_UNICODE),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $settingStmt = $pdo->prepare(
        'INSERT INTO qiling_system_settings (setting_key, setting_value, updated_by, created_at, updated_at)
         VALUES (:setting_key, :setting_value, 0, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = VALUES(updated_at)'
    );
    $defaultSettings = [
        'admin_entry_path' => 'admin',
        'front_site_enabled' => '1',
        'front_maintenance_message' => '系统维护中，请稍后访问。',
        'front_allow_ips' => '',
        'security_headers_enabled' => '1',
        'frontend_asset_version_seed' => '',
    ];
    foreach ($defaultSettings as $settingKey => $settingValue) {
        $settingStmt->execute([
            'setting_key' => $settingKey,
            'setting_value' => $settingValue,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $adminUsername = $data['admin_username'];
    $adminEmail = $data['admin_email'];
    $adminPasswordHash = password_hash($data['admin_password'], PASSWORD_BCRYPT);

    $adminStmt = $pdo->prepare('SELECT id FROM qiling_users WHERE username = :username LIMIT 1');
    $adminStmt->execute(['username' => $adminUsername]);
    $adminId = (int) $adminStmt->fetchColumn();

    if ($adminId <= 0) {
        $insertAdmin = $pdo->prepare(
            'INSERT INTO qiling_users (username, email, password_hash, role_key, status, created_at, updated_at)
             VALUES (:username, :email, :password_hash, :role_key, :status, :created_at, :updated_at)'
        );
        $insertAdmin->execute([
            'username' => $adminUsername,
            'email' => $adminEmail,
            'password_hash' => $adminPasswordHash,
            'role_key' => 'admin',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $adminId = (int) $pdo->lastInsertId();
    } else {
        $updateAdmin = $pdo->prepare(
            'UPDATE qiling_users
             SET email = :email,
                 password_hash = :password_hash,
                 role_key = :role_key,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateAdmin->execute([
            'email' => $adminEmail,
            'password_hash' => $adminPasswordHash,
            'role_key' => 'admin',
            'status' => 'active',
            'updated_at' => $now,
            'id' => $adminId,
        ]);
    }

    $staffStmt = $pdo->prepare('SELECT id FROM qiling_staff WHERE user_id = :user_id LIMIT 1');
    $staffStmt->execute(['user_id' => $adminId]);
    $staffId = (int) $staffStmt->fetchColumn();
    if ($staffId <= 0) {
        $insertStaff = $pdo->prepare(
            'INSERT INTO qiling_staff (user_id, store_id, role_key, staff_no, phone, title, status, created_at, updated_at)
             VALUES (:user_id, :store_id, :role_key, :staff_no, :phone, :title, :status, :created_at, :updated_at)'
        );
        $insertStaff->execute([
            'user_id' => $adminId,
            'store_id' => $storeId,
            'role_key' => 'manager',
            'staff_no' => 'A0001',
            'phone' => '',
            'title' => '系统管理员',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $envOverrides = parseEnvFile($envExamplePath);
    $envOverrides['APP_URL'] = rtrim($data['app_url'], '/');
    $envOverrides['APP_KEY'] = bin2hex(random_bytes(32));
    $envOverrides['DB_HOST'] = $dbHost;
    $envOverrides['DB_PORT'] = $dbPort;
    $envOverrides['DB_DATABASE'] = $dbName;
    $envOverrides['DB_USERNAME'] = $dbUser;
    $envOverrides['DB_PASSWORD'] = $dbPass;
    $envOverrides['INSTALL_ADMIN_USERNAME'] = $adminUsername;
    $envOverrides['INSTALL_ADMIN_PASSWORD'] = '';
    $envOverrides['INSTALL_ADMIN_EMAIL'] = $adminEmail;
    $envOverrides['WP_SYNC_SHARED_SECRET'] = $data['wp_sync_secret'] !== '' ? $data['wp_sync_secret'] : bin2hex(random_bytes(16));
    $envOverrides['CRON_SHARED_KEY'] = bin2hex(random_bytes(24));

    $envContent = buildEnvContent($envExamplePath, $envOverrides);
    $writeResult = file_put_contents($envPath, $envContent, LOCK_EX);
    if ($writeResult === false) {
        throw new RuntimeException('.env 写入失败，请检查目录权限');
    }
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

    $exists = (int) $check->fetchColumn() > 0;
    if ($exists) {
        return;
    }

    $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition);
}

function ensureIndex(PDO $pdo, string $table, string $indexName, string $indexDefinition): void
{
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $check->execute([
        'table_name' => $table,
        'index_name' => $indexName,
    ]);

    $exists = (int) $check->fetchColumn() > 0;
    if ($exists) {
        return;
    }

    $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD ' . $indexDefinition);
}

/**
 * @return array<string, string>
 */
function parseEnvFile(string $path): array
{
    $result = [];
    if (!is_file($path)) {
        return $result;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $result;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $result[$key] = trim($value, "\"'");
    }

    return $result;
}

function toBoolValue(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @param array<string, string> $overrides
 */
function buildEnvContent(string $templatePath, array $overrides): string
{
    $lines = file($templatePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('.env.example 读取失败');
    }

    $written = [];
    $output = [];

    foreach ($lines as $line) {
        $originalLine = $line;
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            $output[] = $originalLine;
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            $output[] = $originalLine;
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        if (array_key_exists($key, $overrides)) {
            $output[] = $key . '=' . formatEnvValue($overrides[$key]);
            $written[$key] = true;
        } else {
            $output[] = $originalLine;
        }
    }

    foreach ($overrides as $key => $value) {
        if (isset($written[$key])) {
            continue;
        }
        $output[] = $key . '=' . formatEnvValue($value);
    }

    return implode("\n", $output) . "\n";
}

function formatEnvValue(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (preg_match('/[\s#\'"]/u', $value)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    return $value;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>启灵系统安装向导</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f8fb; color: #1f2937; margin: 0; }
        .wrap { max-width: 980px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.03); }
        h1 { margin: 0 0 8px; font-size: 24px; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        .muted { color: #6b7280; font-size: 14px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        label { font-size: 14px; font-weight: 600; }
        input { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; font-size: 14px; }
        button { background: #111827; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-size: 14px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; border-bottom: 1px solid #eef2f7; padding: 8px 6px; }
        .ok { color: #047857; }
        .bad { color: #b91c1c; }
        .alert { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
        .alert-ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        @media (max-width: 860px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>启灵医美养生门店系统 安装向导</h1>
        <div class="muted">在线安装：环境检测、数据库初始化、管理员创建、.env 写入</div>
    </div>

    <div class="card">
        <h2>环境检测</h2>
        <table>
            <thead>
            <tr>
                <th>项目</th>
                <th>状态</th>
                <th>说明</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $item): ?>
                <tr>
                    <td><?php echo h((string) $item['name']); ?></td>
                    <td class="<?php echo !empty($item['ok']) ? 'ok' : 'bad'; ?>">
                        <?php echo !empty($item['ok']) ? '通过' : '失败'; ?>
                    </td>
                    <td><?php echo h((string) $item['detail']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>安装配置</h2>
        <?php if ($success): ?>
            <div class="alert alert-ok"><?php echo h($message); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-err"><?php echo h($error); ?></div>
        <?php endif; ?>
        <?php if ($locked && !$success): ?>
            <div class="alert alert-warn">
                当前安装向导已锁定。若确需重装，请先删除文件：
                <code><?php echo h($installLockPath); ?></code>
            </div>
        <?php endif; ?>
        <?php if (!empty($installed['installed']) && !$success): ?>
            <?php if (!$allowWebReinstall): ?>
                <div class="alert alert-warn">
                    检测到系统已安装。网页重装默认禁用（可在 <code>.env</code> 设置
                    <code>INSTALL_ALLOW_REINSTALL=true</code> 后配合 <code>?force=1</code> 使用）。
                </div>
            <?php elseif (!$force): ?>
                <div class="alert alert-warn">
                    检测到系统已安装。若你确认要重装，请访问：
                    <a href="?force=1">?force=1</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post">
            <div class="grid">
                <div class="field">
                    <label>系统访问地址 (APP_URL)</label>
                    <input type="text" name="app_url" value="<?php echo h($data['app_url']); ?>" required>
                </div>
                <div class="field">
                    <label>数据库主机</label>
                    <input type="text" name="db_host" value="<?php echo h($data['db_host']); ?>" required>
                </div>
                <div class="field">
                    <label>数据库端口</label>
                    <input type="text" name="db_port" value="<?php echo h($data['db_port']); ?>" required>
                </div>
                <div class="field">
                    <label>数据库名称</label>
                    <input type="text" name="db_name" value="<?php echo h($data['db_name']); ?>" required>
                </div>
                <div class="field">
                    <label>数据库用户名</label>
                    <input type="text" name="db_user" value="<?php echo h($data['db_user']); ?>" required>
                </div>
                <div class="field">
                    <label>数据库密码</label>
                    <input type="password" name="db_pass">
                </div>
                <div class="field">
                    <label>管理员账号</label>
                    <input type="text" name="admin_username" value="<?php echo h($data['admin_username']); ?>" required>
                </div>
                <div class="field">
                    <label>管理员密码 (至少8位)</label>
                    <input type="password" name="admin_password" required>
                </div>
                <div class="field">
                    <label>管理员邮箱</label>
                    <input type="email" name="admin_email" value="<?php echo h($data['admin_email']); ?>" required>
                </div>
                <div class="field">
                    <label>WordPress 同步密钥 (可选)</label>
                    <input type="text" name="wp_sync_secret" value="<?php echo h($data['wp_sync_secret']); ?>">
                </div>
            </div>
            <div style="margin-top:14px;">
                <button type="submit">开始安装</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
