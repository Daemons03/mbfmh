<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\ShowAnimals;

/**
 * payload: { species } — stops the show animal working for this species; what
 * it would have made is lost and its slot is free again.
 */
final class CancelShowAnimal implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $rules = new ShowAnimals($farm->data);
        $species = $payload->string('species');
        $show = $rules->state($farm);
        $job = $show['active'][$species] ?? throw new GameError('no_job', 'Aucun animal de cette espèce ne travaille.');

        if ($clock->isReady($job)) {
            throw new GameError('already_ready', 'Déjà prêt : récupérez plutôt.');
        }

        unset($show['active'][$species]);
        $farm->setShow($show);
    }
}
