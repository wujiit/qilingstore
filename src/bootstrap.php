<?php

declare(strict_types=1);

use Qiling\Core\Config;
use Qiling\Core\Database;

if (!defined('QILING_SYSTEM_ROOT')) {
    define('QILING_SYSTEM_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Qiling\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = QILING_SYSTEM_ROOT . '/src/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

Config::load(QILING_SYSTEM_ROOT . '/.env');
Database::boot(Config::all());
