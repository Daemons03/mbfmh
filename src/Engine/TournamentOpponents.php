<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

/**
 * Where the tournament takes the player's opponents from: the bots and the
 * other real farms. Without it, the tournament falls back to its invented farms.
 */
interface TournamentOpponents
{
    /**
     * The other farms of a tier in a week, with their points at $at, or null
     * when there are no bots (the invented farms are used then). $seed: the
     * farm's tournament seed, left out of its own league.
     *
     * loser: weeks the farm finished last of bronze 1★.
     *
     * @return list<array{id: int|null, name: string, title: string|null, points: int, player?: bool, loser?: int}>|null
     */
    public function league(string $week, int $tier, int $at, string $seed = ''): ?array;

    /**
     * The final place of the farm $seed in its league of $week, once that
     * week is closed for everybody; null when it is not known.
     *
     * @return array{rank: int, size: int}|null
     */
    public function result(string $week, string $seed): ?array;
}
