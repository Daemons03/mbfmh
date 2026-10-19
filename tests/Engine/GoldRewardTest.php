<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Farm;
use FermeTranquille\GameData;

/**
 * Gold for playing: 100 on the first visit of each day, 1 000 per level gained.
 */
final class GoldRewardTest extends EngineTestCase
{
    protected function gameData(): GameData
    {
        $raw = parent::gameData()->toArray();
        $raw['gold_rewards'] = GameData::load(cacheDir: null)->toArray()['gold_rewards'];

        return GameData::fromArray($raw);
    }

    public function testDailyLoginGoldOncePerDay(): void
    {
        $gold = $this->state['gold'];

        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));
        self::assertSame($gold + 100, $this->state['gold'], 'first visit of the day');
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + 3600));
        self::assertSame($gold + 100, $this->state['gold'], 'same day: nothing more');

        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + 86400));
        self::assertSame($gold + 200, $this->state['gold'], 'next day');
    }

    public function testThousandGoldPerLevelGained(): void
    {
        $farm = new Farm($this->state, $this->data);
        $gold = $farm->resource('gold');

        // Level 1 -> 4 at once: 50 + 111 + 290 XP (round(50 × n^1.6)).
        $farm->addXp($this->data->xpForLevel(1) + $this->data->xpForLevel(2) + $this->data->xpForLevel(3) + 5);

        self::assertSame(4, $farm->level());
        self::assertSame($gold + 3000, $farm->resource('gold'));
    }

    public function testNoMaxLevel(): void
    {
        $this->state['level'] = 900;
        $farm = new Farm($this->state, $this->data);
        $farm->addXp($this->data->xpForLevel(900));

        self::assertSame(901, $farm->level(), 'the curve goes on past the old 250');
    }
}
