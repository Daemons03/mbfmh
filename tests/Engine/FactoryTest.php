<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Production;

/**
 * V2.3 factories with several recipes: dairy, jam kitchen, beehive.
 * Values from the original game (player's screenshots).
 */
final class FactoryTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200;
        $this->state['cash'] = 100_000_000;
        $this->state['materials']['mallets'] = 100; // beehive level 1 costs 50 mallets
        $this->setBuilding('b1', ['level' => 25]); // 1 400 places per product
    }

    public function testDairyRecipesAtLevel1(): void
    {
        $recipes = Production::recipes($this->data, 'dairy', 1);

        self::assertSame(['cream', 'butter', 'cheese', 'cherry_yoghurt'], array_keys($recipes));
        self::assertSame([['milk' => 250], ['cash' => 308], ['cream' => 60], 3 * 3600, 225],
            [$recipes['cream']['inputs'], $recipes['cream']['cost'], $recipes['cream']['outputs'], $recipes['cream']['duration'], $recipes['cream']['xp']]);
        self::assertSame(['olive_oil' => 110, 'milk' => 160], $recipes['cheese']['inputs']);
        self::assertSame(['cherry' => 190, 'milk' => 90], $recipes['cherry_yoghurt']['inputs']);
    }

    public function testDairyCapacityIsInBatches(): void
    {
        self::assertSame(4, Production::maxQuantity($this->data, 'dairy', 1, 'cream'));
        self::assertSame(4, Production::maxQuantity($this->data, 'dairy', 1, 'butter'), 'same batch count for every recipe');
        self::assertSame(22, Production::maxQuantity($this->data, 'dairy', 10, 'cheese'));
    }

    public function testMilkToCreamToButter(): void
    {
        $this->act('build', ['type' => 'dairy', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['milk' => 500];

        $this->act('start_production', ['id' => 'b4', 'recipe' => 'cream', 'quantity' => 2]);
        $this->act('collect', ['id' => 'b4'], 2 * 3 * 3600);
        self::assertSame(['cream' => 120], $this->state['barn']['items']);

        $this->act('start_production', ['id' => 'b4', 'recipe' => 'butter', 'quantity' => 4], 6 * 3600);
        $this->act('collect', ['id' => 'b4'], 6 * 3600 + 4 * 45 * 60);
        self::assertSame(['butter' => 60], $this->state['barn']['items']);
    }

    public function testJamKitchenNeedsHoney(): void
    {
        $this->act('build', ['type' => 'jam_kitchen', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['peach' => 290];

        $error = $this->assertGameError('not_enough_items', 'start_production', ['id' => 'b4', 'recipe' => 'peach_jam']);
        self::assertSame(['missing' => ['flower_honey' => 24]], $error->details);

        $this->state['barn']['items']['flower_honey'] = 24;
        $this->act('start_production', ['id' => 'b4', 'recipe' => 'peach_jam']);
        $this->act('collect', ['id' => 'b4'], 2 * 3600);
        self::assertSame(['peach_jam' => 40], $this->state['barn']['items']);
    }

    public function testBeehiveMakesBothHoneys(): void
    {
        $this->act('build', ['type' => 'beehive', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['nectar' => 50];

        self::assertSame(1, Production::maxQuantity($this->data, 'beehive', 1, 'forest_honey'), 'level 1 holds 50 honey');
        $this->act('start_production', ['id' => 'b4', 'recipe' => 'forest_honey']);
        $this->act('collect', ['id' => 'b4'], 2 * 3600);

        self::assertSame(['forest_honey' => 50], $this->state['barn']['items']);
        self::assertSame(['flower_nectar' => 600], Production::recipe($this->data, 'beehive', 1, 'flower_honey')['inputs']);
    }

    public function testRecipeNeedsPlayerLevel(): void
    {
        $this->state['level'] = 8;
        $this->act('build', ['type' => 'windmill', 'x' => 6, 'y' => 4]);
        $this->state['level'] = 7;
        $this->state['barn']['items'] = ['cabbage' => 60];

        $this->assertGameError('level_too_low', 'start_production', ['id' => 'b4', 'recipe' => 'pig_feed']);
    }
}
