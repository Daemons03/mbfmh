<?php

declare(strict_types=1);

namespace FermeTranquille\Tests;

use FermeTranquille\Auth;
use FermeTranquille\Tests\Support\ArraySession;
use FermeTranquille\Tests\Support\TestDb;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private PDO $pdo;
    private ArraySession $session;
    private Auth $auth;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->session = new ArraySession();
        $this->auth = new Auth($this->pdo, $this->session);
    }

    public function testCreateUserHashesPassword(): void
    {
        $id = $this->auth->createUser('maxime', 'secret-pass');

        $hash = $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $id)->fetchColumn();
        self::assertNotSame('secret-pass', $hash);
        self::assertTrue(password_verify('secret-pass', $hash));
        self::assertSame($id, $this->auth->findUserId('maxime'));
    }

    public function testCreateUserRejectsDuplicateLogin(): void
    {
        $this->auth->createUser('maxime', 'secret-pass');

        $this->expectException(PDOException::class);
        $this->auth->createUser('maxime', 'other-pass');
    }

    #[DataProvider('invalidLogins')]
    public function testCreateUserRejectsInvalidLogin(string $login): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->auth->createUser($login, 'secret-pass');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLogins(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'space' => ['max ime'];
        yield 'quote' => ["max'ime"];
        yield 'too long' => [str_repeat('a', 65)];
    }

    public function testCreateUserRejectsShortPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->auth->createUser('maxime', 'short');
    }

    public function testLoginSuccessStoresUserAndRegeneratesSession(): void
    {
        $id = $this->auth->createUser('maxime', 'secret-pass');
        $tokenBefore = $this->auth->csrfToken();

        self::assertTrue($this->auth->login('maxime', 'secret-pass'));
        self::assertSame($id, $this->auth->userId());
        self::assertSame('maxime', $this->auth->userLogin());
        self::assertSame(1, $this->session->regenerations);
        self::assertNotSame($tokenBefore, $this->auth->csrfToken(), 'CSRF token rotates on login');
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $this->auth->createUser('maxime', 'secret-pass');

        self::assertFalse($this->auth->login('maxime', 'wrong-pass'));
        self::assertNull($this->auth->userId());
        self::assertSame(0, $this->session->regenerations);
    }

    public function testLoginFailsWithUnknownUser(): void
    {
        self::assertFalse($this->auth->login('nobody', 'secret-pass'));
        self::assertNull($this->auth->userId());
    }

    public function testLoginRehashesOutdatedHash(): void
    {
        $id = $this->auth->createUser('maxime', 'secret-pass');
        $weak = password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$weak, $id]);

        self::assertTrue($this->auth->login('maxime', 'secret-pass'));

        $hash = $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $id)->fetchColumn();
        self::assertNotSame($weak, $hash);
        self::assertTrue(password_verify('secret-pass', $hash));
    }

    public function testLogoutClearsSession(): void
    {
        $this->auth->createUser('maxime', 'secret-pass');
        $this->auth->login('maxime', 'secret-pass');

        $this->auth->logout();

        self::assertNull($this->auth->userId());
        self::assertNull($this->auth->userLogin());
    }

    public function testCsrfTokenIsStableAndChecked(): void
    {
        $token = $this->auth->csrfToken();

        self::assertSame(64, strlen($token));
        self::assertSame($token, $this->auth->csrfToken());
        self::assertTrue($this->auth->checkCsrf($token));
        self::assertFalse($this->auth->checkCsrf('nope'));
        self::assertFalse($this->auth->checkCsrf(null));
    }

    public function testCsrfCheckFailsWithoutToken(): void
    {
        self::assertFalse($this->auth->checkCsrf(''));
    }
}
