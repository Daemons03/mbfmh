<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * What a field gives: the crop's yield and flower nectar for a normal field
 * depend on its level (yields[level - 1]); fertile (x1.5) and premium (x1.6)
 * fields multiply both, rounded up like the game; humus then adds 25 % to the
 * crop, rounded down.
 */
final readonly class Crop
{
    public const float HUMUS_BONUS = 0.25;

    /**
     * @return array<string, int> outputs of one harvest
     */
    public static function harvest(GameData $data, string $cropId, string $fieldType, int $fieldLevel, bool $humus): array
    {
        $crop = $data->crop($cropId);
        $index = min($fieldLevel, count($crop['yields'])) - 1;

        $factor = (float) ($data->building($fieldType)['yield_factor'] ?? 1.0);
        // round() first: float noise must not push an exact value up or down.
        $up = static fn (int $base): int => (int) ceil(round($base * $factor, 6));

        $quantity = $up($crop['yields'][$index]);
        if ($humus) {
            $quantity = (int) floor(round($quantity * (1 + self::HUMUS_BONUS), 6));
        }
        $outputs = [$cropId => $quantity];
        $nectar = $up($crop['flower_nectar'][$index] ?? 0);
        if ($nectar > 0) {
            $outputs['flower_nectar'] = $nectar;
        }

        return $outputs;
    }
}
