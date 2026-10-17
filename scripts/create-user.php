<?php

declare(strict_types=1);

// Usage: php scripts/create-user.php <login>
// Creates the player account, or resets its password if it already exists.

use FermeTranquille\Auth;
use FermeTranquille\Db;
use FermeTranquille\Session\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$login = $argv[1] ?? null;
if ($login === null) {
    fwrite(STDERR, "Usage : php scripts/create-user.php <login>\n");
    exit(1);
}

function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    $tty = $hidden && stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return rtrim((string) $value, "\r\n");
}

// CLI does not need a real session.
$noSession = new class implements Session {
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value): void
    {
    }

    public function remove(string $key): void
    {
    }

    public function regenerate(): void
    {
    }

    public function destroy(): void
    {
    }
};

try {
    Auth::assertValidLogin($login);
    $auth = new Auth(Db::pdo(), $noSession);
    $existingId = $auth->findUserId($login);

    if ($existingId !== null) {
        $answer = prompt("Le compte « {$login} » existe déjà. Changer son mot de passe ? [o/N] ");
        if (!in_array(strtolower($answer), ['o', 'oui', 'y', 'yes'], true)) {
            fwrite(STDOUT, "Rien n'a été modifié.\n");
            exit(0);
        }
    }

    $password = prompt('Mot de passe : ', true);
    Auth::assertValidPassword($password);
    if (prompt('Confirmation : ', true) !== $password) {
        fwrite(STDERR, "Les mots de passe ne correspondent pas.\n");
        exit(1);
    }

    if ($existingId !== null) {
        $auth->updatePassword($existingId, $password);
        fwrite(STDOUT, "Mot de passe de « {$login} » mis à jour.\n");
    } else {
        $id = $auth->createUser($login, $password);
        fwrite(STDOUT, "Compte « {$login} » créé (id {$id}).\n");
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
