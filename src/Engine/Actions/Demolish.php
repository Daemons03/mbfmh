<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — no refund. Storage (barn stock would have nowhere to go),
 * outside buildings and buildings with a job in progress cannot be destroyed.
 */
final class Demolish implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));

        if ($farm->data->building($building['type'])['kind'] === 'storage' || $farm->data->isOutside($building['type'])) {
            throw new GameError('not_demolishable', 'Ce bâtiment ne peut pas être détruit.');
        }
        if (isset($building['job'])) {
            throw new GameError('busy', 'Récoltez ou attendez la fin de la production avant de détruire.');
        }

        $provided = $farm->data->buildingLevel($building['type'], $building['level'])['workers'] ?? 0;
        if ($provided > 0 && $farm->workers()['free'] < $provided) {
            throw new GameError('workers_needed', 'Ces ouvriers travaillent encore : libérez-les avant de détruire l\'habitation.');
        }

        $farm->removeBuilding($building['id']);
    }
}
