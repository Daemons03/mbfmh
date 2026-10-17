<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * What a field gives: the crop's yield and flower nectar depend on the field
 * level (yields[level - 1]); humus adds 25 % to the crop, rounded down.
 */
final readonly class Crop
{
    public const float HUMUS_BONUS = 0.25;

    /**
     * @return array<string, int> outputs of one harvest
     */
    public static function harvest(GameData $data, string $cropId, int $fieldLevel, bool $humus): array
    {
        $crop = $data->crop($cropId);
        $index = min($fieldLevel, count($crop['yields'])) - 1;

        $quantity = $crop['yields'][$index];
        if ($humus) {
            $quantity = (int) floor($quantity * (1 + self::HUMUS_BONUS));
        }
        $outputs = [$cropId => $quantity];
        $nectar = $crop['flower_nectar'][$index] ?? 0;
        if ($nectar > 0) {
            $outputs['flower_nectar'] = $nectar;
        }

        return $outputs;
    }
}
