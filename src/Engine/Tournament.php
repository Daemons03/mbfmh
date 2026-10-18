<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use DateTimeImmutable;
use DateTimeZone;
use FermeTranquille\GameData;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Solo tournament. One week (Monday 00:00 to Sunday 24:00, server time) in a
 * league of `size`: the player and invented farms. Points = market XP earned
 * during the week. At the end the first `promoted` go up a tier, the last
 * `relegated` go down, and everyone is paid by rank.
 *
 * Tiers: league × stars, 0 = bronze 1★ … 17 = diamond 3★.
 * State: tournament = { week, round, started_at, tier, start_xp, seed, last? }
 * (round counts closed weeks; started_at is when this tournament began).
 *
 * Invented farms: each one belongs to a rank group and finishes the week at
 * objective(group) × (1 + p %), p drawn between (percent - spread) and
 * percent from the curve (admin-tunable, settings.tournament_curve); their
 * score grows linearly from started_at to the end of the week.
 */
final readonly class Tournament
{
    private const int WEEK = 7 * 86400;

    public function __construct(private GameData $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->data->toArray()['tournament'];
    }

    public function tierCount(): int
    {
        return count($this->config()['leagues']) * $this->config()['stars'];
    }

    /**
     * @return array{league: array<string, string>, stars: int, name: string}
     */
    public function tierInfo(int $tier): array
    {
        $stars = $this->config()['stars'];
        $league = $this->config()['leagues'][intdiv($tier, $stars)];
        $star = $tier % $stars + 1;

        return ['league' => $league, 'stars' => $star, 'name' => "{$league['name']} {$star}★"];
    }

    /**
     * Points needed for objectives 1 to 6 in a tier.
     *
     * @return list<int>
     */
    public function objectives(int $tier): array
    {
        $objectives = $this->config()['objectives'];
        $sixth = $objectives['first_tier'] * $objectives['ratio'] ** $tier;

        return array_map(static fn (float $f): int => (int) round($sixth * $f), $objectives['fractions']);
    }

    /** Week id and its start (Monday 00:00, server time zone). */
    public static function week(int $now): array
    {
        $date = (new DateTimeImmutable("@{$now}"))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $monday = $date->modify('monday this week')->setTime(0, 0);

        return ['id' => $date->format('o-\WW'), 'start' => $monday->getTimestamp()];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(Farm $farm): array
    {
        return $farm->tournament();
    }

    /**
     * Starts the first week, or closes a finished one (ranking, rewards,
     * promotion / relegation) and starts the current one. Weeks skipped
     * while away are not played.
     */
    public function settle(Farm $farm, int $now): void
    {
        $week = self::week($now);
        $state = $this->state($farm);
        if ($state === []) {
            $farm->setTournament([
                'week' => $week['id'], 'round' => 0, 'started_at' => $now, 'tier' => 0, 'start_xp' => $farm->marketXp(),
                'seed' => substr(hash('sha256', "tournament:{$now}"), 0, 16),
            ]);

            return;
        }
        if ($state['week'] === $week['id']) {
            return;
        }
        $this->closeWeek($farm, $state, $week['id'], $week['start']);
    }

    /** Admin: ends the current tournament now, as if Sunday were over; a new one runs until the end of the week. */
    public function finishWeek(Farm $farm, int $now): void
    {
        if ($this->state($farm) === []) {
            $this->settle($farm, $now);
        }
        $this->closeWeek($farm, $this->state($farm), self::week($now)['id'], $now);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function closeWeek(Farm $farm, array $state, string $nextWeek, int $nextStart): void
    {
        $points = $farm->marketXp() - $state['start_xp'];
        $rank = $this->rankOf($points, $this->botScores($farm, $state, 1.0));
        $tier = $state['tier'];
        $rewards = $this->rankRewards($tier, $rank);
        $farm->gain(array_diff_key($rewards, ['rose_seed' => true]));
        if (isset($rewards['rose_seed'])) {
            $farm->addToBarn(['rose_seed' => $rewards['rose_seed']]);
        }

        $config = $this->config();
        $newTier = $tier;
        if ($rank <= $config['promoted']) {
            $newTier = min($this->tierCount() - 1, $tier + 1);
        } elseif ($rank > $config['size'] - $config['relegated']) {
            $newTier = max(0, $tier - 1);
        }

        $farm->setTournament([
            'week' => $nextWeek, 'round' => ($state['round'] ?? 0) + 1, 'started_at' => $nextStart,
            'tier' => $newTier, 'start_xp' => $farm->marketXp(), 'seed' => $state['seed'],
            'last' => ['week' => $state['week'], 'tier' => $tier, 'new_tier' => $newTier, 'rank' => $rank, 'points' => $points, 'rewards' => $rewards],
        ]);
    }

    /** 1-based rank of the player among the farms (ties go to the player). */
    public function rankOf(int $points, array $bots): int
    {
        return 1 + count(array_filter($bots, static fn (array $bot): bool => $bot['points'] > $points));
    }

    /**
     * The invented farms of the week with their score at `progress` (0..1
     * of the week).
     *
     * @param array<string, mixed> $state
     * @return list<array{name: string, points: int, group: string}>
     */
    public function botScores(Farm $farm, array $state, float $progress): array
    {
        $config = $this->config();
        $round = $state['round'] ?? 0;
        $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', "{$state['seed']}:{$round}:{$state['tier']}", true)));
        $names = $random->shuffleArray($config['bot_names']);
        $objectives = $this->objectives($state['tier']);
        $curve = $this->curve($farm);

        $bots = [];
        foreach ($config['groups'] as $group => [$from, $to]) {
            $slots = $to - $from + 1;
            if ($group === 'relegated') {
                $slots--; // the player takes one place of the league
            }
            $setting = $curve[$group];
            for ($i = 0; $i < $slots; $i++) {
                $percent = $setting['percent'] - $config['bot_spread'] * $random->nextFloat();
                $final = $objectives[$setting['objective'] - 1] * (1 + $percent / 100);
                $bots[] = ['name' => $names[count($bots) % count($names)], 'points' => (int) floor($final * $progress), 'group' => $group];
            }
        }
        usort($bots, static fn (array $a, array $b): int => $b['points'] <=> $a['points']);

        return $bots;
    }

    /**
     * Bot curve: the data default, overridden by the admin setting.
     *
     * @return array<string, array{objective: int, percent: int}>
     */
    public function curve(Farm $farm): array
    {
        $curve = [];
        foreach ([...$this->config()['bot_curve'], ...($farm->settings()['tournament_curve'] ?? [])] as $row) {
            $curve[$row['group']] = ['objective' => (int) $row['objective'], 'percent' => (int) $row['percent']];
        }

        return $curve;
    }

    public function groupOf(int $rank): string
    {
        foreach ($this->config()['groups'] as $group => [$from, $to]) {
            if ($rank >= $from && $rank <= $to) {
                return $group;
            }
        }

        return 'relegated';
    }

    /**
     * What a rank pays in a tier: cash for everyone; keys, mallets (from
     * gold 1★) and buckets (from sapphire 1★) and roses for the podium.
     *
     * @return array<string, int>
     */
    public function rankRewards(int $tier, int $rank): array
    {
        $rules = $this->config()['rank_rewards'];
        $group = $this->groupOf($rank);
        $rewards = ['cash' => (int) round($rules['cash_base'][$tier] * $rules['cash_share'][$group])];
        if ($group === 'first' || $group === 'podium') {
            $scale = $rules[$group];
            foreach ($rules['materials'] as ['material' => $material, 'from_tier' => $from]) {
                if ($tier >= $from) {
                    $rewards[$material] = $scale['start'] + $scale['step'] * ($tier - $from);
                }
            }
            $rewards['rose_seed'] = $rules['roses'][$group];
        }

        return $rewards;
    }

    /**
     * @return array<string, mixed>
     */
    public function view(Farm $farm, int $now): array
    {
        $state = $this->state($farm);
        $end = self::week($now)['start'] + self::WEEK;
        $start = min($state['started_at'] ?? $end - self::WEEK, $end - 1);
        $progress = max(0.0, min(1.0, ($now - $start) / ($end - $start)));
        $points = $farm->marketXp() - $state['start_xp'];
        $bots = $this->botScores($farm, $state, $progress);
        $rank = $this->rankOf($points, $bots);

        $ranking = $bots;
        array_splice($ranking, $rank - 1, 0, [['name' => 'Ma ferme', 'points' => $points, 'me' => true]]);
        $tier = $this->tierInfo($state['tier']);

        return [
            'tier' => $state['tier'],
            'name' => $tier['name'],
            'emoji' => $tier['league']['emoji'],
            'stars' => $tier['stars'],
            'ends_at' => $end,
            'points' => $points,
            'objectives' => $this->objectives($state['tier']),
            'rank' => $rank,
            'rewards' => $this->rankRewards($state['tier'], $rank),
            'ranking' => array_map(static fn (array $row): array => ['name' => $row['name'], 'points' => $row['points'], 'me' => $row['me'] ?? false], $ranking),
            'curve' => $this->curve($farm),
            'last' => isset($state['last']) ? $state['last'] + [
                'name' => $this->tierInfo($state['last']['tier'])['name'],
                'new_name' => $this->tierInfo($state['last']['new_tier'])['name'],
            ] : null,
        ];
    }
}
