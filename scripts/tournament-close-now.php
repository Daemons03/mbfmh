<?php

declare(strict_types=1);

// Usage: php scripts/tournament-close-now.php
// Admin: ends the tournament week now for everybody, bots and real farms:
// places, lots, promotion / relegation, rewards and the last place's prize,
// as on a Monday. A new season starts at once and ends with the normal
// Monday close.

use FermeTranquille\BotLeagues;
use FermeTranquille\BotRepository;
use FermeTranquille\Config;
use FermeTranquille\Db;
use FermeTranquille\Engine\Admin;
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

date_default_timezone_set((string) (Config::get()['app']['timezone'] ?? 'UTC'));
$pdo = Db::pdo();
$data = GameData::load();
$now = time();
$bots = new BotRepository($pdo, new BotWorld($data));
$results = $bots->closeWeekNow($now);
$week = $bots->meta()['week'];
printf("Bots : semaine %s close, %d vraie(s) ferme(s) classée(s)\n", $week, count($results));

// Each real farm of the week closes it with its recorded place.
$engine = (new Engine($data))->withOpponents(new BotLeagues($bots));
$admin = new Admin($data, $engine);
$tournament = new Tournament($data);
$states = new StateRepository($pdo, $engine, purgeChance: 0);
foreach ($states->all() as $farm) {
    $seed = $farm['state']['tournament']['seed'] ?? null;
    if (($farm['state']['tournament']['week'] ?? null) !== $week || !isset($results[$seed])) {
        continue;
    }
    for ($try = 0; $try < 5; $try++) {
        ['state' => $state, 'version' => $version] = $states->load($farm['user_id']);
        $state = json_decode(json_encode($admin->apply($state, 'finish_tournament_week', [], $now)), true);
        try {
            $states->save($farm['user_id'], $state, $version);
            $states->logAction($farm['user_id'], 'admin:finish_tournament_week', ['all' => true]);
            $last = $state['tournament']['last'];
            printf("%-20s %s / %s en %s → %s, %s 💵\n", $farm['login'], $last['rank'], $last['size'],
                $tournament->tierInfo($last['tier'])['name'], $tournament->tierInfo($last['new_tier'])['name'], number_format($last['rewards']['cash'], 0, ',', ' '));
            break;
        } catch (StaleStateException) {
            continue;
        }
    }
}
