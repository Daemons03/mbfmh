<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Boosts;
use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Crop;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Library;
use FermeTranquille\Engine\Payload;
use InvalidArgumentException;

/**
 * payload: { id, crop, humus?, boost?, seed? }
 * - humus: true spends 1 humus for +25 % yield;
 * - boost: 'premium' (premium humus, +50 %) or 'instant' (instant humus,
 *   ready at once), instead of humus;
 * - seed: 'premium' spends the crop's premium seed for +10 %.
 * Special items make the planting free. crop may also be a special crop
 * (roses, tulips, golden flowers), which takes its seed.
 */
final class Plant implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $field = $farm->building($payload->string('id'));
        $cropId = $payload->string('crop');
        $boosts = new Boosts($farm->data);

        if ($farm->data->building($field['type'])['kind'] !== 'field') {
            throw new GameError('not_a_field', 'On ne peut planter que dans un champ.');
        }
        if (isset($field['job'])) {
            throw new GameError('busy', 'Ce champ est déjà planté.');
        }
        $special = $boosts->specialCrop($cropId);
        if ($special !== null) {
            $this->plantSpecial($farm, $field, $cropId, $special, $boosts, $clock);

            return;
        }
        try {
            $crop = $farm->data->crop($cropId);
        } catch (InvalidArgumentException) {
            throw new GameError('unknown_crop', 'Culture inconnue.', ['crop' => $cropId]);
        }
        $farm->assertLevel($farm->data->item($cropId)['unlock_level']);

        $humus = $payload->has('humus') && $payload->bool('humus');
        $boost = $payload->has('boost') ? $payload->choice('boost', Boosts::VARIANTS) : null;
        $premiumSeed = $payload->has('seed') && $payload->choice('seed', ['premium']) === 'premium';
        if ($humus && $boost !== null) {
            throw new GameError('invalid_payload', 'Un seul humus par plantation.', ['field' => 'boost']);
        }

        $use = [];
        if ($humus) {
            $use['humus'] = 1;
        }
        if ($boost !== null) {
            $use[$boosts->item('humus', $boost)] = 1;
        }
        if ($premiumSeed) {
            $seed = $boosts->premiumSeed($cropId) ?? throw new GameError('unknown_crop', 'Pas de semence premium pour cette culture.');
            $use[$seed] = 1;
        }
        $farm->assertBarnHas($use);
        $free = $boost !== null || $premiumSeed; // special items are free to use
        $farm->pay($free ? [] : $farm->productionCost($crop['plant_cost']));
        foreach ($use as $item => $count) {
            $farm->removeFromBarn($item, $count);
        }

        $humusBonus = match (true) {
            $humus => Crop::HUMUS_BONUS,
            $boost === 'premium' => Crop::PREMIUM_HUMUS_BONUS,
            default => 0.0,
        };
        $seedFactor = $premiumSeed ? (float) $boosts->config()['premium_seed_factor'] : 1.0;

        // Fixed at planting, like production jobs; the library bonus comes on top.
        $outputs = Crop::harvest($farm->data, $cropId, $field['type'], $field['level'], $humusBonus, $seedFactor);
        $outputs[$cropId] += (new Library($farm->data))->bonus($farm, $cropId);
        $field['job'] = [
            'product' => $cropId,
            'started_at' => $clock->now,
            'duration' => $boost === 'instant' ? 0 : $crop['duration'],
            'outputs' => $outputs,
        ];
        if ($humus) {
            $field['job']['humus'] = true;
        }
        if ($boost !== null) {
            $field['job']['boost'] = $boost;
        }
        if ($premiumSeed) {
            $field['job']['seed'] = 'premium';
        }
        $farm->saveBuilding($field);
    }

    /**
     * Roses and tulips give their reward at once and leave the field empty;
     * golden flowers grow and pay gold at harvest.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $special
     */
    private function plantSpecial(Farm $farm, array $field, string $id, array $special, Boosts $boosts, Clock $clock): void
    {
        $farm->assertBarnHas([$special['seed'] => 1]);
        $farm->removeFromBarn($special['seed'], 1);

        if ($special['instant'] ?? false) {
            if (isset($special['reward']['xp'])) {
                $farm->addXp($special['reward']['xp']);
            }
            if (isset($special['market_xp_by_field_level'])) {
                $farm->addMarketXp($boosts->marketXpByFieldLevel($special['market_xp_by_field_level'], $field['level']));
            }

            return;
        }

        $field['job'] = [
            'product' => $id,
            'started_at' => $clock->now,
            'duration' => $special['duration'],
            'outputs' => [],
            'reward' => $special['reward'],
            'xp' => 0,
        ];
        $farm->saveBuilding($field);
    }
}
