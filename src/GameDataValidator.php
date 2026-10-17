<?php

declare(strict_types=1);

namespace FermeTranquille;

/**
 * Structural and referential checks for game-data.json, so that a hand edit
 * fails loudly at load time instead of deep inside the engine.
 */
final class GameDataValidator
{
    /** @var list<string> */
    private array $errors = [];

    private int $maxLevel = 1;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $buildingKinds
     */
    public function __construct(
        private readonly array $data,
        private readonly array $buildingKinds,
    ) {
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        $this->errors = [];

        foreach (['currencies', 'materials', 'player', 'initial_state', 'land', 'market', 'items', 'crops', 'buildings'] as $key) {
            if (!is_array($this->data[$key] ?? null)) {
                $this->errors[] = "missing section: {$key}";
            }
        }
        if ($this->errors !== []) {
            return $this->errors;
        }

        $this->checkPlayer();
        $this->checkCatalog('currencies');
        $this->checkCatalog('materials');
        $this->checkItems();
        $this->checkCrops();
        $this->checkBuildings();
        $this->checkLand();
        $this->checkMarket();
        $this->checkInitialState();

        return $this->errors;
    }

    private function checkPlayer(): void
    {
        $player = $this->data['player'];
        if ($this->positiveInt($player['max_level'] ?? null, 'player.max_level')) {
            $this->maxLevel = $player['max_level'];
        }
        $curve = $player['xp_curve'] ?? null;
        if (!is_array($curve)) {
            $this->errors[] = 'player.xp_curve: missing';

            return;
        }
        $this->positiveNumber($curve['base'] ?? null, 'player.xp_curve.base');
        $this->positiveNumber($curve['exponent'] ?? null, 'player.xp_curve.exponent');
    }

    private function checkCatalog(string $section): void
    {
        foreach ($this->entries($this->data[$section]) as $id => $entry) {
            $this->nameAndEmoji($entry, "{$section}.{$id}");
        }
    }

    private function checkItems(): void
    {
        foreach ($this->entries($this->data['items']) as $id => $item) {
            $path = "items.{$id}";
            $this->nameAndEmoji($item, $path);
            if (!in_array($item['category'] ?? null, ['crop', 'goods', 'byproduct'], true)) {
                $this->errors[] = "{$path}.category: must be crop, goods or byproduct";
            }
            $this->level($item['unlock_level'] ?? null, "{$path}.unlock_level");
            $this->nonNegativeNumber($item['barn_price'] ?? null, "{$path}.barn_price");
            $this->nonNegativeNumber($item['market_price'] ?? null, "{$path}.market_price");
        }
    }

    private function checkCrops(): void
    {
        foreach ($this->entries($this->data['crops']) as $id => $crop) {
            $path = "crops.{$id}";
            if (($this->data['items'][$id]['category'] ?? null) !== 'crop') {
                $this->errors[] = "{$path}: needs a matching item with category crop";
            }
            $this->cost($crop['plant_cost'] ?? null, "{$path}.plant_cost");
            $this->positiveInt($crop['duration'] ?? null, "{$path}.duration");
            $this->positiveInt($crop['yield'] ?? null, "{$path}.yield");
            $this->nonNegativeNumber($crop['xp'] ?? null, "{$path}.xp");
        }
        foreach ($this->entries($this->data['items']) as $id => $item) {
            if (($item['category'] ?? null) === 'crop' && !isset($this->data['crops'][$id])) {
                $this->errors[] = "items.{$id}: crop item without crops.{$id}";
            }
        }
    }

