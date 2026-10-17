<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Market contracts. Generation is deterministic: contract n of a farm is drawn
 * from sha256(market_seed:n), so the same state always yields the same
 * contracts (reloading cannot reroll them, tests are reproducible).
 *
 * A contract is { id, items: {item: qty}, reward: {cash, xp, materials?} } and,
 * while waiting to replace a discarded one, wait: {started_at, duration}.
 */
final readonly class Market
{
    public function __construct(private GameData $data)
    {
    }

    /**
     * Fills empty slots (new farm, legacy state, more slots from the data or
     * the contracts upgrade).
     */
    public function resolve(Farm $farm, Clock $clock): void
    {
        $slots = (new MarketUpgrades($this->data))->slots($farm);
        while (count($farm->contracts()) < $slots) {
            $contract = $this->generate($farm, $clock, waiting: false);
            if ($contract === null) {
                return;
            }
            $farm->addContract($contract);
        }
    }

    /**
     * @return array<string, mixed>|null null when nothing can be requested
     */
    public function generate(Farm $farm, Clock $clock, bool $waiting): ?array
    {
        $eligible = $this->eligibleItems($farm);
        if ($eligible === []) {
            return null;
        }

        $market = $this->data->market();
        $number = $farm->takeContractNumber();
        $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', $farm->marketSeed($clock->now) . ':' . $number, true)));

        [$minProducts, $maxProducts] = $market['products_per_contract'];
        $count = $random->getInt(min($minProducts, count($eligible)), min($maxProducts, count($eligible)));
        $picked = $random->pickArrayKeys(array_flip($eligible), $count);

        $items = [];
        $cash = 0.0;
        $bonus = [];
        foreach ($picked as $item) {
            $definition = $this->data->item($item);
            $range = $definition['contract_quantity'] ?? $market['quantity_per_product'];
            $quantity = $random->getInt(...$range);
            $items[$item] = $quantity;
            $cash += $quantity * $definition['market_price'];

            // Crafted goods (sewing) always bring extra materials (mallets or buckets), more for bigger orders.
            if (isset($definition['contract_bonus'])) {
                $materials = $definition['contract_bonus']['materials'];
                [$min, $max] = $definition['contract_bonus']['quantity'];
                $material = $materials[$random->getInt(0, count($materials) - 1)];
                $share = $range[1] > $range[0] ? ($quantity - $range[0]) / ($range[1] - $range[0]) : 1.0;
                $bonus[$material] = ($bonus[$material] ?? 0) + (int) round($min + $share * ($max - $min));
            }
        }

        $reward = ['cash' => (int) round($cash)];
        $reward['xp'] = max(1, (int) round($reward['cash'] * $market['xp_per_cash']));
        $materialReward = $market['material_reward'];
        // The usual random bonus (boards, nails, saws) can come on top of it.
        if ($random->nextFloat() < $materialReward['chance']) {
            $materials = $materialReward['materials'];
            $material = $materials[$random->getInt(0, count($materials) - 1)];
            $bonus[$material] = ($bonus[$material] ?? 0) + $random->getInt(...$materialReward['quantity']);
        }
        if ($bonus !== []) {
            $reward['materials'] = $bonus;
        }

        $contract = ['id' => 'c' . $number, 'items' => $items, 'reward' => $reward];
        if ($waiting) {
            $contract['wait'] = ['started_at' => $clock->now, 'duration' => $market['replace_delay']];
        }

        return $contract;
    }

    /**
     * What contracts may ask for: marketable products (crops, animal, orchard,
     * meadow and beehive products) the player can currently provide, i.e.
     * unlocked crops plus the outputs of production buildings they own.
     *
     * @return list<string>
     */
    public function eligibleItems(Farm $farm): array
    {
        $level = $farm->level();
        $eligible = [];
        foreach ($this->data->toArray()['crops'] as $crop => $_) {
            if ($this->data->item($crop)['unlock_level'] <= $level) {
                $eligible[$crop] = true;
            }
        }
        foreach ($farm->buildingsOfKind('production') as $building) {
            $outputs = array_merge(...array_values(array_map(
                static fn (array $recipe): array => $recipe['outputs'],
                Production::recipes($this->data, $building['type'], $building['level']),
            )));
            foreach ($outputs as $item => $_) {
                $definition = $this->data->item($item);
                if (($definition['marketable'] ?? false) && $definition['unlock_level'] <= $level) {
                    $eligible[$item] = true;
                }
            }
        }

        return array_keys($eligible);
    }

    /**
     * @param array<string, mixed> $contract
     */
    public static function isWaiting(array $contract, Clock $clock): bool
    {
        return isset($contract['wait']) && !$clock->isReady($contract['wait']);
    }
}
