<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Library;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\Production;

/**
 * payload: { id, recipe?, quantity? } — recipe can be omitted when the
 * building has only one; quantity (batches, default 1) only for batch
 * buildings. Takes inputs from the barn and pays the cost; the job keeps its
 * own outputs (library bonus included) and XP, so the result is fixed at start. Refused when an output
 * is larger than the barn capacity (it could never be collected).
 * "instant" buildings deliver right away, without a job.
 */
final class StartProduction implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));

        if ($farm->data->building($building['type'])['kind'] !== 'production') {
            throw new GameError('not_a_production_building', 'Ce bâtiment ne produit rien.');
        }
        if (isset($building['job'])) {
            throw new GameError('busy', 'Une production est déjà en cours.');
        }

        $recipe = Production::recipe(
            $farm->data,
            $building['type'],
            $building['level'],
            $payload->has('recipe') ? $payload->string('recipe') : null,
        );
        $farm->assertLevel($farm->data->item($recipe['id'])['unlock_level']);
        $recipe['outputs'][$recipe['id']] += (new Library($farm->data))->bonus($farm, $recipe['id']); // per batch
        $quantity = $payload->has('quantity') ? $payload->int('quantity', 1) : 1;
        $max = Production::maxQuantity($farm->data, $building['type'], $building['level'], $recipe['id']);
        if ($quantity > $max) {
            throw new GameError('quantity_too_high', "Ce bâtiment ne peut pas en faire autant : {$max} lot(s) au maximum.", [
                'max' => $max,
            ]);
        }
        $total = Production::scaled($recipe, $quantity);

        $capacity = $farm->barnCapacity();
        foreach ($total['outputs'] as $item => $count) {
            if ($count > $capacity && $farm->isBarnLimited($item)) {
                $name = $farm->data->item($item)['name'];
                throw new GameError('barn_too_small', "La grange est trop petite pour {$count} × {$name} : agrandissez-la d'abord.", [
                    'item' => $item,
                    'needed' => $count,
                    'capacity' => $capacity,
                ]);
            }
        }

        $farm->assertBarnHas($total['inputs']);
        $farm->pay($farm->productionCost($total['cost']));
        foreach ($total['inputs'] as $item => $count) {
            $farm->removeFromBarn($item, $count);
        }

        if (Production::isInstant($farm->data, $building['type'])) {
            // Crafted on the spot (sewing workshop): straight to the barn.
            $farm->addToBarn($total['outputs']);
            $farm->addXp($total['xp']);

            return;
        }

        $building['job'] = [
            'product' => $recipe['id'],
            'quantity' => $quantity,
            'started_at' => $clock->now,
            'duration' => $total['duration'],
            'outputs' => $total['outputs'],
            'xp' => $total['xp'],
        ];
        $farm->saveBuilding($building);
    }
}
