<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — quarter turn in place (same top corner, width and depth
 * swapped); refused when the new footprint does not fit. Jobs keep going.
 */
final class Rotate implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));
        if ($farm->data->isOutside($building['type'])) {
            throw new GameError('not_movable', 'Ce bâtiment est hors de la ferme et ne se tourne pas.');
        }
        $rotated = empty($building['rotated']);

        $farm->assertPlaceable($building['type'], $building['x'], $building['y'], $building['id'], $rotated);
        unset($building['rotated']);
        $farm->saveBuilding($building + ($rotated ? ['rotated' => true] : []));
    }
}
