<?php

declare(strict_types=1);

namespace FermeTranquille\Tests;

use FermeTranquille\Db;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbTest extends TestCase
{
    public function testDsnUsesDefaults(): void
    {
        $dsn = Db::dsn(['host' => 'localhost', 'name' => 'ft', 'user' => 'u', 'password' => 'p']);

        self::assertSame('mysql:host=localhost;port=3306;dbname=ft;charset=utf8mb4', $dsn);
    }

    public function testDsnUsesProvidedPortAndCharset(): void
    {
        $dsn = Db::dsn([
            'host' => '127.0.0.1',
            'port' => '3307',
            'name' => 'ft',
            'user' => 'u',
            'password' => 'p',
            'charset' => 'utf8',
        ]);

        self::assertSame('mysql:host=127.0.0.1;port=3307;dbname=ft;charset=utf8', $dsn);
    }

    public function testDsnRejectsMissingKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name');

        Db::dsn(['host' => 'localhost', 'user' => 'u', 'password' => 'p']);
    }

    public function testExampleConfigIsValid(): void
    {
        $config = require dirname(__DIR__) . '/config/config.example.php';

        self::assertIsArray($config['db']);
        self::assertStringStartsWith('mysql:', Db::dsn($config['db']));
    }
}
