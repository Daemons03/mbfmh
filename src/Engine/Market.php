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
     * Fills empty slots (new farm, legacy state, more slots in the data).
     */
    public function resolve(Farm $farm, Clock $clock): void
    {
        $slots = $this->data->market()['slots'];
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
        foreach ($picked as $item) {
            $quantity = $random->getInt(...$market['quantity_per_product']);
            $items[$item] = $quantity;
            $cash += $quantity * $this->data->item($item)['market_price'];
        }

        $reward = ['cash' => (int) round($cash)];
        $reward['xp'] = max(1, (int) round($reward['cash'] * $market['xp_per_cash']));
        $materialReward = $market['material_reward'];
        if ($random->nextFloat() < $materialReward['chance']) {
            $materials = $materialReward['materials'];
            $material = $materials[$random->getInt(0, count($materials) - 1)];
            $reward['materials'] = [$material => $random->getInt(...$materialReward['quantity'])];
        }

        $contract = ['id' => 'c' . $number, 'items' => $items, 'reward' => $reward];
        if ($waiting) {
            $contract['wait'] = ['started_at' => $clock->now, 'duration' => $market['replace_delay']];
        }

        return $contract;
    }

    /**
     * What the player can currently provide: unlocked crops, plus the outputs
     * of production buildings they own.
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
                if ($this->data->item($item)['unlock_level'] <= $level) {
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