    private function checkBuildings(): void
    {
        foreach ($this->entries($this->data['buildings']) as $type => $building) {
            $path = "buildings.{$type}";
            $this->nameAndEmoji($building, $path);
            $kind = $building['kind'] ?? null;
            if (!in_array($kind, $this->buildingKinds, true)) {
                $this->errors[] = "{$path}.kind: must be one of " . implode(', ', $this->buildingKinds);
            }

            $size = $building['size'] ?? null;
            if (!is_array($size) || !array_is_list($size) || count($size) !== 2
                || !is_int($size[0]) || !is_int($size[1]) || $size[0] < 1 || $size[1] < 1) {
                $this->errors[] = "{$path}.size: must be [width, height] positive integers";
            }
            $this->positiveInt($building['max_count'] ?? null, "{$path}.max_count");

            $levels = $building['levels'] ?? null;
            if (!is_array($levels) || !array_is_list($levels) || $levels === []) {
                $this->errors[] = "{$path}.levels: must be a non-empty list";
                continue;
            }

            $production = null;
            if ($kind === 'production') {
                $production = $this->production($building['production'] ?? null, "{$path}.production");
            }

            foreach ($levels as $index => $level) {
                $levelPath = "{$path}.levels[{$index}]";
                if (($level['level'] ?? null) !== $index + 1) {
                    $this->errors[] = "{$levelPath}.level: expected " . ($index + 1);
                }
                $this->level($level['unlock_level'] ?? 1, "{$levelPath}.unlock_level");
                $this->cost($level['cost'] ?? null, "{$levelPath}.cost");

                if ($kind === 'storage') {
                    $this->positiveInt($level['capacity'] ?? null, "{$levelPath}.capacity");
                }
                if ($kind === 'production') {
                    $outputs = $level['outputs'] ?? null;
                    $this->itemQuantities($outputs, "{$levelPath}.outputs");
                    if ($production !== null && !isset($outputs[$production])) {
                        $this->errors[] = "{$levelPath}.outputs: missing main output {$production}";
                    }
                }
            }
        }
    }

    /**
     * @return string|null the main output id when valid
     */
    private function production(mixed $production, string $path): ?string
    {
        if (!is_array($production)) {
            $this->errors[] = "{$path}: missing";

            return null;
        }
        $this->itemQuantities($production['inputs_per_main_unit'] ?? null, "{$path}.inputs_per_main_unit");
        $this->positiveInt($production['duration'] ?? null, "{$path}.duration");
        $this->nonNegativeNumber($production['xp'] ?? null, "{$path}.xp");

        $main = $production['main_output'] ?? null;
        if (!is_string($main) || !isset($this->data['items'][$main])) {
            $this->errors[] = "{$path}.main_output: unknown item";

            return null;
        }

        return $main;
    }

    private function checkLand(): void
    {
        $land = $this->data['land'];
        $this->positiveInt($land['block_size'] ?? null, 'land.block_size');
        if (!$this->positiveInt($land['initial_expansions'] ?? null, 'land.initial_expansions')) {
            return;
        }

        $expansions = $land['expansions'] ?? null;
        if (!is_array($expansions) || !array_is_list($expansions)) {
            $this->errors[] = 'land.expansions: must be a list';

            return;
        }

        foreach ($expansions as $index => $expansion) {
            $expected = $land['initial_expansions'] + $index + 1;
            $path = "land.expansions[{$index}]";
            if (($expansion['number'] ?? null) !== $expected) {
                $this->errors[] = "{$path}.number: expected {$expected}";
            }
            $this->level($expansion['unlock_level'] ?? null, "{$path}.unlock_level");
            $costs = $expansion['costs'] ?? null;
            if (!is_array($costs) || !array_is_list($costs) || $costs === []) {
                $this->errors[] = "{$path}.costs: must be a non-empty list of alternative costs";
                continue;
            }
            foreach ($costs as $i => $cost) {
                $this->cost($cost, "{$path}.costs[{$i}]");
            }
        }
    }

    private function checkMarket(): void
    {
        $market = $this->data['market'];
        $this->positiveInt($market['slots'] ?? null, 'market.slots');
        $this->range($market['products_per_contract'] ?? null, 'market.products_per_contract');
        $this->range($market['quantity_per_product'] ?? null, 'market.quantity_per_product');
        $this->positiveInt($market['replace_delay'] ?? null, 'market.replace_delay');
        $this->nonNegativeNumber($market['xp_per_cash'] ?? null, 'market.xp_per_cash');

        $reward = $market['material_reward'] ?? null;
        if (!is_array($reward)) {
            $this->errors[] = 'market.material_reward: missing';

            return;
        }
        $chance = $reward['chance'] ?? null;
        if (!is_int($chance) && !is_float($chance) || $chance < 0 || $chance > 1) {
            $this->errors[] = 'market.material_reward.chance: must be between 0 and 1';
        }
        foreach ((array) ($reward['materials'] ?? []) as $material) {
            if (!is_string($material) || !isset($this->data['materials'][$material])) {
                $this->errors[] = 'market.material_reward.materials: unknown material ' . json_encode($material);
            }
        }
        $this->range($reward['quantity'] ?? null, 'market.material_reward.quantity');
    }

