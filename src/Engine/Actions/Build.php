<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use InvalidArgumentException;

/**
 * payload: { type, x, y, rotated? } — x/y/rotated are ignored for outside buildings.
 */
final class Build implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $type = $payload->string('type');

        try {
            $definition = $farm->data->building($type);
        } catch (InvalidArgumentException) {
            throw new GameError('unknown_building_type', 'Type de bâtiment inconnu.', ['type' => $type]);
        }
        $first = $definition['levels'][0];
        $outside = $farm->data->isOutside($type);
        $x = $outside ? null : $payload->int('x', 0);
        $y = $outside ? null : $payload->int('y', 0);
        $rotated = !$outside && $payload->has('rotated') && $payload->bool('rotated');

        $farm->assertLevel($first['unlock_level'] ?? 1);
        if ($farm->countBuildings($type) >= $definition['max_count']) {
            throw new GameError('max_count_reached', "Nombre maximum atteint pour : {$definition['name']}.", [
                'max' => $definition['max_count'],
            ]);
        }
        if (!$outside) {
            $farm->assertPlaceable($type, $x, $y, rotated: $rotated);
        }
        $farm->assertWorkers(-($first['workers'] ?? 0));
        $farm->pay($first['cost']);
        $farm->addBuilding($type, $x, $y, $rotated);
    }
}
