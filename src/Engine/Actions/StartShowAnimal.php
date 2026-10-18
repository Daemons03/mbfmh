<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\ShowAnimals;

/**
 * payload: { animal } — free; the animal works for its own duration (one of
 * its species' three). One animal per species, as many species at once as the
 * building level allows.
 */
final class StartShowAnimal implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $rules = new ShowAnimals($farm->data);
        $config = $rules->config();
        $animalId = $payload->string('animal');

        $animal = $config['animals'][$animalId]
            ?? throw new GameError('unknown_animal', 'Animal inconnu.', ['animal' => $animalId]);
        $show = $rules->state($farm);
        $owned = $show['animals'][$animalId]
            ?? throw new GameError('animal_not_owned', 'Il faut d\'abord obtenir sa carte dans un coffre.');
        $species = $config['species'][$animal['species']];
        $duration = $species['durations'][$animal['duration']];

        if (isset($show['active'][$animal['species']])) {
            throw new GameError('species_busy', "Un animal de cette espèce ({$species['name']}) travaille déjà.");
        }
        $slots = $rules->slots($farm);
        if (count($show['active']) >= $slots) {
            throw new GameError('no_free_slot', 'Tous les emplacements sont occupés : améliorez le bâtiment.', ['slots' => $slots]);
        }

        $outputs = $rules->outputs($animalId, $owned['level']);
        foreach ($outputs as $item => $quantity) {
            if ($quantity > $farm->barnCapacity() && $farm->isBarnLimited($item)) {
                throw new GameError('barn_too_small', "La grange est trop petite pour {$quantity} × {$farm->data->item($item)['name']}.", [
                    'item' => $item,
                    'needed' => $quantity,
                    'capacity' => $farm->barnCapacity(),
                ]);
            }
        }

        $show['active'][$animal['species']] = [
            'animal' => $animalId,
            'started_at' => $clock->now,
            'duration' => $duration,
            'outputs' => $outputs,
        ];
        $farm->setShow($show);
    }
}
