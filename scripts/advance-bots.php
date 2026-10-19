<?php

declare(strict_types=1);

// Usage: php scripts/advance-bots.php
// Brings the bot world up to date (what the first request of the day would
// do), with no catch-up limit: run just after midnight by the systemd timer
// (see DEPLOY.md) so the bots play even on days without a visit.

use FermeTranquille\BotRepository;
use FermeTranquille\Config;
use FermeTranquille\Db;
use FermeTranquille\Engine\BotWorld;
use FermeTranquille\GameData;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

date_default_timezone_set((string) (Config::get()['app']['timezone'] ?? 'UTC'));
$world = new BotWorld(GameData::load());
$bots = new BotRepository(Db::pdo(), $world);

$start = microtime(true);
$now = time();
$behind = ($meta = $bots->meta()) === null ? 'monde à créer' : $world->daysBehind($meta, $now) . ' jour(s) de retard';
// ensure() catches up max_catch_up_days at a time: loop until up to date.
for ($round = 0; $round < 100; $round++) {
    $bots->ensure($now);
    if ($world->daysBehind($bots->meta(), $now) === 0) {
        break;
    }
}
$meta = $bots->meta();
printf("Bots à jour (%s) : semaine %s, jour du %s, vitesse ×%s, %.1f s\n",
    $behind, $meta['week'], date('Y-m-d', $meta['day_start']), $meta['speed'], microtime(true) - $start);
