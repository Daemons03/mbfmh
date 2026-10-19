<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

/**
 * Where the tournament takes the player's 19 opponents from (the bots).
 * Without it, the tournament falls back to its invented farms.
 */
interface TournamentOpponents
{
    /**
     * The opponents of a week in a tier, with their points at $at, or null
     * when there are none for that week (the invented farms are used then).
     *
     * @return list<array{id: int, name: string, title: string, points: int}>|null
     */
    public function league(string $week, int $tier, int $at): ?array;
}
