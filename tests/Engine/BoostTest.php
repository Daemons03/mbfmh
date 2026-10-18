<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

/**
 * Special items: premium seeds, instant / premium humus, feed and
 * fertilizer, and special crops (roses, tulips, golden flowers). All free to use.
 * Field b2 (level 1, corn: 10), chicken coop b4 and apple orchard b5 built in setUp.
 */
final class BoostTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200;
        $this->state['cash'] = 100_000_000;
        $this->setBuilding('b1', ['level' => 25]);
        $this->act('build', ['type' => 'chicken_coop', 'x' => 6, 'y' => 4]);
        $this->act('build', ['type' => 'apple_orchard', 'x' => 8, 'y' => 4]);
        $this->state['cash'] = 1_000;
    }

    private function give(array $items): void
    {
        $this->state['barn']['items'] = $items;
    }

    public function testPremiumSeedAndHumus(): void
    {
        $this->give(['premium_seed_corn' => 1, 'premium_humus' => 1]);

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn', 'seed' => 'premium', 'boost' => 'premium']);

        // 10 x 1.1 = 11, then x 1.5 = 16.5 -> 16; nothing paid, both items used.
        self::assertSame(['corn' => 16], $this->building('b2')['job']['outputs']);
        self::assertSame(1_000, $this->state['cash']);
        self::assertSame([], $this->state['barn']['items']);
    }

    public function testInstantHumusIsReadyAtOnce(): void
    {
        $this->give(['instant_humus' => 1]);
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn', 'boost' => 'instant']);

        $this->act('harvest', ['id' => 'b2']);
        self::assertSame(['corn' => 10], $this->state['barn']['items']);
    }

    public function testFieldRules(): void
    {
        $this->give(['humus' => 1, 'premium_humus' => 1]);
        $this->assertGameError('invalid_payload', 'plant', ['id' => 'b2', 'crop' => 'corn', 'humus' => true, 'boost' => 'premium']);
        $this->assertGameError('invalid_payload', 'plant', ['id' => 'b2', 'crop' => 'corn', 'boost' => 'golden']);
        $this->assertGameError('not_enough_items', 'plant', ['id' => 'b2', 'crop' => 'corn', 'seed' => 'premium']);
        $this->assertGameError('not_enough_items', 'plant', ['id' => 'b2', 'crop' => 'rose']);
        $this->assertGameError('not_sellable', 'sell_barn', ['item' => 'premium_humus', 'quantity' => 1]);
    }

    public function testRosesGiveXpAtOnce(): void
    {
        $this->give(['rose_seed' => 2]);
        $xp = $this->state['xp'];

        $this->act('plant', ['id' => 'b2', 'crop' => 'rose']);

        self::assertSame($xp + 10, $this->state['xp']);
        self::assertArrayNotHasKey('job', $this->building('b2'), 'the field stays free');
        self::assertSame(['rose_seed' => 1], $this->state['barn']['items']);
    }

    public function testTulipsGiveMarketXpByFieldLevel(): void
    {
        $expected = [1 => 450, 5 => 970, 10 => 1620, 14 => 1980, 18 => 2340];
        foreach ($expected as $level => $marketXp) {
            $this->give(['tulip_seed' => 1]);
            $this->state['market_xp'] = 0;
            $this->setBuilding('b2', ['level' => $level]);
            $this->act('plant', ['id' => 'b2', 'crop' => 'tulip']);
            self::assertSame($marketXp, $this->state['market_xp'], "field level {$level}");
        }
    }

    public function testGoldenFlowersPayGold(): void
    {
        $this->give(['golden_flower_seed' => 1]);
        $gold = $this->state['gold'];
        $xp = $this->state['xp'];

        $this->act('plant', ['id' => 'b2', 'crop' => 'golden_flower']);
        $this->assertGameError('not_ready', 'harvest', ['id' => 'b2'], 7199);
        $this->act('harvest', ['id' => 'b2'], 7200);

        self::assertSame($gold + 200, $this->state['gold']);
        self::assertSame($xp, $this->state['xp']);
        self::assertSame([], $this->state['barn']['items']);
    }

    public function testInstantFeed(): void
    {
        $this->give(['instant_feed' => 1]);

        $this->act('start_production', ['id' => 'b4', 'boost' => 'instant']);
        $this->act('collect', ['id' => 'b4']);

        self::assertSame(['egg' => 21, 'dung' => 7], $this->state['barn']['items'], 'no chicken feed needed');
        self::assertSame(1_000, $this->state['cash'], 'free');
    }

    public function testPremiumFeedAndFertilizer(): void
    {
        $this->give(['premium_feed' => 1, 'premium_fertilizer' => 1]);

        $this->act('start_production', ['id' => 'b4', 'boost' => 'premium']);
        $this->act('start_production', ['id' => 'b5', 'boost' => 'premium']);

        self::assertSame(['egg' => 32, 'dung' => 7], $this->building('b4')['job']['outputs'], '21 x 1.5 = 31.5 -> 32');
        self::assertSame(390, $this->building('b4')['job']['duration']);
        $apples = $this->data->buildingLevel('apple_orchard', 1)['recipes']['apple']['outputs']['apple'];
        self::assertSame((int) ceil($apples * 1.5), $this->building('b5')['job']['outputs']['apple']);
        self::assertSame([], $this->state['barn']['items']);
    }

    public function testProductionRules(): void
    {
        $this->give(['instant_feed' => 1]);
        $this->assertGameError('not_enough_items', 'start_production', ['id' => 'b5', 'boost' => 'instant'], 0);
        $this->act('build', ['type' => 'windmill', 'x' => 10, 'y' => 0]);
        $this->assertGameError('no_boost', 'start_production', ['id' => 'b6', 'recipe' => 'chicken_feed', 'boost' => 'instant']);
    }
}
