<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Special items won at the tournament, free to use (no cash paid):
 * - instant / premium feed (animals) and fertilizer (orchards): one item
 *   replaces the whole feed / fertilizer of a production; instant = ready
 *   at once, premium = main product x premium_factor;
 * - instant / premium humus on fields (ready at once / +50 % instead of +25 %);
 * - premium seeds: crop x premium_seed_factor;
 * - special crops: roses (+XP at once), tulips (market XP at once, by field
 *   level), golden flowers (gold after a while).
 */
final readonly class Boosts
{
    public const array VARIANTS = ['premium', 'instant'];

    public function __construct(private GameData $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->data->toArray()['boosts'] ?? [];
    }

    /**
     * Which boost kind a recipe takes: 'feed', 'fertilizer' or null.
     *
     * @param array<string, int> $inputs
     */
    public function kindFor(array $inputs): ?string
    {
        foreach (['feed', 'fertilizer'] as $kind) {
            if (array_intersect(array_keys($inputs), $this->config()[$kind]['inputs'] ?? []) !== []) {
                return $kind;
            }
        }

        return null;
    }

    public function item(string $kind, string $variant): string
    {
        return $this->config()[$kind][$variant];
    }

    /**
     * The recipe with one special item instead of its feed / fertilizer,
     * no cash cost, and its boost applied.
     *
     * @param array{id: string, inputs: array<string, int>, cost: array<string, int>, outputs: array<string, int>, duration: int, xp: int} $recipe
     * @return array{id: string, inputs: array<string, int>, cost: array<string, int>, outputs: array<string, int>, duration: int, xp: int}
     */
    public function boostRecipe(array $recipe, string $kind, string $variant): array
    {
        $replaced = $this->config()[$kind]['inputs'];
        $recipe['inputs'] = array_diff_key($recipe['inputs'], array_flip($replaced)) + [$this->item($kind, $variant) => 1];
        $recipe['cost'] = [];
        if ($variant === 'instant') {
            $recipe['duration'] = 0;
        } else {
            $recipe['outputs'][$recipe['id']] = $this->premium($recipe['outputs'][$recipe['id']]);
        }

        return $recipe;
    }

    public function premium(int $quantity): int
    {
        return (int) ceil(round($quantity * $this->config()['premium_factor'], 6));
    }

    public function premiumSeed(string $crop): ?string
    {
        return $this->config()['premium_seeds'][$crop] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function specialCrop(string $id): ?array
    {
        $crops = $this->data->toArray()['special_crops'] ?? [];

        return str_starts_with($id, '_') ? null : ($crops[$id] ?? null);
    }

    /** Tulips: market XP for a field level, linear between the known points. */
    public function marketXpByFieldLevel(array $points, int $level): int
    {
        [$lastLevel, $lastXp] = $points[0];
        if ($level <= $lastLevel) {
            return $lastXp;
        }
        foreach ($points as [$pointLevel, $pointXp]) {
            if ($level <= $pointLevel) {
                return (int) round($lastXp + ($pointXp - $lastXp) * ($level - $lastLevel) / ($pointLevel - $lastLevel));
            }
            [$lastLevel, $lastXp] = [$pointLevel, $pointXp];
        }

        return $lastXp;
    }
}
