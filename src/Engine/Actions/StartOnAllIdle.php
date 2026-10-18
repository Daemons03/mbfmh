<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * "Tout planter" / "tout produire": the same action on the chosen building,
 * then on every other idle building of its group, as long as resources last.
 * A refused building is rolled back and skipped; when none at all could
 * start, the first refusal is raised.
 */
final class StartOnAllIdle
{
    /**
     * @param callable(array<string, mixed>): bool $sameGroup
     * @param callable(array<string, mixed>, Payload): Payload $adapt payload for one building
     */
    public static function run(Farm $farm, Payload $payload, Clock $clock, Action $action, callable $sameGroup, callable $adapt): void
    {
        $chosen = $farm->building($payload->string('id'));
        $targets = [$chosen];
        foreach ($farm->buildings() as $building) {
            if ($building['id'] !== $chosen['id'] && !isset($building['job']) && $sameGroup($building)) {
                $targets[] = $building;
            }
        }

        $started = 0;
        $firstError = null;
        foreach ($targets as $building) {
            $before = clone $farm;
            try {
                $action->apply($farm, $adapt($building, $payload->with('id', $building['id'])), $clock);
                $started++;
            } catch (GameError $error) {
                $farm->restore($before);
                $firstError ??= $error;
            }
        }

        if ($started === 0) {
            throw $firstError;
        }
    }
}
