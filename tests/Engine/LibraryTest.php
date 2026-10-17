<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

/**
 * Library: per-product upgrades paid in scrolls, ink and books, capped by the
 * library level; the bonus is added to every harvest / production batch.
 */
final class LibraryTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200;
        $this->state['cash'] = 100_000_000;
        $this->state['materials'] = ['scrolls' => 1_000, 'ink' => 1_000, 'books' => 1_000] + $this->state['materials'];
        $this->setBuilding('b1', ['level' => 25]); // 1 400 places per product
    }

    private function buildLibrary(int $level): void
    {
        $this->state['buildings'][] = ['id' => 'lib', 'type' => 'library', 'level' => $level];
    }

    public function testUpgradeCostsScrollsInkAndBooks(): void
    {
        $this->buildLibrary(2);

        $this->act('upgrade_library', ['item' => 'corn']);
        self::assertSame(['corn' => 1], $this->state['library']);
        self::assertSame([980, 980, 995], [$this->state['materials']['scrolls'], $this->state['materials']['ink'], $this->state['materials']['books']]);

        $this->act('upgrade_library', ['item' => 'corn']);
        self::assertSame(['corn' => 2], $this->state['library']);
    }

    public function testRules(): void
    {
        $this->assertGameError('no_library', 'upgrade_library', ['item' => 'corn']);
        $this->buildLibrary(1);
        $this->assertGameError('unknown_item', 'upgrade_library', ['item' => 'chicken_feed']);

        $this->act('upgrade_library', ['item' => 'corn']);
        $this->assertGameError('library_too_low', 'upgrade_library', ['item' => 'corn']);

        $this->state['materials']['scrolls'] = 0;
        $this->assertGameError('not_enough_resources', 'upgrade_library', ['item' => 'egg']);

        $this->state['level'] = 1;
        $this->state['materials']['scrolls'] = 1_000;
        $this->assertGameError('level_too_low', 'upgrade_library', ['item' => 'milk']);

        $this->state['library'] = ['egg' => 20];
        $this->setBuilding('lib', ['level' => 22]);
        $this->assertGameError('max_level', 'upgrade_library', ['item' => 'egg']);
    }

    public function testFanSiteValues(): void
    {
        $items = $this->data->toArray()['library']['items'];

        self::assertSame(['level' => 14, 'cost' => ['scrolls' => 87900, 'ink' => 87900, 'books' => 27400], 'bonus' => 14], $items['corn']['levels'][13]);
        self::assertSame(['level' => 18, 'cost' => ['scrolls' => 66900, 'ink' => 66900, 'books' => 14600], 'bonus' => 90], $items['milk']['levels'][17]);
        self::assertSame(20, $items['egg']['levels'][19]['bonus']);
    }

    public function testHarvestBonus(): void
    {
        $this->state['library'] = ['corn' => 3];

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 120);

        self::assertSame(['corn' => 10 + 3], $this->state['barn']['items']);
    }

    public function testProductionBonusPerBatch(): void
    {
        $this->state['library'] = ['egg' => 2, 'flower_honey' => 1];
        $this->act('build', ['type' => 'chicken_coop', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['chicken_feed' => 10];

        $view = $this->engine->view($this->state, self::NOW);
        self::assertSame(['egg' => 23, 'dung' => 7], $view['production']->b4['recipes']['egg']['outputs'], 'shown with the bonus');

        $this->act('start_production', ['id' => 'b4']);
        $this->state['library']['egg'] = 5; // later upgrades do not change a running job
        $this->act('collect', ['id' => 'b4'], 390);

        self::assertSame(['egg' => 21 + 2, 'dung' => 7], $this->state['barn']['items']);
    }

    public function testBatchBuildingsGetTheBonusForEachBatch(): void
    {
        $this->state['library'] = ['cheese' => 1];
        $this->act('build', ['type' => 'dairy', 'x' => 6, 'y' => 4]);
        $recipe = $this->engine->view($this->state, self::NOW)['production']->b4['recipes']['cheese'];
        $this->state['barn']['items'] = array_map(static fn (int $q): int => $q * 2, $recipe['inputs']);

        $this->act('start_production', ['id' => 'b4', 'recipe' => 'cheese', 'quantity' => 2]);

        $bonus = $this->data->toArray()['library']['items']['cheese']['levels'][0]['bonus'];
        self::assertSame($recipe['outputs']['cheese'] * 2, $this->building('b4')['job']['outputs']['cheese']);
        self::assertSame(2 * ($this->data->buildingLevel('dairy', 1)['recipes']['cheese']['outputs']['cheese'] + $bonus),
            $this->building('b4')['job']['outputs']['cheese']);
    }

    public function testViewAndNormalizedState(): void
    {
        $this->buildLibrary(4);
        $this->state['library'] = ['milk' => 2];
        $state = $this->engine->normalize($this->state);

        $view = $this->engine->view($state, self::NOW);
        self::assertSame(4, $view['library']['building_level']);
        self::assertEquals((object) ['milk' => 10], $view['library']['bonus']);

        $next = $this->decode($this->engine->apply($state, 'upgrade_library', ['item' => 'milk'], self::NOW));
        self::assertSame(['milk' => 3], $next['library']);
    }
}
