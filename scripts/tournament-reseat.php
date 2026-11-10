<?php

declare(strict_types=1);

// Usage: php scripts/tournament-reseat.php [highest tier] [--apply]
// One-time re-ranking for the one-league-per-tier tournament: every bot and
// real farm up to the highest tier (default 14 = sapphire 3★) is ranked by
// level, then market XP, and the tiers are filled from the highest one down
// with their size (tournament.tiers); bronze 1★ takes the rest. Farms above
// that tier keep theirs. Without --apply, only prints where everybody lands.

use FermeTranquille\BotRepository;
use FermeTranquille\Config;
use FermeTranquille\Db;
use FermeTranquille\Engine\BotWorld;
use FermeTranquille\Engine\Engine;
use FermeTranquille\Engine\Tournament;
use FermeTranquille\GameData;
use FermeTranquille\StaleStateException;
use FermeTranquille\StateRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$highest = (int) (array_values(array_diff($args, ['--apply']))[0] ?? 14);

date_default_timezone_set((string) (Config::get()['app']['timezone'] ?? 'UTC'));
$pdo = Db::pdo();
$data = GameData::load();
$tournament = new Tournament($data);
$bots = new BotRepository($pdo, new BotWorld($data));
$states = new StateRepository($pdo, new Engine($data), purgeChance: 0);
$bots->ensure(time());

$entries = [];
foreach ($bots->bots() as $bot) {
    if ($bot['tier'] <= $highest) {
        $entries[] = ['bot' => $bot['id'], 'name' => $bot['name'], 'level' => $bot['level'], 'market_xp' => $bot['market_xp']];
    }
}
foreach ($states->all() as $farm) {
    $tier = $farm['state']['tournament']['tier'] ?? 0;
    if ($tier <= $highest) {
        $entries[] = ['user' => $farm['user_id'], 'name' => $farm['login'], 'level' => $farm['state']['level'], 'market_xp' => $farm['state']['market_xp'] ?? 0];
    } else {
        printf("%-20s reste en %s\n", $farm['login'], $tournament->tierInfo($tier)['name']);
    }
}
usort($entries, static fn (array $a, array $b): int => [$b['level'], $b['market_xp']] <=> [$a['level'], $a['market_xp']]);

// Fill from the highest tier down; bronze 1★ (tier 0) takes the rest.
$tier = $highest;
$left = $tournament->config()['tiers'][$tier]['size'];
$botTiers = [];
$farmTiers = [];
$counts = [];
foreach ($entries as $entry) {
    while ($tier > 0 && $left <= 0) {
        $tier--;
        $left = $tournament->config()['tiers'][$tier]['size'] ?? PHP_INT_MAX;
    }
    $left--;
    $counts[$tier] = ($counts[$tier] ?? 0) + 1;
    if (isset($entry['bot'])) {
        $botTiers[$entry['bot']] = $tier;
    } else {
        $farmTiers[$entry['user']] = $tier;
        printf("%-20s niveau %3d → %s\n", $entry['name'], $entry['level'], $tournament->tierInfo($tier)['name']);
    }
}
krsort($counts);
foreach ($counts as $t => $count) {
    printf("  %-14s %4d\n", $tournament->tierInfo($t)['name'], $count);
}

if (!$apply) {
    echo "Aperçu seulement : relancer avec --apply pour l'appliquer.\n";
    exit(0);
}
$bots->setTiers($botTiers);
foreach ($farmTiers as $userId => $newTier) {
    for ($try = 0; $try < 5; $try++) {
        ['state' => $state, 'version' => $version] = $states->load($userId);
        $state['tournament']['tier'] = $newTier;
        try {
            $states->save($userId, $state, $version);
            break;
        } catch (StaleStateException) {
            continue;
        }
    }
}
echo "Reclassement appliqué.\n";
