<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\ShowAnimals;

/**
 * payload: { species } — everything to the barn, or nothing.
 */
final class CollectShowAnimal implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $rules = new ShowAnimals($farm->data);
        $species = $payload->string('species');
        $show = $rules->state($farm);
        $job = $show['active'][$species] ?? throw new GameError('nothing_to_collect', 'Rien à récupérer ici.');

        if (!$clock->isReady($job)) {
            throw new GameError('not_ready', 'Pas encore prêt.', ['remaining' => $clock->remaining($job)]);
        }

        $farm->addToBarn($job['outputs']);
        unset($show['active'][$species]);
        $farm->setShow($show);
    }
}
