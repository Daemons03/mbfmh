<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Api;

use FermeTranquille\Api\Api;
use FermeTranquille\Auth;
use FermeTranquille\Tests\Support\ArraySession;
use FermeTranquille\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    private const int NOW = 1_760_000_000;

    private Auth $auth;
    private Api $api;

    protected function setUp(): void
    {
        $this->auth = new Auth(TestDb::create(), new ArraySession());
        $this->auth->createUser('maxime', 'secret-pass');
        $this->api = new Api($this->auth, static fn (): int => self::NOW);
    }

    public function testSessionReturnsCsrfTokenWhenAnonymous(): void
    {
        $response = $this->api->handle('GET', 'session', [], null);

        self::assertSame(200, $response->status);
        self::assertTrue($response->body['ok']);
        self::assertSame(self::NOW, $response->body['server_now']);
        self::assertFalse($response->body['authenticated']);
        self::assertNull($response->body['login']);
        self::assertSame($this->auth->csrfToken(), $response->body['csrf_token']);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'secret-pass'], null);

        self::assertSame(403, $response->status);
        self::assertSame(['ok' => false, 'server_now' => self::NOW, 'error' => 'csrf_invalid'], $response->body);
        self::assertNull($this->auth->userId());
    }

    public function testLoginFlow(): void
    {
        $token = $this->csrf();

        $response = $this->api->handle('POST', '/login/', ['login' => 'maxime', 'password' => 'secret-pass'], $token);

        self::assertSame(200, $response->status);
        self::assertTrue($response->body['authenticated']);
        self::assertSame('maxime', $response->body['login']);
        self::assertNotSame($token, $response->body['csrf_token']);
    }

    public function testLoginWithWrongPassword(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'bad-pass'], $this->csrf());

        self::assertSame(401, $response->status);
        self::assertSame('invalid_credentials', $response->body['error']);
    }

    public function testLoginWithMissingFields(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => 'maxime'], $this->csrf());

        self::assertSame(400, $response->status);
        self::assertSame('missing_credentials', $response->body['error']);
    }

    public function testLoginRejectsNonStringFields(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => ['maxime'], 'password' => 123], $this->csrf());

        self::assertSame(400, $response->status);
    }

    public function testLogout(): void
    {
        $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'secret-pass'], $this->csrf());

        $response = $this->api->handle('POST', 'logout', [], $this->csrf());

        self::assertSame(200, $response->status);
        self::assertFalse($response->body['authenticated']);
        self::assertNull($this->auth->userId());
    }

    public function testUnknownRoute(): void
    {
        $response = $this->api->handle('GET', 'nope', [], null);

        self::assertSame(404, $response->status);
        self::assertSame('not_found', $response->body['error']);
    }

    public function testWrongMethod(): void
    {
        $response = $this->api->handle('GET', 'login', [], null);

        self::assertSame(405, $response->status);
        self::assertSame('method_not_allowed', $response->body['error']);
    }

    private function csrf(): string
    {
        return $this->api->handle('GET', 'session', [], null)->body['csrf_token'];
    }
}
