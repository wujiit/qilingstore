<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    /** @param array<string, string> $config */
    public static function boot(array $config): void
    {
        if (self::$pdo instanceof PDO) {
            return;
        }

        $host = $config['DB_HOST'] ?? ($config['DB_SERVER'] ?? '127.0.0.1');
        $port = $config['DB_PORT'] ?? '3306';
        $db = $config['DB_DATABASE'] ?? ($config['DB_NAME'] ?? '');
        $user = $config['DB_USERNAME'] ?? ($config['DB_USER'] ?? '');
        $pass = $config['DB_PASSWORD'] ?? ($config['DB_PASS'] ?? '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new RuntimeException('Database is not booted');
        }

        return self::$pdo;
    }
}
