<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — moves the building to its next level.
 */
final class Upgrade implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));
        $next = $farm->data->buildingLevel($building['type'], $building['level'] + 1);

        if ($next === null) {
            throw new GameError('max_level', 'Niveau maximum atteint.');
        }
        if (isset($building['job'])) {
            throw new GameError('busy', 'Impossible d\'améliorer pendant une production.');
        }

        $farm->assertLevel($next['unlock_level'] ?? 1);
        $current = $farm->data->buildingLevel($building['type'], $building['level']);
        $farm->assertWorkers(($current['workers'] ?? 0) - ($next['workers'] ?? 0));
        $farm->pay($next['cost']);

        $building['level']++;
        $farm->saveBuilding($building);
    }
}
