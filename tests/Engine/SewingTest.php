<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Market;
use FermeTranquille\Engine\Production;

/**
 * Sewing workshop: instant crafting; its goods bring materials in contracts.
 */
final class SewingTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200;
        $this->state['cash'] = 100_000_000;
        $this->setBuilding('b1', ['level' => 25]); // 1 400 places per product
        $this->act('build', ['type' => 'sewing_workshop']);
    }

    public function testTwentyRecipes(): void
    {
        $recipes = Production::recipes($this->data, 'sewing_workshop', 1);

        self::assertCount(20, $recipes);
        self::assertSame(['sheep_wool' => 1500], $recipes['light_socks']['inputs']);
        self::assertSame(['white_wool' => 200, 'purple_dye' => 10], $recipes['purple_slippers']['inputs']);
        self::assertSame(['feather' => 500, 'red_dye' => 10], $recipes['red_pillows']['inputs']);
        self::assertSame(197, $this->data->item('purple_sweater')['unlock_level']);
    }

    public function testCraftingIsInstant(): void
    {
        $this->state['barn']['items'] = ['sheep_wool' => 1300];

        $this->act('start_production', ['id' => 'b4', 'recipe' => 'light_beanie']);

        self::assertSame(['sheep_wool' => 50, 'light_beanie' => 10], $this->state['barn']['items']);
        self::assertArrayNotHasKey('job', $this->building('b4'));
        self::assertSame(50, $this->state['xp']);
    }

    public function testCraftingRules(): void
    {
        $this->state['barn']['items'] = ['sheep_wool' => 1749];
        $this->assertGameError('not_enough_items', 'start_production', ['id' => 'b4', 'recipe' => 'light_sweater']);

        $this->state['level'] = 196;
        $this->state['barn']['items'] = ['sheep_wool' => 2000, 'purple_dye' => 10];
        $this->assertGameError('level_too_low', 'start_production', ['id' => 'b4', 'recipe' => 'purple_sweater']);
        $this->assertGameError('quantity_too_high', 'start_production', ['id' => 'b4', 'recipe' => 'light_sweater', 'quantity' => 2]);
    }

    public function testSewingContractsBringMaterials(): void
    {
        $farm = new Farm($this->state, $this->data);
        $market = new Market($this->data);
        $seen = [];
        for ($i = 0; $i < 300; $i++) {
            $contract = $market->generate($farm, new Clock(self::NOW), waiting: false);
            foreach ($contract['items'] as $item => $quantity) {
                $bonus = $this->data->item($item)['contract_bonus'] ?? null;
                if ($bonus === null) {
                    continue;
                }
                self::assertGreaterThanOrEqual(10, $quantity);
                self::assertLessThanOrEqual(20, $quantity);
                self::assertNotEmpty(array_intersect_key($contract['reward']['materials'], array_flip($bonus['materials'])), $item);
                $expected = str_starts_with($item, 'green') || str_starts_with($item, 'purple') ? 'buckets' : 'mallets';
                self::assertArrayHasKey($expected, $contract['reward']['materials'], $item);
                $seen[$expected] = true;
            }
        }

        self::assertArrayHasKey('buckets', $seen, 'green/purple goods bring buckets');
        self::assertArrayHasKey('mallets', $seen, 'light/red goods bring mallets');
    }

    public function testOlderFarmsMoveTheWorkshopOffTheGrid(): void
    {
        $this->state['buildings'][] = ['id' => 'old', 'type' => 'sewing_workshop', 'x' => 6, 'y' => 4, 'level' => 3];

        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));

        self::assertSame(['id' => 'old', 'type' => 'sewing_workshop', 'level' => 3], $this->building('old'));
        $this->act('build', ['type' => 'house', 'x' => 6, 'y' => 4]); // the cells are free again
    }
}
