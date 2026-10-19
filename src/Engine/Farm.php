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
     * Back to a copy taken earlier (clone $farm): rolls back one refused step of a batch.
     */
    public function restore(self $snapshot): void
    {
        $this->state = $snapshot->state;
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
        $farm->ensureStarterBuildings();

        return $farm;
    }

    /**
     * Farmhouse and fence exist on every farm (added to older farms too).
     * Their id is their type, so building ids b1, b2... are unchanged.
     * Farm-zone starters (farmhouse) go to start_position, or the first free
     * owned spot; older farms get their outside farmhouse moved onto the grid.
     */
    public function ensureStarterBuildings(): void
    {
        // Buildings that moved outside the farm (sewing workshop) leave the grid.
        foreach ($this->state['buildings'] as $index => $building) {
            if (isset($building['x']) && $this->data->isOutside($building['type'])) {
                unset($building['x'], $building['y'], $building['rotated']);
                $this->state['buildings'][$index] = $building;
            }
        }

        foreach ($this->data->starterBuildings() as $type) {
            $outside = $this->data->isOutside($type);
            $existing = null;
            foreach ($this->state['buildings'] as $index => $building) {
                if ($building['type'] === $type) {
                    $existing = $index;
                }
            }
            if ($existing !== null && ($outside || isset($this->state['buildings'][$existing]['x']))) {
                continue;
            }

            $building = $existing !== null ? $this->state['buildings'][$existing] : ['id' => $type, 'type' => $type, 'level' => 1];
            if (!$outside) {
                $spot = $this->freeSpot($type, $building['id']);
                if ($spot === null) {
                    continue; // no room: tried again on the next resolve
                }
                $building = ['id' => $building['id'], 'type' => $type, 'x' => $spot[0], 'y' => $spot[1], 'level' => $building['level']];
            }
            if ($existing !== null) {
                $this->state['buildings'][$existing] = $building;
            } else {
                $this->state['buildings'][] = $building;
            }
        }
    }

    /**
     * @return array{int, int}|null start_position if free, else the first free owned spot
     */
    private function freeSpot(string $type, string $ignoreId): ?array
    {
        $candidates = [];
        if (isset($this->data->building($type)['start_position'])) {
            $candidates[] = $this->data->building($type)['start_position'];
        }
        $side = (new Land($this->data->blockSize()))->gridSide($this->expansions());
        for ($y = 0; $y < $side; $y++) {
            for ($x = 0; $x < $side; $x++) {
                $candidates[] = [$x, $y];
            }
        }
        foreach ($candidates as [$x, $y]) {
            try {
                $this->assertPlaceable($type, $x, $y, $ignoreId);

                return [$x, $y];
            } catch (GameError) {
                continue;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ workers and happiness

    /**
     * @return array{total: int, used: int, free: int}
     */
    public function workers(): array
    {
        $total = 0;
        $used = 0;
        foreach ($this->state['buildings'] as $building) {
            $workers = $this->data->buildingLevel($building['type'], $building['level'])['workers'] ?? 0;
            if ($workers > 0) {
                $total += $workers;
            } else {
                $used -= $workers;
            }
        }

        return ['total' => $total, 'used' => $used, 'free' => $total - $used];
    }

    /**
     * Throws unless $extra more workers are available.
     */
    public function assertWorkers(int $extra): void
    {
        $free = $this->workers()['free'];
        if ($extra > 0 && $free < $extra) {
            throw new GameError('not_enough_workers', 'Pas assez d\'ouvriers : construisez ou améliorez une habitation.', [
                'needed' => $extra,
                'free' => max(0, $free),
            ]);
        }
    }

    /**
     * Sum of the buildings' happiness, never below zero: houses can cancel the
     * decorations' bonus but never make costs higher than normal (x1).
     */
    public function happiness(): int
    {
        $happiness = 0;
        foreach ($this->state['buildings'] as $building) {
            $happiness += $this->data->buildingLevel($building['type'], $building['level'])['happiness'] ?? 0;
        }

        return max(0, $happiness);
    }

    /**
     * Cash of a production cost (planting, animals, factories) after the happiness effect.
     *
     * @param array<string, int> $cost
     * @return array<string, int>
     */
    public function productionCost(array $cost): array
    {
        if (isset($cost['cash'])) {
            $cost['cash'] = (int) round($cost['cash'] * $this->data->costFactor($this->happiness()));
        }

        return $cost;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $state = $this->state;
        // Keep JSON objects as objects even when empty.
        $state['barn']['items'] = (object) $state['barn']['items'];
        if (isset($state['cafe'])) {
            $state['cafe']['served'] = (object) $state['cafe']['served'];
        }
        if (isset($state['show'])) {
            $state['show']['animals'] = (object) ($state['show']['animals'] ?? []);
            $state['show']['active'] = (object) ($state['show']['active'] ?? []);
        }
        foreach (['library', 'market_upgrades'] as $key) {
            if (isset($state[$key])) {
                $state[$key] = (object) $state[$key];
            }
        }

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
    /** XP, with gold_rewards.per_level gold for every level gained. */
    public function addXp(int $amount): void
    {
        $this->state['xp'] += $amount;
        $gained = 0;
        while (($needed = $this->data->xpForLevel($this->state['level'])) > 0 && $this->state['xp'] >= $needed) {
            $this->state['xp'] -= $needed;
            $this->state['level']++;
            $gained++;
        }
        if ($this->data->maxLevel() !== null && $this->state['level'] >= $this->data->maxLevel()) {
            $this->state['xp'] = 0;
        }
        if ($gained > 0) {
            $this->gain(['gold' => $gained * $this->data->goldReward('per_level')]);
        }
    }

    /**
     * gold_rewards.daily_login gold on the first visit of each day.
     */
    public function grantDailyGold(string $today): void
    {
        if (($this->state['login_day'] ?? null) === $today) {
            return;
        }
        $this->state['login_day'] = $today;
        $this->gain(['gold' => $this->data->goldReward('daily_login')]);
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

    public function addExpansion(): void
    {
        $this->state['expansions']++;
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
     * Dung, feeds, fertilizer, humus and leaves are not limited by the barn.
     */
    public function isBarnLimited(string $item): bool
    {
        return $this->data->item($item)['barn_limit'] ?? true;
    }

    /**
     * @return list<string> products stored at full capacity
     */
    public function barnFullItems(): array
    {
        $capacity = $this->barnCapacity();

        return array_keys(array_filter(
            $this->barnItems(),
            fn (int $quantity, string $item): bool => $quantity >= $capacity && $this->isBarnLimited($item),
            ARRAY_FILTER_USE_BOTH,
        ));
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
            if (!$this->isBarnLimited($item)) {
                continue;
            }
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

    /**
     * Stores what fits and sells the rest at barn price. $sell asks to sell
     * more of an incoming product (from the harvest, then from the barn):
     * never less than what does not fit. Products that cannot be sold still
     * make the whole thing refused when they do not fit.
     *
     * @param array<string, int> $items
     * @param array<string, int> $sell
     */
    public function addToBarnSellingSurplus(array $items, array $sell = []): void
    {
        $capacity = $this->barnCapacity();
        $cash = 0;
        $fromBarn = [];
        foreach ($items as $item => $quantity) {
            if (($this->data->item($item)['sellable'] ?? true) === false) {
                continue;
            }
            $surplus = $this->isBarnLimited($item) ? $quantity - max(0, $capacity - $this->barnQuantity($item)) : 0;
            $sold = min(max($surplus, $sell[$item] ?? 0), $quantity + $this->barnQuantity($item));
            if ($sold <= 0) {
                continue;
            }
            $items[$item] -= min($sold, $quantity);
            $fromBarn[$item] = $sold - min($sold, $quantity);
            $cash += (int) floor($sold * $this->data->item($item)['barn_price']);
        }

        foreach (array_filter($fromBarn) as $item => $quantity) {
            $this->removeFromBarn($item, $quantity);
        }
        $this->addToBarn(array_filter($items));
        $this->gain(['cash' => $cash]);
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

    // ------------------------------------------------------------ show animals

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $show = $this->state['show'] ?? [];
        foreach (['animals', 'active'] as $key) {
            if (isset($show[$key])) {
                $show[$key] = json_decode(json_encode($show[$key], JSON_THROW_ON_ERROR), true); // objects once normalized
            }
        }

        return $show;
    }

    /**
     * @param array<string, mixed> $show
     */
    public function setShow(array $show): void
    {
        $this->state['show'] = $show;
    }

    // ------------------------------------------------------------ library, market upgrades

    /**
     * @return array<string, int>
     */
    public function library(): array
    {
        return (array) ($this->state['library'] ?? []); // an object once normalized
    }

    public function setLibraryLevel(string $item, int $level): void
    {
        $this->state['library'] = [$item => $level] + $this->library();
    }

    /**
     * @return array<string, int>
     */
    public function marketUpgrades(): array
    {
        return (array) ($this->state['market_upgrades'] ?? []);
    }

    public function setMarketUpgradeLevel(string $track, int $level): void
    {
        $this->state['market_upgrades'] = [$track => $level] + $this->marketUpgrades();
    }

    /**
     * @return array<string, mixed>
     */
    public function tournament(): array
    {
        return json_decode(json_encode($this->state['tournament'] ?? [], JSON_THROW_ON_ERROR), true); // objects once normalized
    }

    /**
     * @param array<string, mixed> $tournament
     */
    public function setTournament(array $tournament): void
    {
        $this->state['tournament'] = $tournament;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return json_decode(json_encode($this->state['settings'] ?? [], JSON_THROW_ON_ERROR), true);
    }

    /** Total market XP earned by delivering contracts. */
    public function marketXp(): int
    {
        return $this->state['market_xp'] ?? 0;
    }

    public function addMarketXp(int $xp): void
    {
        $this->state['market_xp'] = $this->marketXp() + $xp;
    }

    // ------------------------------------------------------------ café

    /**
     * @return array<string, mixed>
     */
    public function cafe(): array
    {
        $cafe = $this->state['cafe'] ?? [];
        if (isset($cafe['served'])) {
            $cafe['served'] = (array) $cafe['served']; // an object once normalized for JSON
        }

        return $cafe;
    }

    /**
     * @param array<string, mixed> $cafe
     */
    public function setCafe(array $cafe): void
    {
        $this->state['cafe'] = $cafe;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildingsOfType(string $type): array
    {
        return array_values(array_filter($this->state['buildings'], static fn (array $b): bool => $b['type'] === $type));
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
    public function addBuilding(string $type, ?int $x = null, ?int $y = null, bool $rotated = false): array
    {
        $building = ['id' => 'b' . $this->state['next_id']++, 'type' => $type];
        if ($x !== null && $y !== null) {
            $building += ['x' => $x, 'y' => $y];
        }
        if ($rotated) {
            $building['rotated'] = true;
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
    /**
     * Cells covered on the grid: [width, height] of the type, swapped when the
     * building is rotated (a quarter turn, drawn mirrored).
     *
     * @return array{int, int}
     */
    public function footprint(string $type, bool $rotated = false): array
    {
        [$width, $height] = $this->data->building($type)['size'];

        return $rotated ? [$height, $width] : [$width, $height];
    }

    public function assertPlaceable(string $type, int $x, int $y, ?string $ignoreId = null, bool $rotated = false): void
    {
        [$width, $height] = $this->footprint($type, $rotated);
        $land = new Land($this->data->blockSize());

        $occupied = [];
        foreach ($this->state['buildings'] as $other) {
            if ($other['id'] === $ignoreId || $this->data->isOutside($other['type'])) {
                continue;
            }
            [$w, $h] = $this->footprint($other['type'], !empty($other['rotated']));
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
