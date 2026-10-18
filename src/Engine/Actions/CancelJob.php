<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — stops the crop or production running in a field or a
 * production building. Everything it took (cash, seeds, humus, feed,
 * fertilizer, ingredients) is lost; the building is free again.
 */
final class CancelJob implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = SpeedUp::runningJob($farm, $payload->string('id'), $clock);

        unset($building['job']);
        $farm->saveBuilding($building);
    }
}
