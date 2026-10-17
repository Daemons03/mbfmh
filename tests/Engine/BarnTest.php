<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

final class BarnTest extends EngineTestCase
{
    public function testSellAtBarnPrice(): void
    {
        $this->state['barn']['items'] = ['corn' => 12];

        $this->act('sell_barn', ['item' => 'corn', 'quantity' => 10]);

        self::assertSame(2000 + 10 * 5, $this->state['cash']);
        self::assertSame(['corn' => 2], $this->state['barn']['items']);
    }

    public function testSellingEverythingRemovesTheItem(): void
    {
        $this->state['barn']['items'] = ['corn' => 3, 'egg' => 1];

        $this->act('sell_barn', ['item' => 'corn', 'quantity' => 3]);

        self::assertSame(['egg' => 1], $this->state['barn']['items']);
    }

    public function testSellRules(): void
    {
        $this->state['barn']['items'] = ['corn' => 2];

        $error = $this->assertGameError('not_enough_items', 'sell_barn', ['item' => 'corn', 'quantity' => 3]);
        self::assertSame(['item' => 'corn', 'missing' => 1], $error->details);
        $this->assertGameError('unknown_item', 'sell_barn', ['item' => 'gems', 'quantity' => 1]);
        $this->assertGameError('invalid_payload', 'sell_barn', ['item' => 'corn', 'quantity' => 0]);
        $this->assertGameError('invalid_payload', 'sell_barn', ['item' => 'corn', 'quantity' => '2']);
    }

    public function testUpgradeBarnRaisesCapacity(): void
    {
        $this->state['barn']['items'] = ['corn' => 195];
        $this->act('upgrade', ['id' => 'b1']);

        self::assertSame(2, $this->building('b1')['level']);
        self::assertSame(2000 - 200, $this->state['cash']);

        // 250 per product now: 195 + 10 corn fits.
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 300);
        self::assertSame(['corn' => 205], $this->state['barn']['items']);
    }

    public function testUpgradeRules(): void
    {
        // Barn level 3 costs 1 100, level 4 costs 11 000.
        $this->act('upgrade', ['id' => 'b1']);
        $this->act('upgrade', ['id' => 'b1']);
        $this->assertGameError('not_enough_resources', 'upgrade', ['id' => 'b1']);

        // Field level 2 needs player level 13.
        $this->state['cash'] = 100_000;
        $this->assertGameError('level_too_low', 'upgrade', ['id' => 'b2']);

        $this->state['level'] = 13;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->assertGameError('busy', 'upgrade', ['id' => 'b2']);
    }

    public function testUpgradeStopsAtMaxLevel(): void
    {
        $this->state['buildings'][0]['level'] = $this->data->maxBuildingLevel('barn');

        $this->assertGameError('max_level', 'upgrade', ['id' => 'b1']);
    }
}
