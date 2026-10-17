<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use InvalidArgumentException;

/**
 * payload: { type, x, y }
 */
final class Build implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $type = $payload->string('type');
        $x = $payload->int('x', 0);
        $y = $payload->int('y', 0);

        try {
            $definition = $farm->data->building($type);
        } catch (InvalidArgumentException) {
            throw new GameError('unknown_building_type', 'Type de bâtiment inconnu.', ['type' => $type]);
        }
        $first = $definition['levels'][0];

        $farm->assertLevel($first['unlock_level'] ?? 1);
        if ($farm->countBuildings($type) >= $definition['max_count']) {
            throw new GameError('max_count_reached', "Nombre maximum atteint pour : {$definition['name']}.", [
                'max' => $definition['max_count'],
            ]);
        }
        $farm->assertPlaceable($type, $x, $y);
        $farm->pay($first['cost']);
        $farm->addBuilding($type, $x, $y);
    }
}
