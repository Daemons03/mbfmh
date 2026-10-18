<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Payload;

/**
 * payload: same as plant — plants the chosen field, then every other free
 * field with the same crop and options while resources last.
 */
final class PlantAll implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        StartOnAllIdle::run(
            $farm,
            $payload,
            $clock,
            new Plant(),
            fn (array $building): bool => $farm->data->building($building['type'])['kind'] === 'field',
            fn (array $building, Payload $one): Payload => $one,
        );
    }
}
