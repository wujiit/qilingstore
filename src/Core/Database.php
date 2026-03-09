<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;
    /** @var array<string, string>|null */
    private static ?array $config = null;

    /** @param array<string, string> $config */
    public static function boot(array $config): void
    {
        // Keep bootstrap fast and resilient: establish PDO only when actually needed.
        self::$config = $config;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            self::connect();
        }

        if (!self::$pdo instanceof PDO) {
            throw new RuntimeException('Database is not booted');
        }

        return self::$pdo;
    }

    private static function connect(): void
    {
        if (!is_array(self::$config)) {
            throw new RuntimeException('Database is not booted');
        }

        $host = self::$config['DB_HOST'] ?? (self::$config['DB_SERVER'] ?? '127.0.0.1');
        $port = self::$config['DB_PORT'] ?? '3306';
        $db = self::$config['DB_DATABASE'] ?? (self::$config['DB_NAME'] ?? '');
        $user = self::$config['DB_USERNAME'] ?? (self::$config['DB_USER'] ?? '');
        $pass = self::$config['DB_PASSWORD'] ?? (self::$config['DB_PASS'] ?? '');

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
}
