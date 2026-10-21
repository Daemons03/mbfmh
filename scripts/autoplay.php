<?php

declare(strict_types=1);

// Real bots: farms played by Engine/AutoPlayer, a turn every 15 minutes
// (systemd timer ferme-autoplay, see DEPLOY.md).
//
//   php scripts/autoplay.php add <login> <activity>   account created if needed (random password), e.g. add Daemons 1
//   php scripts/autoplay.php remove <login>           the farm is the owner's again
//   php scripts/autoplay.php list
//   php scripts/autoplay.php run                      one turn for every bot (activity = chance to play it)

use FermeTranquille\Auth;
use FermeTranquille\Config;
use FermeTranquille\Db;
use FermeTranquille\Engine\AutoPlayer;
use FermeTranquille\Engine\Engine;
use FermeTranquille\GameData;
use FermeTranquille\Session\Session;
use FermeTranquille\StaleStateException;
use FermeTranquille\StateRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

date_default_timezone_set((string) (Config::get()['app']['timezone'] ?? 'UTC'));
$pdo = Db::pdo();
$data = GameData::load();
$engine = new Engine($data);
$states = new StateRepository($pdo, $engine, purgeChance: 0.001);
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

/** Sets or clears state.autoplay of a farm, with the optimistic lock. */
$mark = static function (int $userId, ?float $activity) use ($states): void {
    for ($try = 0; $try < 3; $try++) {
        ['state' => $state, 'version' => $version] = $states->load($userId);
        if ($activity === null) {
            unset($state['autoplay']);
        } else {
            $state['autoplay'] = ['activity' => $activity];
        }
        try {
            $states->save($userId, $state, $version);

            return;
        } catch (StaleStateException) {
            continue;
        }
    }
    throw new RuntimeException('La ferme change trop souvent, réessayez.');
};

$command = $argv[1] ?? '';
switch ($command) {
    case 'add':
        $login = $argv[2] ?? '';
        $activity = (float) ($argv[3] ?? 1);
        if ($login === '' || $activity <= 0 || $activity > 1) {
            fwrite(STDERR, "Usage : php scripts/autoplay.php add <login> <activité entre 0 et 1>\n");
            exit(1);
        }
        $userId = $auth->findUserId($login) ?? $auth->createUser($login, bin2hex(random_bytes(24))); // nobody logs in as a bot
        $mark($userId, $activity);
        echo "{$login} : joué par un bot à " . round($activity * 100) . " %\n";
        break;

    case 'remove':
        $userId = $auth->findUserId($argv[2] ?? '') ?? exit("Compte inconnu\n");
        $mark($userId, null);
        echo "{$argv[2]} : rendu à son propriétaire\n";
        break;

    case 'list':
        foreach ($states->all() as $farm) {
            if (isset($farm['state']['autoplay'])) {
                printf("%-16s %3d %% · niveau %d\n", $farm['login'], round($farm['state']['autoplay']['activity'] * 100), $farm['state']['level']);
            }
        }
        break;

    case 'run':
        $player = new AutoPlayer($engine, $data);
        foreach ($states->all() as $farm) {
            $activity = $farm['state']['autoplay']['activity'] ?? null;
            if ($activity === null || mt_rand() / mt_getrandmax() >= $activity) {
                continue; // not a bot, or it skips this turn (95 %: one turn in 20)
            }
            ['state' => $state, 'version' => $version] = $states->load($farm['user_id']);
            $turn = $player->turn($state, time());
            try {
                $states->save($farm['user_id'], $turn['state'], $version);
            } catch (StaleStateException) {
                continue; // changed meanwhile (admin, another turn): next turn
            }
            $counts = array_count_values(array_column($turn['actions'], 0));
            $states->logAction($farm['user_id'], 'autoplay', $counts);
            echo $farm['login'], ' : ', json_encode($counts, JSON_UNESCAPED_UNICODE), "\n";
        }
        break;

    default:
        fwrite(STDERR, "Usage : php scripts/autoplay.php add|remove|list|run …\n");
        exit(1);
}
