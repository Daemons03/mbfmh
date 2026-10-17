<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Library (bibliothèque): each product has its own upgrade levels, paid in
 * scrolls, ink and books. Level n adds levels[n - 1].bonus units of the product
 * to every harvest / production batch, fixed when the job starts. A product
 * cannot go above the library building's level. State: library = {item: level}.
 */
final readonly class Library
{
    public function __construct(private GameData $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->data->toArray()['library'] ?? ['items' => []];
    }

    /**
     * Level of the library building, 0 when not built.
     */
    public function buildingLevel(Farm $farm): int
    {
        $building = $farm->buildingsOfType($this->config()['building'] ?? 'library')[0] ?? null;

        return $building['level'] ?? 0;
    }

    public function level(Farm $farm, string $item): int
    {
        return $farm->library()[$item] ?? 0;
    }

    /**
     * Extra units of $item per harvest or production batch.
     */
    public function bonus(Farm $farm, string $item): int
    {
        $level = $this->level($farm, $item);

        return $level === 0 ? 0 : $this->config()['items'][$item]['levels'][$level - 1]['bonus'];
    }

    /**
     * @return array<string, mixed>
     */
    public function view(Farm $farm): array
    {
        $bonus = [];
        foreach ($farm->library() as $item => $_) {
            $bonus[$item] = $this->bonus($farm, $item);
        }

        return ['building_level' => $this->buildingLevel($farm), 'bonus' => (object) $bonus];
    }
}
