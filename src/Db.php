<?php

declare(strict_types=1);

namespace FermeTranquille;

use PDO;
use RuntimeException;

/**
 * Lazy PDO singleton. Configuration comes from config/config.php ('db' key).
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    private function __construct()
    {
    }

    /**
     * Override the configuration (tests, CLI). Resets any open connection.
     *
     * @param array<string, mixed> $dbConfig
     */
    public static function configure(array $dbConfig): void
    {
        self::$config = $dbConfig;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $config = self::$config ??= Config::section('db');
            self::$pdo = new PDO(self::dsn($config), (string) $config['user'], (string) $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        }

        return self::$pdo;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function dsn(array $config): string
    {
        foreach (['host', 'name', 'user', 'password'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException("Missing db config key: {$key}");
            }
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            (int) ($config['port'] ?? 3306),
            $config['name'],
            $config['charset'] ?? 'utf8mb4',
        );
    }
}
