<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id, x, y } — running jobs keep going.
 */
final class Move implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));
        if ($farm->data->isOutside($building['type'])) {
            throw new GameError('not_movable', 'Ce bâtiment est hors de la ferme et ne se déplace pas.');
        }
        $x = $payload->int('x', 0);
        $y = $payload->int('y', 0);

        $farm->assertPlaceable($building['type'], $x, $y, $building['id']);
        $farm->saveBuilding(['x' => $x, 'y' => $y] + $building);
    }
}
