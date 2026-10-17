<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Market;
use FermeTranquille\Engine\Production;

/**
 * Meadow (one seed per run) and fabrique artisanale (batches): values from the game.
 */
final class MeadowTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200;
        $this->state['cash'] = 100_000_000;
        $this->setBuilding('b1', ['level' => 25]); // 1 400 places per product
        $this->act('build', ['type' => 'meadow', 'x' => 6, 'y' => 4]);
        $this->act('build', ['type' => 'manufactory', 'x' => 9, 'y' => 4]);
    }

    public function testMeadowGrowsTheChosenSeed(): void
    {
        $this->assertGameError('invalid_payload', 'start_production', ['id' => 'b4']);
        $cash = $this->state['cash'];

        $this->act('start_production', ['id' => 'b4', 'recipe' => 'olive']);
        self::assertSame($cash - 3338, $this->state['cash']);
        $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], 8 * 3600 - 1);
        $this->act('collect', ['id' => 'b4'], 8 * 3600);

        self::assertSame(['olive' => 130], $this->state['barn']['items']);
        self::assertSame(1, Production::maxQuantity($this->data, 'meadow', 1, 'olive'), 'one seed at a time');
    }

    public function testMeadowYieldGrowsWithLevel(): void
    {
        self::assertSame(['citrus' => 400], Production::recipe($this->data, 'meadow', 10, 'citrus')['outputs']);
        self::assertSame(['lavender' => 60], Production::recipe($this->data, 'meadow', 1, 'lavender')['outputs']);
    }

    public function testOliveToOliveOilToCheese(): void
    {
        $this->state['barn']['items'] = ['olive' => 290, 'milk' => 160];

        $this->act('start_production', ['id' => 'b5', 'recipe' => 'olive_oil', 'quantity' => 2]);
        $this->act('collect', ['id' => 'b5'], 2 * 5 * 3600);
        self::assertSame(['milk' => 160, 'olive_oil' => 260], $this->state['barn']['items']);

        $this->act('build', ['type' => 'dairy', 'x' => 6, 'y' => 6], 10 * 3600);
        $this->act('start_production', ['id' => 'b6', 'recipe' => 'cheese'], 10 * 3600);
        $this->act('collect', ['id' => 'b6'], 10 * 3600 + 25 * 60);
        self::assertSame(['olive_oil' => 150, 'cheese' => 30], $this->state['barn']['items']);
    }

    public function testManufactoryCapacityAndDyeLevels(): void
    {
        self::assertSame(2, Production::maxQuantity($this->data, 'manufactory', 1, 'olive_oil'));
        self::assertSame(8, Production::maxQuantity($this->data, 'manufactory', 12, 'olive_oil'));

        self::assertArrayNotHasKey('red_dye', Production::recipes($this->data, 'manufactory', 7));
        self::assertSame(['cherry' => 50], Production::recipe($this->data, 'manufactory', 8, 'red_dye')['inputs']);
        self::assertArrayNotHasKey('green_dye', Production::recipes($this->data, 'manufactory', 10));
        self::assertSame(['lavender' => 300], Production::recipe($this->data, 'manufactory', 12, 'purple_dye')['inputs']);
        $this->assertGameError('unknown_recipe', 'start_production', ['id' => 'b5', 'recipe' => 'red_dye']);
    }

    public function testMeadowAndFabriqueGoodsAreSold(): void
    {
        $eligible = (new Market($this->data))->eligibleItems(new Farm($this->state, $this->data));

        foreach (['olive', 'lavender', 'olive_oil', 'vanilla_extract'] as $item) {
            self::assertContains($item, $eligible);
        }
    }

    public function testEachSeedNeedsTenMoreLevels(): void
    {
        $levels = array_map(fn (string $seed): int => $this->data->item($seed)['unlock_level'],
            ['lavender', 'aloe_vera', 'vanilla', 'cocoa', 'jasmine', 'olive', 'citrus']);

        self::assertSame([40, 50, 60, 70, 80, 90, 100], $levels);
        $this->state['level'] = 89;
        $this->assertGameError('level_too_low', 'start_production', ['id' => 'b4', 'recipe' => 'olive']);
    }
}
