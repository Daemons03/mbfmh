<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Recipes of production buildings. Each level lists its recipes, keyed by
 * main output: { inputs, cost, outputs, duration, xp } for one batch.
 *
 * - "run" buildings (animals, orchards) always make exactly one batch.
 * - "batch" buildings make 1..n batches at once, up to the level's capacity:
 *   max_output units of the main output (windmill, silo, composter, beehive)
 *   or max_batches (dairy, jam kitchen). Inputs, cost, outputs, duration and
 *   XP are multiplied by the number of batches.
 */
final readonly class Production
{
    /**
     * @return array<string, array{inputs: array<string, int>, cost: array<string, int>, outputs: array<string, int>, duration: int, xp: int}>
     */
    public static function recipes(GameData $data, string $type, int $level): array
    {
        $recipes = [];
        foreach ($data->buildingLevel($type, $level)['recipes'] ?? [] as $main => $recipe) {
            $recipes[$main] = [
                'inputs' => $recipe['inputs'] ?? [],
                'cost' => $recipe['cost'] ?? [],
                'outputs' => $recipe['outputs'],
                'duration' => $recipe['duration'],
                'xp' => (int) $recipe['xp'],
            ];
        }

        return $recipes;
    }

    /**
     * @param string|null $recipeId may be omitted when the building has a single recipe
     * @return array{id: string, inputs: array<string, int>, cost: array<string, int>, outputs: array<string, int>, duration: int, xp: int}
     * @throws GameError
     */
    public static function recipe(GameData $data, string $type, int $level, ?string $recipeId = null): array
    {
        $recipes = self::recipes($data, $type, $level);
        if ($recipeId === null) {
            if (count($recipes) !== 1) {
                throw new GameError('invalid_payload', 'Choisissez une recette.', ['field' => 'recipe']);
            }
            $recipeId = array_key_first($recipes);
        }
        $recipe = $recipes[$recipeId] ?? throw new GameError('unknown_recipe', 'Recette inconnue pour ce bâtiment.', [
            'recipe' => $recipeId,
        ]);

        return ['id' => $recipeId] + $recipe;
    }

    public static function isBatch(GameData $data, string $type): bool
    {
        return ($data->building($type)['production']['mode'] ?? 'run') === 'batch';
    }

    /**
     * Most batches of $recipeId a job can hold at this level (1 for run buildings).
     */
    public static function maxQuantity(GameData $data, string $type, int $level, string $recipeId): int
    {
        if (!self::isBatch($data, $type)) {
            return 1;
        }
        $definition = $data->buildingLevel($type, $level);
        if (isset($definition['max_batches'])) {
            return $definition['max_batches'];
        }
        $perBatch = self::recipes($data, $type, $level)[$recipeId]['outputs'][$recipeId];

        return max(1, intdiv($definition['max_output'], $perBatch));
    }

    /**
     * Totals for $quantity batches.
     *
     * @param array{inputs: array<string, int>, cost: array<string, int>, outputs: array<string, int>, duration: int, xp: int} $recipe
     * @return array{inputs: array<string, int>, cost: array<string, int>, outputs: array<string, int>, duration: int, xp: int}
     */
    public static function scaled(array $recipe, int $quantity): array
    {
        $times = static fn (array $quantities): array => array_map(static fn (int $q): int => $q * $quantity, $quantities);

        return [
            'inputs' => $times($recipe['inputs']),
            'cost' => $times($recipe['cost']),
            'outputs' => $times($recipe['outputs']),
            'duration' => $recipe['duration'] * $quantity,
            'xp' => $recipe['xp'] * $quantity,
        ];
    }
}
