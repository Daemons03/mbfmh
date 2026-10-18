<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Market upgrades: tracks bought level by level. Each one improves every
 * delivered contract (cash, XP or a material) or adds contract slots.
 * levels[n - 1].bonus is the total at level n. State: market_upgrades = {track: level}.
 */
final readonly class MarketUpgrades
{
    public function __construct(private GameData $data)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tracks(): array
    {
        return $this->data->toArray()['market_upgrades']['tracks'] ?? [];
    }

    public function level(Farm $farm, string $track): int
    {
        return $farm->marketUpgrades()[$track] ?? 0;
    }

    public function bonus(Farm $farm, string $track): int
    {
        $level = $this->level($farm, $track);

        return $level === 0 ? 0 : $this->tracks()[$track]['levels'][$level - 1]['bonus'];
    }

    public function slots(Farm $farm): int
    {
        return $this->data->market()['slots'] + $this->totalOf($farm, 'slots')['slots'];
    }

    /**
     * What every delivered contract brings on top of its own reward.
     *
     * @return array{cash: int, xp: int, market_xp: int, materials: array<string, int>}
     */
    public function contractBonus(Farm $farm): array
    {
        $total = $this->totalOf($farm, 'cash', 'xp', 'market_xp', 'material');

        return ['cash' => $total['cash'], 'xp' => $total['xp'], 'market_xp' => $total['market_xp'], 'materials' => $total['materials']];
    }

    /** Market XP of one delivered contract: the base plus the market XP upgrade. */
    public function marketXpPerContract(Farm $farm): int
    {
        return ($this->data->market()['market_xp']['per_contract'] ?? 0) + $this->contractBonus($farm)['market_xp'];
    }

    /**
     * Market level from the total market XP; levels[n - 1] is the total
     * needed to reach level n + 1.
     *
     * @return array{level: int, xp: int, needed: int|null}
     */
    public function marketLevel(Farm $farm): array
    {
        $thresholds = $this->data->market()['market_xp']['levels'] ?? [];
        $total = $farm->marketXp();
        $level = 1;
        $floor = 0;
        foreach ($thresholds as $threshold) {
            if ($total < $threshold) {
                return ['level' => $level, 'xp' => $total - $floor, 'needed' => $threshold - $floor];
            }
            $level++;
            $floor = $threshold;
        }

        return ['level' => $level, 'xp' => $total - $floor, 'needed' => null];
    }

    /**
     * @return array{slots: int, cash: int, xp: int, market_xp: int, materials: array<string, int>}
     */
    private function totalOf(Farm $farm, string ...$types): array
    {
        $total = ['slots' => 0, 'cash' => 0, 'xp' => 0, 'market_xp' => 0, 'materials' => []];
        foreach ($this->tracks() as $id => $track) {
            $effect = $track['effect'];
            $bonus = $this->bonus($farm, $id);
            if ($bonus === 0 || !in_array($effect['type'], $types, true)) {
                continue;
            }
            if ($effect['type'] === 'material') {
                $total['materials'][$effect['material']] = ($total['materials'][$effect['material']] ?? 0) + $bonus;
            } else {
                $total[$effect['type']] += $bonus;
            }
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function view(Farm $farm): array
    {
        $bonus = $this->contractBonus($farm);
        $bonus['materials'] = (object) $bonus['materials'];

        return [
            'slots' => $this->slots($farm),
            'bonus' => $bonus,
            'level' => $this->marketLevel($farm),
            'xp_per_contract' => $this->marketXpPerContract($farm),
        ];
    }
}