    private function checkInitialState(): void
    {
        $state = $this->data['initial_state'];
        $this->level($state['level'] ?? null, 'initial_state.level');
        foreach (['xp', 'cash', 'gold'] as $key) {
            $this->nonNegativeInt($state[$key] ?? null, "initial_state.{$key}");
        }
        if (($state['expansions'] ?? null) !== ($this->data['land']['initial_expansions'] ?? null)) {
            $this->errors[] = 'initial_state.expansions: must equal land.initial_expansions';
        }
    }

    // ------------------------------------------------------------ helpers

    /**
     * Entries of an id => object map, ignoring "_" metadata keys.
     *
     * @param array<string, mixed> $map
     * @return iterable<string, array<string, mixed>>
     */
    private function entries(array $map): iterable
    {
        foreach ($map as $id => $entry) {
            if (str_starts_with((string) $id, '_')) {
                continue;
            }
            if (!is_array($entry)) {
                $this->errors[] = "{$id}: must be an object";
                continue;
            }
            yield (string) $id => $entry;
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function nameAndEmoji(array $entry, string $path): void
    {
        foreach (['name', 'emoji'] as $key) {
            if (!is_string($entry[$key] ?? null) || $entry[$key] === '') {
                $this->errors[] = "{$path}.{$key}: must be a non-empty string";
            }
        }
    }

    private function cost(mixed $cost, string $path): void
    {
        if (!is_array($cost) || array_is_list($cost) && $cost !== []) {
            $this->errors[] = "{$path}: must be an object {resource: quantity}";

            return;
        }
        foreach ($cost as $resource => $quantity) {
            $isResource = isset($this->data['currencies'][$resource]) || isset($this->data['materials'][$resource]);
            if (!$isResource) {
                $this->errors[] = "{$path}: unknown resource {$resource}";
            }
            $this->nonNegativeInt($quantity, "{$path}.{$resource}");
        }
    }

    private function itemQuantities(mixed $quantities, string $path): void
    {
        if (!is_array($quantities) || $quantities === [] || array_is_list($quantities)) {
            $this->errors[] = "{$path}: must be a non-empty object {item: quantity}";

            return;
        }
        foreach ($quantities as $item => $quantity) {
            if (!isset($this->data['items'][$item])) {
                $this->errors[] = "{$path}: unknown item {$item}";
            }
            $this->positiveNumber($quantity, "{$path}.{$item}");
        }
    }

    private function range(mixed $range, string $path): void
    {
        if (!is_array($range) || !array_is_list($range) || count($range) !== 2
            || !is_int($range[0]) || !is_int($range[1]) || $range[0] < 1 || $range[0] > $range[1]) {
            $this->errors[] = "{$path}: must be [min, max] with 1 <= min <= max";
        }
    }

    private function level(mixed $value, string $path): void
    {
        if (!is_int($value) || $value < 1 || $value > $this->maxLevel) {
            $this->errors[] = "{$path}: must be an integer between 1 and {$this->maxLevel}";
        }
    }

    private function positiveInt(mixed $value, string $path): bool
    {
        if (!is_int($value) || $value < 1) {
            $this->errors[] = "{$path}: must be a positive integer";

            return false;
        }

        return true;
    }

    private function nonNegativeInt(mixed $value, string $path): void
    {
        if (!is_int($value) || $value < 0) {
            $this->errors[] = "{$path}: must be an integer >= 0";
        }
    }

    private function positiveNumber(mixed $value, string $path): void
    {
        if (!is_int($value) && !is_float($value) || $value <= 0) {
            $this->errors[] = "{$path}: must be a number > 0";
        }
    }

    private function nonNegativeNumber(mixed $value, string $path): void
    {
        if (!is_int($value) && !is_float($value) || $value < 0) {
            $this->errors[] = "{$path}: must be a number >= 0";
        }
    }
}
