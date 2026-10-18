<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * What a field gives: the crop's yield and flower nectar for a normal field
 * depend on its level (yields[level - 1]); fertile (x1.5) and premium (x1.6)
 * fields multiply both, rounded up like the game; a premium seed adds 10 %
 * (rounded up), then humus adds 25 % (premium humus 50 %), rounded down.
 */
final readonly class Crop
{
    public const float HUMUS_BONUS = 0.25;
    public const float PREMIUM_HUMUS_BONUS = 0.5;

    /**
     * @return array<string, int> outputs of one harvest
     */
    public static function harvest(GameData $data, string $cropId, string $fieldType, int $fieldLevel, float $humusBonus = 0.0, float $seedFactor = 1.0): array
    {
        $crop = $data->crop($cropId);
        $index = min($fieldLevel, count($crop['yields'])) - 1;

        $factor = (float) ($data->building($fieldType)['yield_factor'] ?? 1.0);
        // round() first: float noise must not push an exact value up or down.
        $up = static fn (int $base, float $by): int => (int) ceil(round($base * $by, 6));

        $quantity = $up($up($crop['yields'][$index], $factor), $seedFactor);
        if ($humusBonus > 0) {
            $quantity = (int) floor(round($quantity * (1 + $humusBonus), 6));
        }
        $outputs = [$cropId => $quantity];
        $nectar = $up($crop['flower_nectar'][$index] ?? 0, $factor);
        if ($nectar > 0) {
            $outputs['flower_nectar'] = $nectar;
        }

        return $outputs;
    }
}
