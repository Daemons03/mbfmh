<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — no refund. Storage cannot be destroyed (barn stock would
 * have nowhere to go), nor a building with a job in progress.
 */
final class Demolish implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));

        if ($farm->data->building($building['type'])['kind'] === 'storage') {
            throw new GameError('not_demolishable', 'La grange ne peut pas être détruite.');
        }
        if (isset($building['job'])) {
            throw new GameError('busy', 'Récoltez ou attendez la fin de la production avant de détruire.');
        }

        $farm->removeBuilding($building['id']);
    }
}
