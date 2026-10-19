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

    /** Bound for unlock levels: player.max_level, or no real bound when there is none. */
    private int $maxLevel = 100_000;

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
        $this->checkHappiness();
        $this->checkCatalog('currencies');
        $this->checkCatalog('materials');
        $this->checkItems();
        $this->checkCrops();
        $this->checkBuildings();
        $this->checkLand();
        $this->checkMarket();
        $this->checkInitialState();
        if (isset($this->data['cafe'])) {
            $this->checkCafe();
        }
        if (isset($this->data['library'])) {
            $this->checkLibrary();
        }
        if (isset($this->data['market_upgrades'])) {
            $this->checkMarketUpgrades();
        }
        if (isset($this->data['boosts'])) {
            $this->checkBoosts();
        }
        if (isset($this->data['show_animals'])) {
            $this->checkShowAnimals();
        }
        if (isset($this->data['bots'])) {
            $this->checkBots();
        }
        if (isset($this->data['speed_up'])) {
            $this->positiveNumber($this->data['speed_up']['gold_per_minute'] ?? null, 'speed_up.gold_per_minute');
        }
        if (isset($this->data['tournament'])) {
            $this->checkTournamentRewards();
        }

        return $this->errors;
    }

    private function checkPlayer(): void
    {
        $player = $this->data['player'];
        if (($player['max_level'] ?? null) !== null && $this->positiveInt($player['max_level'], 'player.max_level')) {
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

    private function checkHappiness(): void
    {
        $curve = $this->data['happiness']['cost_curve'] ?? null;
        if (!is_array($curve) || count($curve) < 2) {
            $this->errors[] = 'happiness.cost_curve: needs at least two [happiness, factor] points';

            return;
        }
        $previous = null;
        foreach ($curve as $i => $point) {
            if (!is_array($point) || count($point) !== 2 || !is_int($point[0]) || !(is_int($point[1]) || is_float($point[1])) || $point[1] < 0) {
                $this->errors[] = "happiness.cost_curve[{$i}]: must be [integer happiness, factor >= 0]";
                continue;
            }
            if ($previous !== null && $point[0] <= $previous) {
                $this->errors[] = "happiness.cost_curve[{$i}]: happiness values must increase";
            }
            $previous = $point[0];
        }
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
            if (!in_array($item['category'] ?? null, ['crop', 'goods', 'byproduct', 'special'], true)) {
                $this->errors[] = "{$path}.category: must be crop, goods, byproduct or special";
            }
            if (array_key_exists('sellable', $item) && !is_bool($item['sellable'])) {
                $this->errors[] = "{$path}.sellable: must be true or false";
            }
            $this->level($item['unlock_level'] ?? null, "{$path}.unlock_level");
            $this->nonNegativeNumber($item['barn_price'] ?? null, "{$path}.barn_price");
            $this->nonNegativeNumber($item['market_price'] ?? null, "{$path}.market_price");
            if (array_key_exists('barn_limit', $item) && !is_bool($item['barn_limit'])) {
                $this->errors[] = "{$path}.barn_limit: must be true or false";
            }
            if (!is_bool($item['marketable'] ?? null)) {
                $this->errors[] = "{$path}.marketable: must be true or false";
            }
            if (isset($item['contract_quantity'])) {
                $this->range($item['contract_quantity'], "{$path}.contract_quantity");
            }
            if (isset($item['contract_bonus'])) {
                foreach ((array) ($item['contract_bonus']['materials'] ?? []) as $material) {
                    if (!isset($this->data['materials'][$material])) {
                        $this->errors[] = "{$path}.contract_bonus.materials: unknown material " . json_encode($material);
                    }
                }
                $this->range($item['contract_bonus']['quantity'] ?? null, "{$path}.contract_bonus.quantity");
            }
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
            $fieldLevels = count($this->data['buildings']['field']['levels'] ?? []);
            foreach (['yields' => 1, 'flower_nectar' => 0] as $key => $min) {
                $list = $crop[$key] ?? null;
                if (!is_array($list) || !array_is_list($list) || count($list) !== $fieldLevels) {
                    $this->errors[] = "{$path}.{$key}: must list one value per field level ({$fieldLevels})";
                    continue;
                }
                foreach ($list as $i => $value) {
                    if (!is_int($value) || $value < $min) {
                        $this->errors[] = "{$path}.{$key}[{$i}]: must be an integer >= {$min}";
                    }
                }
            }
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

            $zone = $building['zone'] ?? 'farm';
            if (!in_array($zone, ['farm', 'outside'], true)) {
                $this->errors[] = "{$path}.zone: must be farm or outside";
            }
            $size = $building['size'] ?? null;
            if ($zone === 'farm' && (!is_array($size) || !array_is_list($size) || count($size) !== 2
                || !is_int($size[0]) || !is_int($size[1]) || $size[0] < 1 || $size[1] < 1)) {
                $this->errors[] = "{$path}.size: must be [width, height] positive integers";
            }
            if (($building['max_count'] ?? null) !== null) { // null: unlimited (fields, animals, orchards)
                $this->positiveInt($building['max_count'], "{$path}.max_count");
            } elseif (!array_key_exists('max_count', $building)) {
                $this->errors[] = "{$path}.max_count: must be a positive integer or null (unlimited)";
            }
            if (array_key_exists('yield_factor', $building)) {
                $this->positiveNumber($building['yield_factor'], "{$path}.yield_factor");
            }
            if (isset($building['variant_of']) && !isset($this->data['buildings'][$building['variant_of']])) {
                $this->errors[] = "{$path}.variant_of: unknown building";
            }

            $levels = $building['levels'] ?? null;
            if (!is_array($levels) || !array_is_list($levels) || $levels === []) {
                $this->errors[] = "{$path}.levels: must be a non-empty list";
                continue;
            }

            $mode = null;
            if ($kind === 'production') {
                $mode = $building['production']['mode'] ?? null;
                if (!in_array($mode, ['run', 'batch', 'instant'], true)) {
                    $this->errors[] = "{$path}.production.mode: must be run, batch or instant";
                }
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
                foreach (['workers', 'happiness'] as $key) {
                    if (array_key_exists($key, $level) && !is_int($level[$key])) {
                        $this->errors[] = "{$levelPath}.{$key}: must be an integer";
                    }
                }
                if ($kind === 'production') {
                    $this->recipes($level, $mode, $levelPath);
                }
            }
        }
    }

    /**
     * Level recipes, keyed by their main output: { inputs, cost, outputs, duration, xp }.
     * Batch levels also give max_output (most units of a main output per job).
     *
     * @param array<string, mixed> $level
     */
    private function recipes(array $level, ?string $mode, string $levelPath): void
    {
        $recipes = $level['recipes'] ?? null;
        if (!is_array($recipes) || $recipes === [] || array_is_list($recipes)) {
            $this->errors[] = "{$levelPath}.recipes: must be a non-empty object {main output: recipe}";

            return;
        }
        if ($mode === 'batch') {
            if (isset($level['max_batches'])) {
                $this->positiveInt($level['max_batches'], "{$levelPath}.max_batches");
            } else {
                $this->positiveInt($level['max_output'] ?? null, "{$levelPath}.max_output");
            }
        }

        foreach ($recipes as $main => $recipe) {
            $path = "{$levelPath}.recipes.{$main}";
            if (!is_array($recipe)) {
                $this->errors[] = "{$path}: must be an object";
                continue;
            }
            // Empty inputs are allowed (nothing consumed).
            $this->itemQuantities($recipe['inputs'] ?? null, "{$path}.inputs", allowEmpty: true);
            $this->cost($recipe['cost'] ?? null, "{$path}.cost");
            $this->itemQuantities($recipe['outputs'] ?? null, "{$path}.outputs");
            if ($mode === 'instant') {
                $this->nonNegativeInt($recipe['duration'] ?? null, "{$path}.duration");
            } else {
                $this->positiveInt($recipe['duration'] ?? null, "{$path}.duration");
            }
            $this->nonNegativeNumber($recipe['xp'] ?? null, "{$path}.xp");

            $mainQuantity = $recipe['outputs'][$main] ?? null;
            if (!is_int($mainQuantity)) {
                $this->errors[] = "{$path}.outputs: must contain the main output {$main}";
            } elseif ($mode === 'batch' && is_int($level['max_output'] ?? null) && $level['max_output'] < $mainQuantity) {
                $this->errors[] = "{$path}: max_output is smaller than one batch";
            }
        }
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

        $this->nonNegativeNumber($market['quantity_growth'] ?? 0, 'market.quantity_growth');
        $reward = $market['material_reward'] ?? null;
        if (!is_array($reward) || !is_array($reward['materials'] ?? null) || $reward['materials'] === []) {
            $this->errors[] = 'market.material_reward: needs materials {material: factor}';

            return;
        }
        $this->positiveNumber($reward['per_cash'] ?? null, 'market.material_reward.per_cash');
        foreach ($reward['materials'] as $material => $factor) {
            if (!isset($this->data['materials'][$material])) {
                $this->errors[] = "market.material_reward.materials: unknown material {$material}";
            }
            $this->positiveNumber($factor, "market.material_reward.materials.{$material}");
        }

        if (isset($market['market_xp'])) {
            foreach (['factor', 'exponent', 'level_step'] as $key) {
                $this->positiveNumber($market['market_xp'][$key] ?? null, "market.market_xp.{$key}");
            }
        }
    }

    private function checkCafe(): void
    {
        $cafe = $this->data['cafe'];
        $building = $this->data['buildings'][$cafe['building'] ?? ''] ?? null;
        if ($building === null) {
            $this->errors[] = 'cafe.building: unknown building';

            return;
        }
        $maxLevel = count($building['levels'] ?? []);

        $previous = 0;
        foreach ($cafe['star_thresholds'] ?? [] as $i => $threshold) {
            if (!is_int($threshold) || $threshold <= $previous) {
                $this->errors[] = "cafe.star_thresholds[{$i}]: must be increasing positive integers";
            }
            $previous = is_int($threshold) ? $threshold : $previous;
        }
        foreach (['star_cash_bonus', 'star_points_factor', 'favourite_factor', 'xp_per_point'] as $key) {
            $this->nonNegativeNumber($cafe[$key] ?? null, "cafe.{$key}");
        }

        foreach ($this->entries($cafe['recipes'] ?? []) as $id => $recipe) {
            $path = "cafe.recipes.{$id}";
            $this->nameAndEmoji($recipe, $path);
            $this->itemQuantities($recipe['inputs'] ?? null, "{$path}.inputs");
            $this->positiveInt($recipe['cash'] ?? null, "{$path}.cash");
            $this->positiveInt($recipe['points'] ?? null, "{$path}.points");
            $level = $recipe['cafe_level'] ?? null;
            if (!is_int($level) || $level < 1 || $level > $maxLevel) {
                $this->errors[] = "{$path}.cafe_level: must be between 1 and {$maxLevel}";
            }
        }
        foreach ($cafe['groups'] ?? [] as $i => $group) {
            $this->nameAndEmoji($group, "cafe.groups[{$i}]");
            foreach ($group['favourites'] ?? [] as $favourite) {
                if (!isset($cafe['recipes'][$favourite])) {
                    $this->errors[] = "cafe.groups[{$i}].favourites: unknown recipe {$favourite}";
                }
            }
        }
        if (($cafe['groups'] ?? []) === []) {
            $this->errors[] = 'cafe.groups: needs at least one group';
        }
        $previous = 0;
        foreach ($cafe['milestones'] ?? [] as $i => $milestone) {
            $points = $milestone['points'] ?? null;
            if (!is_int($points) || $points <= $previous) {
                $this->errors[] = "cafe.milestones[{$i}].points: must be increasing";
            }
            $previous = is_int($points) ? $points : $previous;
            $this->cost($milestone['reward'] ?? null, "cafe.milestones[{$i}].reward");
        }
    }

    /**
     * Bots: count, profiles whose shares add up to 1, names, tuning numbers.
     */
    private function checkBots(): void
    {
        $bots = $this->data['bots'];
        $this->positiveInt($bots['count'] ?? null, 'bots.count');
        $shares = 0.0;
        foreach ((array) ($bots['profiles'] ?? []) as $i => $profile) {
            $activity = $profile['activity'] ?? null;
            if (!is_int($activity) && !is_float($activity) || $activity <= 0 || $activity > 1) {
                $this->errors[] = "bots.profiles[{$i}].activity: must be in ]0, 1]";
            }
            $this->positiveNumber($profile['share'] ?? null, "bots.profiles[{$i}].share");
            $shares += (float) ($profile['share'] ?? 0);
            if (!is_string($profile['title'] ?? null)) {
                $this->errors[] = "bots.profiles[{$i}].title: must be a string";
            }
        }
        if (abs($shares - 1.0) > 0.001) {
            $this->errors[] = 'bots.profiles: shares must add up to 1';
        }
        if (!is_array($bots['names'] ?? null) || $bots['names'] === [] || count(array_unique($bots['names'])) !== count($bots['names'])) {
            $this->errors[] = 'bots.names: must be a non-empty list of distinct names';
        }
        foreach (['land_use', 'xp_value', 'contracts_per_day', 'default_speed'] as $key) {
            $this->positiveNumber($bots[$key] ?? null, "bots.{$key}");
        }
        foreach (['purchases_per_day', 'history_weeks', 'max_catch_up_days'] as $key) {
            $this->positiveInt($bots[$key] ?? null, "bots.{$key}");
        }
        if (($bots['max_runs_per_day'] ?? null) !== null) {
            $this->positiveNumber($bots['max_runs_per_day'], 'bots.max_runs_per_day');
        }
    }

    /**
     * Each show animal works for one of its species' durations.
     */
    private function checkShowAnimals(): void
    {
        $config = $this->data['show_animals'];
        foreach ((array) ($config['animals'] ?? []) as $id => $animal) {
            $durations = $config['species'][$animal['species'] ?? '']['durations'] ?? null;
            if ($durations === null) {
                $this->errors[] = "show_animals.animals.{$id}.species: unknown species";
            } elseif (!is_int($animal['duration'] ?? null) || !isset($durations[$animal['duration']])) {
                $this->errors[] = "show_animals.animals.{$id}.duration: must be an index of the species' durations";
            }
        }
    }

    private function checkBoosts(): void
    {
        $boosts = $this->data['boosts'];
        $item = $this->itemRef(...);
        foreach (['feed', 'fertilizer', 'humus'] as $kind) {
            foreach (['premium', 'instant'] as $variant) {
                $item($boosts[$kind][$variant] ?? null, "boosts.{$kind}.{$variant}");
            }
        }
        foreach (['feed', 'fertilizer'] as $kind) {
            foreach ((array) ($boosts[$kind]['inputs'] ?? []) as $i => $input) {
                $item($input, "boosts.{$kind}.inputs[{$i}]");
            }
        }
        $this->positiveNumber($boosts['premium_factor'] ?? null, 'boosts.premium_factor');
        $this->positiveNumber($boosts['premium_seed_factor'] ?? null, 'boosts.premium_seed_factor');
        foreach ((array) ($boosts['premium_seeds'] ?? []) as $crop => $seed) {
            if (!isset($this->data['crops'][$crop])) {
                $this->errors[] = "boosts.premium_seeds.{$crop}: unknown crop";
            }
            $item($seed, "boosts.premium_seeds.{$crop}");
        }
        foreach ($this->entries($this->data['special_crops'] ?? []) as $id => $crop) {
            $this->nameAndEmoji($crop, "special_crops.{$id}");
            $item($crop['seed'] ?? null, "special_crops.{$id}.seed");
            if (!($crop['instant'] ?? false)) {
                $this->positiveInt($crop['duration'] ?? null, "special_crops.{$id}.duration");
            }
        }
    }

    private function checkTournamentRewards(): void
    {
        $tournament = $this->data['tournament'];
        $rewards = $tournament['objective_rewards'] ?? [];
        if (count($rewards) !== count($tournament['objectives']['fractions'] ?? [])) {
            $this->errors[] = 'tournament.objective_rewards: one list of options per objective';
        }
        foreach ($rewards as $n => $options) {
            foreach ((array) $options as $i => $option) {
                $path = "tournament.objective_rewards[{$n}][{$i}]";
                $id = $option['item'] ?? null;
                $known = $id === 'premium_seed' || isset($this->data['items'][$id]) || isset($this->data['materials'][$id]);
                if (!is_string($id) || !$known) {
                    $this->errors[] = "{$path}.item: unknown item or material " . json_encode($id);
                }
                $this->positiveNumber($option['base'] ?? null, "{$path}.base");
                $this->nonNegativeNumber($option['per_tier'] ?? null, "{$path}.per_tier");
            }
        }
    }

    private function itemRef(mixed $id, string $path): void
    {
        if (!is_string($id) || !isset($this->data['items'][$id])) {
            $this->errors[] = "{$path}: unknown item " . json_encode($id);
        }
    }

    private function checkLibrary(): void
    {
        $library = $this->data['library'];
        if (!isset($this->data['buildings'][$library['building'] ?? ''])) {
            $this->errors[] = 'library.building: unknown building';
        }
        foreach ($this->entries($library['items'] ?? []) as $item => $entry) {
            if (!isset($this->data['items'][$item])) {
                $this->errors[] = "library.items.{$item}: unknown item";
            }
            $this->upgradeLevels($entry['levels'] ?? null, "library.items.{$item}");
        }
    }

    private function checkMarketUpgrades(): void
    {
        foreach ($this->entries($this->data['market_upgrades']['tracks'] ?? []) as $id => $track) {
            $path = "market_upgrades.tracks.{$id}";
            $this->nameAndEmoji($track, $path);
            $effect = $track['effect'] ?? [];
            if (!in_array($effect['type'] ?? null, ['slots', 'cash', 'xp', 'market_xp', 'material'], true)) {
                $this->errors[] = "{$path}.effect.type: must be slots, cash, xp, market_xp or material";
            } elseif ($effect['type'] === 'material' && !isset($this->data['materials'][$effect['material'] ?? ''])) {
                $this->errors[] = "{$path}.effect.material: unknown material";
            }
            $this->upgradeLevels($track['levels'] ?? null, $path);
        }
    }

    /**
     * Levels 1..n, each with a cost and a total bonus that never goes down.
     */
    private function upgradeLevels(mixed $levels, string $path): void
    {
        if (!is_array($levels) || $levels === [] || !array_is_list($levels)) {
            $this->errors[] = "{$path}.levels: must be a non-empty list";

            return;
        }
        $previous = 0;
        foreach ($levels as $i => $level) {
            if (($level['level'] ?? null) !== $i + 1) {
                $this->errors[] = "{$path}.levels[{$i}].level: must be " . ($i + 1);
            }
            $this->cost($level['cost'] ?? null, "{$path}.levels[{$i}].cost");
            $bonus = $level['bonus'] ?? null;
            if (!is_int($bonus) || $bonus < $previous || $bonus < 1) {
                $this->errors[] = "{$path}.levels[{$i}].bonus: must be a positive integer, never lower than the level before";
            } else {
                $previous = $bonus;
            }
        }
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

        $buildings = $state['buildings'] ?? null;
        if (!is_array($buildings) || !array_is_list($buildings)) {
            $this->errors[] = 'initial_state.buildings: must be a list';

            return;
        }
        foreach ($buildings as $index => $building) {
            $path = "initial_state.buildings[{$index}]";
            $definition = $this->data['buildings'][$building['type'] ?? null] ?? null;
            if ($definition === null) {
                $this->errors[] = "{$path}.type: unknown building";
            } elseif (($definition['zone'] ?? 'farm') !== 'farm') {
                $this->errors[] = "{$path}.type: only farm-zone buildings can be placed at start";
            }
            $this->nonNegativeInt($building['x'] ?? null, "{$path}.x");
            $this->nonNegativeInt($building['y'] ?? null, "{$path}.y");
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

    private function itemQuantities(mixed $quantities, string $path, bool $allowEmpty = false): void
    {
        if (is_array($quantities) && $quantities === [] && $allowEmpty) {
            return;
        }
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
