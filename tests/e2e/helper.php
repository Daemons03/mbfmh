<?php

declare(strict_types=1);

// Test helper for the browser tests: manipulates the dedicated E2E account
// through the app's own config and classes (no MySQL root access needed).
//
//   php tests/e2e/helper.php reset-user  <login> <password>
//   php tests/e2e/helper.php delete-user <login>
//   php tests/e2e/helper.php patch-state <login> '{"settings.speed": 100, "barn.items": {"corn": 30}}'
//   php tests/e2e/helper.php get-state   <login>      -> {"state": ..., "version": n}
//   php tests/e2e/helper.php log         <login>      -> ["plant", "harvest", ...]
//   php tests/e2e/helper.php add-house   <login> <x> <y>   (10 more workers, -35 happiness)
//   php tests/e2e/helper.php view        <login>      -> derived values (workers, happiness...)
//   php tests/e2e/helper.php reset-bots  <login>      (empties the bot world: recreated at the next request)
//   php tests/e2e/helper.php ensure-bots <login>      (creates / updates the bot world now, so no request waits for it)

use FermeTranquille\Auth;
use FermeTranquille\BotLeagues;
use FermeTranquille\BotRepository;
use FermeTranquille\Config;
use FermeTranquille\Db;
use FermeTranquille\Engine\BotWorld;
use FermeTranquille\Engine\Engine;
use FermeTranquille\GameData;
use FermeTranquille\Session\Session;
use FermeTranquille\StateRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

[, $command, $login] = $argv + [null, null, null];
if ($command === null || $login === null) {
    fwrite(STDERR, "Usage: php tests/e2e/helper.php <command> <login> [args]\n");
    exit(2);
}
// Never touch a real player account by mistake.
if (!str_starts_with($login, 'e2e-')) {
    fwrite(STDERR, "Refusing to touch a login that does not start with \"e2e-\".\n");
    exit(2);
}

$pdo = Db::pdo();
date_default_timezone_set((string) (Config::get()['app']['timezone'] ?? 'UTC')); // the API's day
$bots = new BotRepository($pdo, new BotWorld(GameData::load()));
// Same engine as the API: the tournament against the bots.
$engine = (new Engine(GameData::load()))->withOpponents(new BotLeagues($bots, GameData::load()));
$states = new StateRepository($pdo, $engine, purgeChance: 0);
$auth = new Auth($pdo, new class implements Session {
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
});

$userId = static function () use ($auth, $login): int {
    return $auth->findUserId($login) ?? throw new RuntimeException("Unknown user {$login}");
};
$print = static function (mixed $value): void {
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
};

switch ($command) {
    case 'reset-user':
        $pdo->prepare('DELETE FROM users WHERE login = ?')->execute([$login]); // cascades to state and log
        $print(['id' => $auth->createUser($login, $argv[3] ?? throw new RuntimeException('Missing password'))]);
        break;

    case 'delete-user':
        $pdo->prepare('DELETE FROM users WHERE login = ?')->execute([$login]);
        $print(['deleted' => $login]);
        break;

    case 'patch-state':
        $patch = json_decode($argv[3] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        ['state' => $state, 'version' => $version] = $states->load($userId());
        foreach ($patch as $path => $value) {
            $target = &$state;
            foreach (explode('.', $path) as $key) {
                if (is_object($target)) {
                    $target = (array) $target;
                }
                $target = &$target[$key];
            }
            $target = $value;
            unset($target);
        }
        $print(['version' => $states->save($userId(), $state, $version)]);
        break;

    case 'get-state':
        $print($states->load($userId()));
        break;

    case 'add-house':
        ['state' => $state, 'version' => $version] = $states->load($userId());
        $state['buildings'][] = ['id' => 'e2e-house-' . count($state['buildings']), 'type' => 'house',
            'x' => (int) ($argv[3] ?? 10), 'y' => (int) ($argv[4] ?? 0), 'level' => 1];
        $print(['version' => $states->save($userId(), $state, $version)]);
        break;

    case 'view':
        $print($engine->view($states->load($userId())['state'], time()));
        break;

    case 'reset-bots':
        $pdo->exec('DELETE FROM bots');
        $pdo->exec('DELETE FROM bot_world');
        $print(true);
        break;

    case 'ensure-bots':
        $bots->ensure(time());
        $print(true);
        break;

    case 'log':
        $stmt = $pdo->prepare('SELECT action_type FROM action_log WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId()]);
        $print($stmt->fetchAll(PDO::FETCH_COLUMN));
        break;

    default:
        fwrite(STDERR, "Unknown command {$command}\n");
        exit(2);
}
