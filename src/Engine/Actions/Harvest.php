<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Crop;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id, sell_surplus? } — the whole harvest (crop + flower nectar) goes
 * to the barn, or nothing does; with sell_surplus, what does not fit is sold at
 * barn price instead. Golden flowers pay their gold.
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

        if (isset($job['reward'])) {
            // Special crop (golden flowers): a reward instead of a harvest.
            $farm->gain($job['reward']);
        } else {
            // Jobs planted before per-level yields stored no outputs: compute them now.
            $outputs = $job['outputs'] ?? Crop::harvest(
                $farm->data, $job['product'], $field['type'], $field['level'], empty($job['humus']) ? 0.0 : Crop::HUMUS_BONUS,
            );
            if ($payload->has('sell_surplus') && $payload->bool('sell_surplus')) {
                $farm->addToBarnSellingSurplus($outputs);
            } else {
                $farm->addToBarn($outputs);
            }
        }
        $farm->addXp($job['xp'] ?? $farm->data->crop($job['product'])['xp']);

        unset($field['job']);
        $farm->saveBuilding($field);
    }
}
