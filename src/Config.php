<?php

declare(strict_types=1);

namespace FermeTranquille;

use RuntimeException;

/**
 * Loads config/config.php once.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (self::$config === null) {
            $file = dirname(__DIR__) . '/config/config.php';
            if (!is_file($file)) {
                throw new RuntimeException('config/config.php not found (copy config/config.example.php)');
            }

            $config = require $file;
            if (!is_array($config)) {
                throw new RuntimeException('config/config.php must return an array');
            }
            self::$config = $config;
        }

        return self::$config;
    }

    /**
     * @return array<string, mixed>
     */
    public static function section(string $name): array
    {
        $section = self::get()[$name] ?? null;
        if (!is_array($section)) {
            throw new RuntimeException("Missing config section: {$name}");
        }

        return $section;
    }
}
