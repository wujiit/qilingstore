<?php

declare(strict_types=1);

use Qiling\Core\Database;
use Qiling\Core\SystemUpgradeService;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $result = SystemUpgradeService::run(Database::pdo(), dirname(__DIR__), 0);
    echo "Upgrade success\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . "\n");
    exit(1);
}
