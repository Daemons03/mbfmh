<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — every output goes to the barn, or nothing does.
 */
final class Collect implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));
        $definition = $farm->data->building($building['type']);

        if ($definition['kind'] !== 'production' || !isset($building['job'])) {
            throw new GameError('nothing_to_collect', 'Rien à récupérer ici.');
        }
        $job = $building['job'];
        if (!$clock->isReady($job)) {
            throw new GameError('not_ready', 'Pas encore prêt.', ['remaining' => $clock->remaining($job)]);
        }

        $farm->addToBarn($job['outputs']);
        $farm->addXp((int) $definition['production']['xp']);

        unset($building['job']);
        $farm->saveBuilding($building);
    }
}
