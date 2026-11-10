<?php

declare(strict_types=1);

namespace FermeTranquille;

use FermeTranquille\Engine\BotWorld;
use FermeTranquille\Engine\TournamentOpponents;

/**
 * The tournament's opponents from the bot world: every bot of the tier and
 * every other real farm that plays the same week in the same tier, one
 * league per tier. This week: market XP earned since Monday (bots: today's
 * share growing through the day); a past week: the bots' history. Final
 * places: computed for everybody when the bot world closes the week.
 */
final class BotLeagues implements TournamentOpponents
{
    /** @var array<string, list<array<string, mixed>>|null> */
    private array $cache = [];

    public function __construct(
        private readonly BotRepository $bots,
    ) {
    }

    public function league(string $week, int $tier, int $at, string $seed = ''): ?array
    {
        return $this->cache["{$week}:{$tier}:{$at}:{$seed}"] ??= $this->compute($week, $tier, $at, $seed);
    }

    public function result(string $week, string $seed): ?array
    {
        return $this->bots->result($week, $seed);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function compute(string $week, int $tier, int $at, string $seed): ?array
    {
        $meta = $this->bots->meta();
        if ($meta === null) {
            return null;
        }

        $farms = [];
        if ($week === $meta['week']) {
            $dayLength = max(1, BotWorld::dayStart($meta['day_start'] + 30 * 3600) - $meta['day_start']);
            $share = max(0.0, min(1.0, ($at - $meta['day_start']) / $dayLength));
            $bots = array_map(static fn (array $bot): array => $bot + [
                'points' => (int) floor($bot['day_start_mxp'] - $bot['week_start_mxp'] + $bot['day_gain'] * $share),
            ], $this->bots->bots($tier));
            foreach ($this->bots->farms() as $farm) {
                if ($farm['week'] === $week && $farm['tier'] === $tier && $farm['seed'] !== $seed) {
                    $farms[] = [
                        'id' => null, 'name' => $farm['login'], 'points' => $farm['points'], 'player' => true, 'loser' => $farm['loser'],
                        'title' => $farm['autoplay'] === null ? null : (string) round($farm['autoplay'] * 100),
                    ];
                }
            }
        } else {
            $bots = [];
            foreach ($this->bots->bots() as $bot) {
                if (($bot['history'][$week][0] ?? null) === $tier) {
                    $bots[] = $bot + ['points' => $bot['history'][$week][1]];
                }
            }
            if ($bots === []) {
                return null; // a week older than the bots' history
            }
        }

        // Real farms first: they win ties, as in the final ranking.
        return [...$farms, ...array_map(static fn (array $bot): array => [
            'id' => $bot['id'], 'name' => $bot['name'], 'title' => $bot['title'], 'points' => max(0, $bot['points']),
            'loser' => $bot['loser'] ?? 0,
        ], $bots)];
    }
}
