<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Market;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — the slot gets a new contract, usable after replace_delay
 * (divided by the speed, like every duration).
 */
final class DiscardContract implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        [$index, $contract] = $farm->contract($payload->string('id'));

        if (Market::isWaiting($contract, $clock)) {
            throw new GameError('contract_waiting', 'Ce contrat n\'est pas encore disponible.');
        }

        $next = (new Market($farm->data))->generate($farm, $clock, waiting: true);
        if ($next !== null) {
            $farm->replaceContract($index, $next);
        }
    }
}
