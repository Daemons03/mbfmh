<?php

declare(strict_types=1);

// Usage: php scripts/calibrate-tournament-cash.php [days] [step]
// Measures the tournament cash base: what a real bot (AutoPlayer, a turn every
// 15 min, activity 90 %) takes in during one day at speed ×1, by level.
// A new farm plays day after day at ×1, like Daemons and Styfller did; the
// days are grouped by `step` levels and averaged. Prints the rows of
// tournament.rank_rewards.cash_by_level. No database.

use FermeTranquille\Engine\AutoPlayer;
use FermeTranquille\Engine\Engine;
use FermeTranquille\GameData;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$days = (int) ($argv[1] ?? 400);
$step = (int) ($argv[2] ?? 10);

date_default_timezone_set('Europe/Paris');
$data = GameData::load();
$engine = new Engine($data);
$player = new AutoPlayer($engine, $data);

$now = (new DateTimeImmutable('2026-11-02 00:00'))->getTimestamp();
$state = $engine->resolve($engine->newState(), $now);
$buckets = [];
for ($day = 1; $day <= $days; $day++) {
    $income = 0;
    for ($turn = 0; $turn < 96; $turn++) {
        $now += AutoPlayer::TURN;
        if ($turn % 10 === 9) {
            continue; // activity 90 %
        }
        $result = $player->turn($state, $now);
        $state = $result['state'];
        $income += $result['income'];
    }
    $level = intdiv($state['level'], $step) * $step;
    $buckets[$level][] = $income;
    fprintf(STDERR, "jour %d : niveau %d, %d encaissés\n", $day, $state['level'], $income);
}
foreach ($buckets as $level => $incomes) {
    printf("[%d, %d],  // %d jour(s)\n", max(1, $level), round(array_sum($incomes) / count($incomes)), count($incomes));
}
