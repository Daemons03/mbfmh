<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use InvalidArgumentException;

/**
 * payload: { id, crop }
 */
final class Plant implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $field = $farm->building($payload->string('id'));
        $cropId = $payload->string('crop');

        if ($farm->data->building($field['type'])['kind'] !== 'field') {
            throw new GameError('not_a_field', 'On ne peut planter que dans un champ.');
        }
        if (isset($field['job'])) {
            throw new GameError('busy', 'Ce champ est déjà planté.');
        }
        try {
            $crop = $farm->data->crop($cropId);
        } catch (InvalidArgumentException) {
            throw new GameError('unknown_crop', 'Culture inconnue.', ['crop' => $cropId]);
        }

        $farm->assertLevel($farm->data->item($cropId)['unlock_level']);
        $farm->pay($crop['plant_cost']);

        $field['job'] = ['product' => $cropId, 'started_at' => $clock->now, 'duration' => $crop['duration']];
        $farm->saveBuilding($field);
    }
}
