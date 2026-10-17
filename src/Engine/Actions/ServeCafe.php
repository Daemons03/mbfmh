<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Cafe;
use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { recipe } — serves a café recipe: takes the products, pays cash,
 * satisfaction points and XP; daily milestones are granted as they are reached.
 */
final class ServeCafe implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $cafeRules = new Cafe($farm->data);
        $config = $cafeRules->config();
        $recipeId = $payload->string('recipe');

        $cafe = $farm->buildingsOfType($config['building'])[0] ?? null;
        if ($cafe === null) {
            throw new GameError('no_cafe', 'Construisez d\'abord le café-boutique.');
        }
        $recipe = $config['recipes'][$recipeId]
            ?? throw new GameError('unknown_recipe', 'Recette inconnue.', ['recipe' => $recipeId]);
        if ($cafe['level'] < $recipe['cafe_level']) {
            throw new GameError('cafe_level_too_low', "Café niveau {$recipe['cafe_level']} requis.", [
                'required' => $recipe['cafe_level'],
            ]);
        }

        $farm->assertBarnHas($recipe['inputs']);
        foreach ($recipe['inputs'] as $item => $quantity) {
            $farm->removeFromBarn($item, $quantity);
        }

        $today = $cafeRules->today($farm, $clock->now);
        $served = $today['served'][$recipeId] ?? 0;
        $reward = $cafeRules->reward($recipeId, $cafeRules->stars($served), $clock->now);

        $today['served'][$recipeId] = $served + 1;
        $today['points'] += $reward['points'];
        $farm->gain(['cash' => $reward['cash']]);
        $farm->addXp((int) round($reward['points'] * $config['xp_per_point']));

        $milestones = $config['milestones'];
        while ($today['rewarded'] < count($milestones) && $today['points'] >= $milestones[$today['rewarded']]['points']) {
            $farm->gain($milestones[$today['rewarded']]['reward']);
            $today['rewarded']++;
        }

        $farm->setCafe($today);
    }
}
