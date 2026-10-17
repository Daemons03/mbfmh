<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\Production;

/**
 * payload: { id } — takes the inputs from the barn and starts a job. The job
 * keeps its own outputs, so the result is fixed at start.
 */
final class StartProduction implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));

        if ($farm->data->building($building['type'])['kind'] !== 'production') {
            throw new GameError('not_a_production_building', 'Ce bâtiment ne produit rien.');
        }
        if (isset($building['job'])) {
            throw new GameError('busy', 'Une production est déjà en cours.');
        }

        $recipe = Production::recipe($farm->data, $building['type'], $building['level']);
        $farm->assertBarnHas($recipe['inputs']);
        foreach ($recipe['inputs'] as $item => $quantity) {
            $farm->removeFromBarn($item, $quantity);
        }

        $building['job'] = [
            'product' => $farm->data->building($building['type'])['production']['main_output'],
            'started_at' => $clock->now,
            'duration' => $recipe['duration'],
            'outputs' => $recipe['outputs'],
        ];
        $farm->saveBuilding($building);
    }
}
