<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Mutable wrapper around state_json with the domain operations actions need.
 * Every rule violation throws a GameError and leaves no partial change behind
 * (checks happen before mutations).
 */
final class Farm
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private array $state,
        public readonly GameData $data,
    ) {
    }

    /**
     * Fresh farm from initial_state.
     */
    public static function create(GameData $data): self
    {
        $initial = $data->initialState();
        $farm = new self([
            'level' => $initial['level'],
            'xp' => $initial['xp'],
            'cash' => $initial['cash'],
            'gold' => $initial['gold'],
            'materials' => array_fill_keys($data->materialIds(), 0),
            'barn' => ['items' => new \stdClass()],
            'expansions' => $initial['expansions'],
            'buildings' => [],
            'next_id' => 1,
            'contracts' => [],
            'settings' => ['speed' => 1],
        ], $data);

        foreach ($initial['buildings'] as $building) {
            $farm->assertPlaceable($building['type'], $building['x'], $building['y']);
            $farm->addBuilding($building['type'], $building['x'], $building['y']);
        }

        return $farm;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $state = $this->state;
        // Keep JSON objects as objects even when empty.
        $state['barn']['items'] = (object) $state['barn']['items'];

        return $state;
    }

    // ------------------------------------------------------------ progress

    public function level(): int
    {
        return $this->state['level'];
    }

    public function xp(): int
    {
        return $this->state['xp'];
    }

    /**
     * Adds XP and applies level-ups. xp is the progress inside the current level.
     */
    public function addXp(int $amount): void
    {
        $this->state['xp'] += $amount;
        while (($needed = $this->data->xpForLevel($this->state['level'])) > 0 && $this->state['xp'] >= $needed) {
            $this->state['xp'] -= $needed;
            $this->state['level']++;
        }
        if ($this->state['level'] >= $this->data->maxLevel()) {
            $this->state['xp'] = 0;
        }
    }

    public function assertLevel(int $required): void
    {
        if ($this->level() < $required) {
            throw new GameError('level_too_low', "Niveau {$required} requis.", ['required' => $required]);
        }
    }

    public function speed(): float
    {
        return (float) $this->state['settings']['speed'];
    }

    public function expansions(): int
    {
        return $this->state['expansions'];
    }

    // ------------------------------------------------------------ resources

    public function resource(string $id): int
    {
        return match ($id) {
            'cash', 'gold' => $this->state[$id],
            default => $this->state['materials'][$id] ?? 0,
        };
    }

    /**
     * @param array<string, int> $cost
     */
    public function canAfford(array $cost): bool
    {
        foreach ($cost as $resource => $quantity) {
            if ($this->resource($resource) < $quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int> $cost
     */
    public function pay(array $cost): void
    {
        $missing = [];
        foreach ($cost as $resource => $quantity) {
            if ($this->resource($resource) < $quantity) {
                $missing[$resource] = $quantity - $this->resource($resource);
            }
        }
        if ($missing !== []) {
            throw new GameError('not_enough_resources', 'Ressources insuffisantes.', ['missing' => $missing]);
        }

        foreach ($cost as $resource => $quantity) {
            $this->addResource($resource, -$quantity);
        }
    }

    /**
     * @param array<string, int> $resources
     */
    public function gain(array $resources): void
    {
        foreach ($resources as $resource => $quantity) {
            $this->addResource($resource, $quantity);
        }
    }

    private function addResource(string $id, int $delta): void
    {
        match ($id) {
            'cash', 'gold' => $this->state[$id] += $delta,
            default => $this->state['materials'][$id] = ($this->state['materials'][$id] ?? 0) + $delta,
        };
    }

    // ------------------------------------------------------------ barn

    /**
     * @return array<string, int>
     */
    public function barnItems(): array
    {
        return (array) $this->state['barn']['items'];
    }

    public function barnQuantity(string $item): int
    {
        return $this->barnItems()[$item] ?? 0;
    }

    /**
     * Capacity applies to each product separately (200 corn and 200 wheat fit together).
     */
    public function barnCapacity(): int
    {
        $capacity = 0;
        foreach ($this->buildingsOfKind('storage') as $barn) {
            $capacity += $this->data->buildingLevel($barn['type'], $barn['level'])['capacity'];
        }

        return $capacity;
    }

    /**
     * @return list<string> products stored at full capacity
     */
    public function barnFullItems(): array
    {
        $capacity = $this->barnCapacity();

        return array_keys(array_filter($this->barnItems(), static fn (int $quantity): bool => $quantity >= $capacity));
    }

    /**
     * All or nothing: refused if any product would exceed the capacity.
     *
     * @param array<string, int> $items
     */
    public function addToBarn(array $items): void
    {
        $capacity = $this->barnCapacity();
        foreach ($items as $item => $quantity) {
            $free = $capacity - $this->barnQuantity($item);
            if ($quantity > $free) {
                $name = $this->data->item($item)['name'];
                throw new GameError('barn_full', "Plus de place dans la grange pour : {$name}.", [
                    'item' => $item,
                    'free' => max(0, $free),
                    'needed' => $quantity,
                ]);
            }
        }

        $stock = $this->barnItems();
        foreach ($items as $item => $quantity) {
            $stock[$item] = ($stock[$item] ?? 0) + $quantity;
        }
        $this->state['barn']['items'] = $stock;
    }

    public function removeFromBarn(string $item, int $quantity): void
    {
        $have = $this->barnQuantity($item);
        if ($have < $quantity) {
            throw new GameError('not_enough_items', 'Pas assez de stock dans la grange.', [
                'item' => $item,
                'missing' => $quantity - $have,
            ]);
        }

        $stock = $this->barnItems();
        $stock[$item] = $have - $quantity;
        if ($stock[$item] === 0) {
            unset($stock[$item]);
        }
        $this->state['barn']['items'] = $stock;
    }

    // ------------------------------------------------------------ market

    /**
     * @return list<array<string, mixed>>
     */
    public function contracts(): array
    {
        return $this->state['contracts'] ?? [];
    }

    /**
     * @return array{int, array<string, mixed>} [slot index, contract]
     */
    public function contract(string $id): array
    {
        foreach ($this->contracts() as $index => $contract) {
            if ($contract['id'] === $id) {
                return [$index, $contract];
            }
        }

        throw new GameError('unknown_contract', 'Contrat introuvable.', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function addContract(array $contract): void
    {
        $this->state['contracts'][] = $contract;
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function replaceContract(int $index, array $contract): void
    {
        $this->state['contracts'][$index] = $contract;
    }

    /**
     * Seed of the contract generator, created on first use.
     */
    public function marketSeed(int $now): string
    {
        return $this->state['market_seed'] ??= substr(hash('sha256', "market:{$now}"), 0, 16);
    }

    public function takeContractNumber(): int
    {
        $this->state['next_contract'] ??= 1;

        return $this->state['next_contract']++;
    }

    /**
     * @param array<string, int> $items
     */
    public function assertBarnHas(array $items): void
    {
        $missing = [];
        foreach ($items as $item => $quantity) {
            if ($this->barnQuantity($item) < $quantity) {
                $missing[$item] = $quantity - $this->barnQuantity($item);
            }
        }
        if ($missing !== []) {
            throw new GameError('not_enough_items', 'Il manque des produits dans la grange.', ['missing' => $missing]);
        }
    }

    // ------------------------------------------------------------ buildings

    /**
     * @return list<array<string, mixed>>
     */
    public function buildings(): array
    {
        return $this->state['buildings'];
    }

    /**
     * @return array<string, mixed>
     */
    public function building(string $id): array
    {
        foreach ($this->state['buildings'] as $building) {
            if ($building['id'] === $id) {
                return $building;
            }
        }

        throw new GameError('unknown_building', 'Bâtiment introuvable.', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $building
     */
    public function saveBuilding(array $building): void
    {
        foreach ($this->state['buildings'] as $index => $existing) {
            if ($existing['id'] === $building['id']) {
                $this->state['buildings'][$index] = $building;

                return;
            }
        }

        throw new GameError('unknown_building', 'Bâtiment introuvable.', ['id' => $building['id']]);
    }

    /**
     * @return array<string, mixed> the new building (outside buildings have no x/y)
     */
    public function addBuilding(string $type, ?int $x = null, ?int $y = null): array
    {
        $building = ['id' => 'b' . $this->state['next_id']++, 'type' => $type];
        if ($x !== null && $y !== null) {
            $building += ['x' => $x, 'y' => $y];
        }
        $building['level'] = 1;
        $this->state['buildings'][] = $building;

        return $building;
    }

    public function removeBuilding(string $id): void
    {
        $this->building($id);
        $this->state['buildings'] = array_values(array_filter(
            $this->state['buildings'],
            static fn (array $building): bool => $building['id'] !== $id,
        ));
    }

    public function countBuildings(string $type): int
    {
        return count(array_filter($this->state['buildings'], static fn (array $b): bool => $b['type'] === $type));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildingsOfKind(string $kind): array
    {
        return array_values(array_filter(
            $this->state['buildings'],
            fn (array $b): bool => $this->data->building($b['type'])['kind'] === $kind,
        ));
    }

    /**
     * Throws unless the footprint of $type at (x, y) lies on owned, free cells.
     */
    public function assertPlaceable(string $type, int $x, int $y, ?string $ignoreId = null): void
    {
        [$width, $height] = $this->data->building($type)['size'];
        $land = new Land($this->data->blockSize());

        $occupied = [];
        foreach ($this->state['buildings'] as $other) {
            if ($other['id'] === $ignoreId || $this->data->isOutside($other['type'])) {
                continue;
            }
            [$w, $h] = $this->data->building($other['type'])['size'];
            for ($dx = 0; $dx < $w; $dx++) {
                for ($dy = 0; $dy < $h; $dy++) {
                    $occupied[($other['x'] + $dx) . ',' . ($other['y'] + $dy)] = true;
                }
            }
        }

        for ($dx = 0; $dx < $width; $dx++) {
            for ($dy = 0; $dy < $height; $dy++) {
                $cx = $x + $dx;
                $cy = $y + $dy;
                if (!$land->isCellOwned($cx, $cy, $this->expansions())) {
                    throw new GameError('cell_locked', 'Ce terrain ne vous appartient pas encore.', ['x' => $cx, 'y' => $cy]);
                }
                if (isset($occupied["{$cx},{$cy}"])) {
                    throw new GameError('cell_occupied', 'Emplacement déjà occupé.', ['x' => $cx, 'y' => $cy]);
                }
            }
        }
    }
}
