<?php

declare(strict_types=1);

namespace FermeTranquille;

use FermeTranquille\Engine\BotWorld;
use FermeTranquille\Engine\TournamentOpponents;

/**
 * The player's tournament opponents taken from the bot world: the first
 * league of the tier (the one with the player's seat, as BotWorld::closeWeek
 * forms them), completed from the nearest tiers when the tier is short.
 * This week: market XP earned since Monday, today's share growing through
 * the day; a past week: what the bots made that week (their history).
 */
final class BotLeagues implements TournamentOpponents
{
    /** @var array<string, list<array{id: int, name: string, title: string, points: int}>|null> */
    private array $cache = [];

    public function __construct(
        private readonly BotRepository $bots,
        private readonly GameData $data,
    ) {
    }

    public function league(string $week, int $tier, int $at): ?array
    {
        return $this->cache["{$week}:{$tier}:{$at}"] ??= $this->compute($week, $tier, $at);
    }

    /**
     * @return list<array{id: int, name: string, title: string, points: int}>|null
     */
    private function compute(string $week, int $tier, int $at): ?array
    {
        $meta = $this->bots->meta();
        if ($meta === null) {
            return null;
        }
        $size = $this->data->toArray()['tournament']['size'];

        if ($week === $meta['week']) {
            $dayLength = max(1, BotWorld::dayStart($meta['day_start'] + 30 * 3600) - $meta['day_start']);
            $share = max(0.0, min(1.0, ($at - $meta['day_start']) / $dayLength));
            $tierOf = static fn (array $bot): int => $bot['tier'];
            $points = static fn (array $bot): int => (int) floor($bot['day_start_mxp'] - $bot['week_start_mxp'] + $bot['day_gain'] * $share);
            $members = $this->bots->bots($tier);
            $all = fn (): array => $this->bots->bots();
        } else {
            $all = $this->bots->bots();
            $played = array_values(array_filter($all, static fn (array $bot): bool => isset($bot['history'][$week])));
            if ($played === []) {
                return null; // a week older than the bots' history
            }
            $tierOf = static fn (array $bot): int => $bot['history'][$week][0];
            $points = static fn (array $bot): int => $bot['history'][$week][1];
            $members = array_values(array_filter($played, static fn (array $bot): bool => $tierOf($bot) === $tier));
            $all = static fn (): array => $played;
        }

        $league = BotWorld::leagues($members, $week, $size, static fn (array $bot): int => $bot['id'])[0] ?? [];
        if (count($league) < $size - 1) {
            // Short tier: the nearest tiers fill the league.
            $others = array_values(array_filter($all(), static fn (array $bot): bool => $tierOf($bot) !== $tier));
            usort($others, static fn (array $a, array $b): int => abs($tierOf($a) - $tier) <=> abs($tierOf($b) - $tier)
                ?: crc32("{$week}:{$a['id']}") <=> crc32("{$week}:{$b['id']}"));
            $league = [...$league, ...array_slice($others, 0, $size - 1 - count($league))];
        }

        return array_map(static fn (array $bot): array => [
            'id' => $bot['id'], 'name' => $bot['name'], 'title' => $bot['title'], 'points' => max(0, $points($bot)),
        ], $league);
    }
}
