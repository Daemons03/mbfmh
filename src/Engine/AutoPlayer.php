<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Plays one turn of a real farm (Daemons, Styfller, Herzo): the same actions
 * as a player's taps, through the engine and its rules. Each step picks the
 * next useful action from the current state; a refused one is not tried
 * again this turn. Order: pick up what is ready (selling what the barn
 * cannot hold), deliver contracts, discard the ones it cannot make, show
 * animals, plant free fields (what its contracts and animals need, else the
 * most profitable crop at its pace), start idle buildings (same priorities),
 * sell what crowds the barn, then invest like the simulated bots
 * (BotWorld::purchaseStep).
 */
final class AutoPlayer
{
    /** Time between two turns: crops shorter than this are worth less. */
    public const int TURN = 900;

    private const int MAX_ACTIONS = 300;
    private const int MAX_PURCHASES = 5;

    private readonly BotWorld $rules;

    public function __construct(
        private readonly Engine $engine,
        private readonly GameData $data,
    ) {
        $this->rules = new BotWorld($data);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, actions: list<array{0: string, 1: array<string, mixed>}>}
     */
    public function turn(array $state, int $now): array
    {
        $state = self::plain($this->engine->resolve($state, $now));
        $done = [];
        $refused = [];
        $purchases = 0;
        $discarded = false;
        for ($i = 0; $i < self::MAX_ACTIONS; $i++) {
            $next = $this->nextAction($state, $now, $refused, $purchases < self::MAX_PURCHASES, !$discarded);
            if ($next === null) {
                break;
            }
            [$type, $payload] = $next;
            try {
                $state = self::plain($this->engine->apply($state, $type, $payload, $now));
                $done[] = $next;
                if (in_array($type, ['build', 'upgrade', 'buy_expansion'], true)) {
                    $purchases++;
                }
                $discarded = $discarded || $type === 'discard_contract'; // one a turn
            } catch (GameError) {
                $refused[self::key($type, $payload)] = true;
            }
        }

        return ['state' => $state, 'actions' => $done];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, true> $refused
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function nextAction(array $state, int $now, array $refused, bool $mayBuy, bool $mayDiscard): ?array
    {
        $farm = new Farm($state, $this->data);
        $clock = new Clock($now, $farm->speed());
        $candidates = [
            ...$this->pickUps($farm, $clock),
            ...$this->deliveries($farm, $clock),
            ...($mayDiscard ? $this->discards($farm, $clock) : []),
            ...$this->showAnimals($farm, $clock),
            ...$this->sales($farm),
            ...$this->plantings($farm),
            ...$this->productions($farm),
        ];
        if ($mayBuy) {
            $candidates[] = $this->purchase($farm);
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== null && !isset($refused[self::key(...$candidate)])) {
                return $candidate;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ picking up and selling

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function pickUps(Farm $farm, Clock $clock): array
    {
        $actions = [];
        foreach ($farm->buildings() as $building) {
            if (!isset($building['job']) || !$clock->isReady($building['job'])) {
                continue;
            }
            $kind = $this->data->building($building['type'])['kind'];
            if ($kind === 'field') {
                $actions[] = ['harvest', ['id' => $building['id'], 'sell_surplus' => true]];
            } elseif ($kind === 'production') {
                $actions[] = ['collect', ['id' => $building['id'], 'sell_surplus' => true]];
            }
        }
        foreach ($farm->show()['active'] ?? [] as $species => $job) {
            if ($clock->isReady($job)) {
                $actions[] = ['collect_show_animal', ['species' => $species, 'sell' => []]];
            }
        }

        return $actions;
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function deliveries(Farm $farm, Clock $clock): array
    {
        $actions = [];
        foreach ($farm->contracts() as $contract) {
            if (Market::isWaiting($contract, $clock)) {
                continue;
            }
            $ready = true;
            foreach ($contract['items'] as $item => $quantity) {
                $ready = $ready && $farm->barnQuantity($item) >= $quantity;
            }
            if ($ready) {
                $actions[] = ['fulfill_contract', ['id' => $contract['id']]];
            }
        }

        return $actions;
    }

    /**
     * Contracts asking for something the farm cannot make now (a crop not
     * unlocked, a product of a building it does not have) go. When none can
     * be delivered and at least 4 wait, the one missing the most goes too,
     * so the market never stays blocked.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function discards(Farm $farm, Clock $clock): array
    {
        $makes = array_flip((new Market($this->data))->eligibleItems($farm));
        $actions = [];
        $blocked = [];
        foreach ($farm->contracts() as $contract) {
            if (Market::isWaiting($contract, $clock)) {
                continue;
            }
            if (array_diff_key($contract['items'], $makes) !== []) {
                $actions[] = ['discard_contract', ['id' => $contract['id']]];
                continue;
            }
            $missing = 0.0;
            foreach ($contract['items'] as $item => $quantity) {
                $missing += max(0, $quantity - $farm->barnQuantity($item)) * $this->price($item);
            }
            if ($missing <= 0) {
                return $actions; // one can be delivered: the market moves
            }
            $blocked[$contract['id']] = $missing;
        }
        if (count($blocked) >= 4) {
            arsort($blocked);
            $actions[] = ['discard_contract', ['id' => array_key_first($blocked)]];
        }

        return $actions;
    }

    /**
     * What the contracts ask for and the barn lacks: item => missing quantity.
     *
     * @return array<string, int>
     */
    private function contractNeeds(Farm $farm): array
    {
        $needs = [];
        foreach ($farm->contracts() as $contract) {
            foreach ($contract['items'] as $item => $quantity) {
                $needs[$item] = ($needs[$item] ?? 0) + $quantity;
            }
        }
        foreach ($needs as $item => $quantity) {
            $needs[$item] = $quantity - $farm->barnQuantity($item);
        }

        return array_filter($needs, static fn (int $missing): bool => $missing > 0);
    }

    /**
     * Stock crowding the barn (over three quarters of a product's room) sold
     * down to half, keeping what the contracts and its buildings need.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function sales(Farm $farm): array
    {
        $capacity = $farm->barnCapacity();
        $keep = [];
        foreach ($farm->contracts() as $contract) {
            foreach ($contract['items'] as $item => $quantity) {
                $keep[$item] = ($keep[$item] ?? 0) + $quantity;
            }
        }
        $actions = [];
        foreach ($farm->barnItems() as $item => $quantity) {
            if (!$farm->isBarnLimited($item) || ($this->data->item($item)['sellable'] ?? true) === false || $quantity <= 0.75 * $capacity) {
                continue;
            }
            $sell = $quantity - max((int) ($capacity / 2), $keep[$item] ?? 0);
            if ($sell > 0) {
                $actions[] = ['sell_barn', ['item' => $item, 'quantity' => $sell]];
            }
        }

        return $actions;
    }

    // ------------------------------------------------------------ show animals

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function showAnimals(Farm $farm, Clock $clock): array
    {
        if (!isset($this->data->toArray()['show_animals'])) {
            return [];
        }
        $config = $this->data->toArray()['show_animals'];
        $show = $farm->show();
        $actions = [];
        $tokens = $farm->resource('tokens');
        if ($tokens >= $config['chest']['bulk_cost']) {
            $actions[] = ['open_chest', ['count' => $config['chest']['bulk_count']]];
        } elseif ($tokens >= $config['chest']['cost']) {
            $actions[] = ['open_chest', ['count' => 1]];
        }

        $rules = new ShowAnimals($this->data);
        if (count($show['active'] ?? []) < $rules->slots($farm)) {
            // Free species: its animal making the most per hour.
            $best = [];
            foreach ($show['animals'] ?? [] as $id => $owned) {
                $animal = $config['animals'][$id];
                if (isset($show['active'][$animal['species']])) {
                    continue;
                }
                $duration = $config['species'][$animal['species']]['durations'][$animal['duration']];
                $perHour = array_sum($rules->outputs($id, $owned['level'])) / max(1, $duration);
                if ($perHour > ($best[$animal['species']][1] ?? -1)) {
                    $best[$animal['species']] = [$id, $perHour];
                }
            }
            uasort($best, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
            foreach ($best as [$id]) {
                $actions[] = ['start_show_animal', ['animal' => $id]];
            }
        }

        return $actions;
    }

    // ------------------------------------------------------------ fields

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function plantings(Farm $farm): array
    {
        $crop = null;
        $actions = [];
        foreach ($farm->buildingsOfKind('field') as $field) {
            if (isset($field['job'])) {
                continue;
            }
            $crop ??= $this->cropToPlant($farm, $field['level']);
            if ($crop !== null) {
                $actions[] = ['plant', ['id' => $field['id'], 'crop' => $crop]];
            }
        }

        return $actions;
    }

    /**
     * A crop its contracts ask for, else one its animals need (their feed is
     * running low), else the most profitable at a turn's pace.
     */
    private function cropToPlant(Farm $farm, int $fieldLevel): ?string
    {
        $items = $this->data->toArray()['items'];
        $crops = array_filter(
            array_keys($this->data->toArray()['crops']),
            fn (string $c): bool => $items[$c]['unlock_level'] <= $farm->level() && !$this->full($farm, $c)
                && $farm->canAfford($farm->productionCost($this->data->crop($c)['plant_cost'])),
        );
        foreach ([...array_keys($this->contractNeeds($farm)), ...array_keys($this->neededInputs($farm))] as $item) {
            if (in_array($item, $crops, true)) {
                return $item;
            }
        }
        $best = null;
        $bestValue = 0.0;
        foreach ($crops as $crop) {
            $data = $this->data->crop($crop);
            $yield = $data['yields'][min($fieldLevel, count($data['yields'])) - 1];
            $cost = $farm->productionCost($data['plant_cost'])['cash'] ?? 0;
            $value = ($yield * $this->price($crop) - $cost + $data['xp']) / max(self::TURN, $data['duration']);
            if ($value > $bestValue) {
                [$best, $bestValue] = [$crop, $value];
            }
        }

        return $best;
    }

    // ------------------------------------------------------------ production buildings

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function productions(Farm $farm): array
    {
        $needed = $this->neededInputs($farm) + $this->contractNeeds($farm);
        $actions = [];
        foreach ($farm->buildingsOfKind('production') as $building) {
            $definition = $this->data->building($building['type']);
            if (isset($building['job']) || ($definition['production']['mode'] ?? 'run') === 'instant') {
                continue;
            }
            $choice = null;
            foreach (Production::recipes($this->data, $building['type'], $building['level']) as $id => $recipe) {
                if ($this->data->item($id)['unlock_level'] > $farm->level() || $this->full($farm, $id)) {
                    continue;
                }
                $quantity = $this->batches($farm, $building, $id, $recipe);
                if ($quantity === 0) {
                    continue;
                }
                // Asked by its contracts or needed by its chain (feed for its animals...) first, then the most valuable.
                $score = (isset($needed[$id]) ? 1e9 : 0) + $this->recipeValue($farm, $recipe);
                if ($choice === null || $score > $choice[2]) {
                    $choice = [$id, $quantity, $score];
                }
            }
            if ($choice !== null) {
                $payload = ['id' => $building['id'], 'recipe' => $choice[0]];
                if (Production::isBatch($this->data, $building['type'])) {
                    $payload['quantity'] = $choice[1];
                }
                $actions[] = ['start_production', $payload];
            }
        }

        return $actions;
    }

    /**
     * How many batches the barn's inputs, the cash and the barn's room allow (0: none).
     *
     * @param array<string, mixed> $building
     * @param array<string, mixed> $recipe
     */
    private function batches(Farm $farm, array $building, string $id, array $recipe): int
    {
        $max = Production::maxQuantity($this->data, $building['type'], $building['level'], $id);
        foreach ($recipe['inputs'] as $item => $quantity) {
            $max = min($max, intdiv($farm->barnQuantity($item), $quantity));
        }
        $cash = $farm->productionCost($recipe['cost'] ?? [])['cash'] ?? 0;
        if ($cash > 0) {
            $max = min($max, intdiv($farm->resource('cash'), $cash));
        }
        foreach ($recipe['outputs'] as $item => $quantity) {
            if ($farm->isBarnLimited($item) && $quantity > 0) {
                $max = min($max, intdiv(max(0, $farm->barnCapacity() - $farm->barnQuantity($item)), $quantity));
            }
        }

        return max(0, $max);
    }

    /**
     * Inputs its buildings use that the barn is short of (less than two runs'
     * worth): feed for the animals, fertilizer for the orchards, cream for butter...
     *
     * @return array<string, true>
     */
    private function neededInputs(Farm $farm): array
    {
        $needed = [];
        foreach ($farm->buildingsOfKind('production') as $building) {
            foreach (Production::recipes($this->data, $building['type'], $building['level']) as $id => $recipe) {
                if ($this->data->item($id)['unlock_level'] > $farm->level()) {
                    continue;
                }
                foreach ($recipe['inputs'] as $item => $quantity) {
                    if ($farm->barnQuantity($item) < 2 * $quantity) {
                        $needed[$item] = true;
                    }
                }
            }
        }

        return $needed;
    }

    /** @param array<string, mixed> $recipe */
    private function recipeValue(Farm $farm, array $recipe): float
    {
        $value = $recipe['xp'] ?? 0;
        foreach ($recipe['outputs'] as $item => $quantity) {
            $value += $quantity * $this->price($item);
        }
        foreach ($recipe['inputs'] as $item => $quantity) {
            $value -= $quantity * $this->price($item);
        }
        $value -= $farm->productionCost($recipe['cost'] ?? [])['cash'] ?? 0;

        return $value / max(self::TURN, $recipe['duration'] ?? 0);
    }

    // ------------------------------------------------------------ investing

    /**
     * The simulated bots' next purchase, turned into a game action
     * (a real spot on the grid for a new building, land when there is none).
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function purchase(Farm $farm): ?array
    {
        // A crowded barn (3 products or more at three quarters of their room) grows first.
        $crowded = array_filter($farm->barnItems(), fn (int $q, string $item): bool => $farm->isBarnLimited($item) && $q >= 0.75 * $farm->barnCapacity(), ARRAY_FILTER_USE_BOTH);
        $barn = $farm->buildingsOfType('barn')[0] ?? null;
        if (count($crowded) >= 3 && $barn !== null) {
            $next = $this->data->buildingLevel('barn', $barn['level'] + 1);
            if ($next !== null && $farm->canAfford($next['cost']) && $farm->resource('cash') - ($next['cost']['cash'] ?? 0) >= $this->reserve($farm) / 2) {
                return ['upgrade', ['id' => $barn['id']]];
            }
        }
        $ids = [];
        $bot = [
            'level' => $farm->level(), 'cash' => $farm->resource('cash'), 'gold' => $farm->resource('gold'),
            'materials' => $farm->toArray()['materials'], 'expansions' => $farm->expansions(), 'buildings' => [],
        ];
        foreach ($farm->buildings() as $building) {
            if ($this->rules->isUsable($building['type'])) {
                $ids[] = $building['id'];
                $bot['buildings'][] = [$building['type'], $building['level']];
            }
        }
        $step = $this->rules->purchaseStep($bot, $this->reserve($farm));
        if ($step === null) {
            return null;
        }
        [$kind, $what] = $step;

        return match ($kind) {
            'upgrade' => ['upgrade', ['id' => $ids[$what]]],
            'expansion' => ['buy_expansion', ['option' => $what]],
            default => ($spot = $farm->spotFor($what)) !== null
                ? ['build', ['type' => $what, 'x' => $spot[0], 'y' => $spot[1]]]
                : ['buy_expansion', ['option' => $this->cashOption($farm)]], // no room: more land first
        };
    }

    /**
     * Cash kept back from purchases: one run of every production building
     * and one planting of every field (so nothing stops for lack of cash),
     * plus half of what is left.
     */
    private function reserve(Farm $farm): int
    {
        $running = 0;
        foreach ($farm->buildings() as $building) {
            $kind = $this->data->building($building['type'])['kind'];
            if ($kind === 'production') {
                $costs = array_map(
                    static fn (array $recipe): int => $recipe['cost']['cash'] ?? 0,
                    Production::recipes($this->data, $building['type'], $building['level']),
                );
                $running += $costs === [] ? 0 : $farm->productionCost(['cash' => max($costs)])['cash'];
            } elseif ($kind === 'field') {
                $running += $farm->productionCost(['cash' => 200])['cash']; // a usual planting
            }
        }
        $cash = $farm->resource('cash');

        return $cash <= $running ? $cash : $running + intdiv($cash - $running, 2);
    }

    private function cashOption(Farm $farm): int
    {
        foreach ($this->data->expansion($farm->expansions() + 1)['costs'] ?? [] as $option => $cost) {
            if (!isset($cost['gold'])) {
                return $option;
            }
        }

        return 0;
    }

    // ------------------------------------------------------------ helpers

    private function full(Farm $farm, string $item): bool
    {
        return $farm->isBarnLimited($item) && $farm->barnQuantity($item) >= $farm->barnCapacity();
    }

    private function price(string $item): float
    {
        $definition = $this->data->item($item);
        if (($definition['sellable'] ?? true) === false) {
            return 0.0;
        }

        return ($definition['marketable'] ?? false) ? $definition['market_price'] : $definition['barn_price'];
    }

    /** @param array<string, mixed> $payload */
    private static function key(string $type, array $payload): string
    {
        return $type . json_encode($payload);
    }

    /**
     * The engine returns JSON shapes (objects); keep plain arrays between steps.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function plain(array $state): array
    {
        return json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
