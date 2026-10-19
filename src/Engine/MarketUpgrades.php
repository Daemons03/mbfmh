<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Market upgrades: tracks bought level by level (materials, or gold for the
 * "or" tracks). Each one improves every delivered contract (cash %, XP,
 * market XP or a material) or adds contract slots.
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

    /**
     * What a delivered contract pays on top of its reward: cash is a
     * percentage of the contract (cash_percent_per_point per point), XP and
     * market XP are flat, materials come from their tracks.
     *
     * @param array<string, mixed> $reward the contract's own reward
     * @return array{cash: int, xp: int, market_xp: int, materials: array<string, int>}
     */
    public function extraFor(Farm $farm, array $reward): array
    {
        $bonus = $this->contractBonus($farm);
        $bonus['cash'] = (int) round($reward['cash'] * $bonus['cash'] * $this->cashPercentPerPoint() / 100);

        return $bonus;
    }

    public function cashPercentPerPoint(): float
    {
        return (float) ($this->data->toArray()['market_upgrades']['cash_percent_per_point'] ?? 0);
    }

    /**
     * Market level: level n + 1 needs level_step × n² market XP in total
     * (no top level).
     *
     * @return array{level: int, xp: int, needed: int}
     */
    public function marketLevel(Farm $farm): array
    {
        return self::levelFor($this->data, $farm->marketXp());
    }

    /**
     * Market level of a total market XP (the player's or a bot's).
     *
     * @return array{level: int, xp: int, needed: int}
     */
    public static function levelFor(GameData $data, int $total): array
    {
        $step = $data->market()['market_xp']['level_step'] ?? 1800;
        $level = (int) floor(sqrt($total / $step)) + 1;
        while ($step * $level ** 2 <= $total) { // float rounding at exact thresholds
            $level++;
        }
        $floor = $step * ($level - 1) ** 2;

        return ['level' => $level, 'xp' => $total - $floor, 'needed' => $step * $level ** 2 - $floor];
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
        $bonus['cash_percent'] = round($bonus['cash'] * $this->cashPercentPerPoint(), 2);
        unset($bonus['cash']);
        $bonus['materials'] = (object) $bonus['materials'];

        return [
            'slots' => $this->slots($farm),
            'bonus' => $bonus,
            'level' => $this->marketLevel($farm),
        ];
    }
}
