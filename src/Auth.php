<?php

declare(strict_types=1);

namespace FermeTranquille;

use FermeTranquille\Session\Session;
use InvalidArgumentException;
use PDO;

final class Auth
{
    private const string KEY_USER_ID = 'user_id';
    private const string KEY_LOGIN = 'login';
    private const string KEY_CSRF = 'csrf_token';

    public const int PASSWORD_MIN_LENGTH = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
    ) {
    }

    public function login(string $login, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, login, password_hash FROM users WHERE login = ?');
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            // Same cost as a real check, so response time does not reveal valid logins.
            static $dummyHash = null;
            $dummyHash ??= password_hash('dummy-password', PASSWORD_DEFAULT);
            password_verify($password, $dummyHash);

            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->updatePassword((int) $user['id'], $password);
        }

        $this->session->regenerate();
        $this->session->set(self::KEY_USER_ID, (int) $user['id']);
        $this->session->set(self::KEY_LOGIN, (string) $user['login']);
        $this->rotateCsrfToken();

        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    public function userId(): ?int
    {
        $id = $this->session->get(self::KEY_USER_ID);

        return is_int($id) ? $id : null;
    }

    public function userLogin(): ?string
    {
        $login = $this->session->get(self::KEY_LOGIN);

        return is_string($login) ? $login : null;
    }

    public function csrfToken(): string
    {
        $token = $this->session->get(self::KEY_CSRF);

        return is_string($token) ? $token : $this->rotateCsrfToken();
    }

    public function checkCsrf(?string $token): bool
    {
        $expected = $this->session->get(self::KEY_CSRF);

        return is_string($expected) && $token !== null && hash_equals($expected, $token);
    }

    /**
     * @return int new user id
     */
    public function createUser(string $login, string $password): int
    {
        self::assertValidLogin($login);
        self::assertValidPassword($password);

        $stmt = $this->pdo->prepare('INSERT INTO users (login, password_hash) VALUES (?, ?)');
        $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findUserId(string $login): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE login = ?');
        $stmt->execute([$login]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function updatePassword(int $userId, string $password): void
    {
        self::assertValidPassword($password);

        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    public static function assertValidLogin(string $login): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $login) !== 1) {
            throw new InvalidArgumentException('Login invalide : 3 à 64 caractères parmi lettres, chiffres, « _ », « . », « - ».');
        }
    }

    public static function assertValidPassword(string $password): void
    {
        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Mot de passe trop court : %d caractères minimum.', self::PASSWORD_MIN_LENGTH),
            );
        }
    }

    private function rotateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::KEY_CSRF, $token);

        return $token;
    }
}
