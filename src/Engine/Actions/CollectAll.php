<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: {} — "tout récolter" from the barn: harvests every ready field and
 * collects every ready production. A job whose outputs do not fit in the barn
 * stays in place; when nothing at all fits, the first barn error is raised.
 */
final class CollectAll implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $done = 0;
        $firstError = null;
        foreach ($farm->buildings() as $building) {
            if (!isset($building['job']) || !$clock->isReady($building['job'])) {
                continue;
            }
            $action = match ($farm->data->building($building['type'])['kind']) {
                'field' => new Harvest(),
                'production' => new Collect(),
                default => null,
            };
            if ($action === null) {
                continue;
            }
            try {
                $action->apply($farm, new Payload(['id' => $building['id']]), $clock);
                $done++;
            } catch (GameError $error) {
                $firstError ??= $error;
            }
        }

        if ($done === 0) {
            throw $firstError ?? new GameError('nothing_to_collect', 'Rien à récolter pour le moment.');
        }
    }
}
