<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Crop;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — the whole harvest (crop + flower nectar) goes to the barn, or nothing does.
 */
final class Harvest implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $field = $farm->building($payload->string('id'));

        if ($farm->data->building($field['type'])['kind'] !== 'field' || !isset($field['job'])) {
            throw new GameError('nothing_to_harvest', 'Rien à récolter ici.');
        }
        $job = $field['job'];
        if (!$clock->isReady($job)) {
            throw new GameError('not_ready', 'Pas encore prêt.', ['remaining' => $clock->remaining($job)]);
        }

        $crop = $farm->data->crop($job['product']);
        // Jobs planted before per-level yields stored no outputs: compute them now.
        $farm->addToBarn($job['outputs'] ?? Crop::harvest($farm->data, $job['product'], $field['type'], $field['level'], !empty($job['humus'])));
        $farm->addXp($crop['xp']);

        unset($field['job']);
        $farm->saveBuilding($field);
    }
}
