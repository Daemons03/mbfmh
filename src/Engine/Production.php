<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Recipe of a production building at a given level: outputs come from the
 * level; inputs are main output quantity x inputs_per_main_unit (rounded up)
 * plus the fixed inputs_per_run (e.g. 10 fertilizer for an apple orchard).
 */
final readonly class Production
{
    /**
     * @return array{inputs: array<string, int>, outputs: array<string, int>, duration: int, xp: int}
     */
    public static function recipe(GameData $data, string $type, int $level): array
    {
        $production = $data->building($type)['production'];
        $outputs = $data->buildingLevel($type, $level)['outputs'];
        $main = $outputs[$production['main_output']];

        $inputs = [];
        foreach ($production['inputs_per_main_unit'] as $item => $perUnit) {
            // round() first: 90 x 1.1 is 99.000000000000014 in floats and must not become 100.
            $inputs[$item] = (int) ceil(round($main * $perUnit, 6));
        }
        foreach ($production['inputs_per_run'] ?? [] as $item => $quantity) {
            $inputs[$item] = ($inputs[$item] ?? 0) + (int) $quantity;
        }

        return [
            'inputs' => $inputs,
            'outputs' => $outputs,
            'duration' => $production['duration'],
            'xp' => (int) $production['xp'],
        ];
    }
}
