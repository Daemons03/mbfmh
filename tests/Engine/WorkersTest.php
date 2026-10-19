<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\GameData;

/**
 * Workers and happiness with the real data: farmhouse 22 workers, fence 110
 * happiness, houses (+workers, -happiness), decorations (+happiness).
 */
final class WorkersTest extends EngineTestCase
{
    protected function gameData(): GameData
    {
        return GameData::load(cacheDir: null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 3; // houses need level 2, the flowerbed level 3
        $this->state['cash'] = 10_000;
    }

    public function testStartingFarm(): void
    {
        $view = $this->decode($this->engine->view($this->state, self::NOW));

        self::assertSame(['total' => 22, 'used' => 20, 'free' => 2], $view['workers'], 'farmhouse 22, two fields 10 each');
        self::assertSame(110, $view['happiness']['value']);
        self::assertSame(0.3740, $view['happiness']['cost_factor']);
    }

    public function testBuildingNeedsFreeWorkers(): void
    {
        $error = $this->assertGameError('not_enough_workers', 'build', ['type' => 'field', 'x' => 8, 'y' => 0]);
        self::assertSame(['needed' => 10, 'free' => 2], $error->details);

        $this->act('build', ['type' => 'house', 'x' => 6, 'y' => 6]);
        $this->act('build', ['type' => 'field', 'x' => 8, 'y' => 0]);

        $view = $this->decode($this->engine->view($this->state, self::NOW));
        self::assertSame(['total' => 32, 'used' => 30, 'free' => 2], $view['workers']);
        self::assertSame(75, $view['happiness']['value'], '110 - 35');
    }

    public function testUpgradeNeedsTheExtraWorkers(): void
    {
        $this->state['level'] = 13;
        $this->state['cash'] = 100_000;

        // Field level 2 uses 12 workers instead of 10: 2 more, exactly what is free.
        $this->act('upgrade', ['id' => 'b2']);
        $error = $this->assertGameError('not_enough_workers', 'upgrade', ['id' => 'b3']);
        self::assertSame(['needed' => 2, 'free' => 0], $error->details);
    }

    public function testHousesCannotBeDemolishedWhileTheirWorkersAreBusy(): void
    {
        $this->act('build', ['type' => 'house', 'x' => 6, 'y' => 6]);
        $this->act('build', ['type' => 'field', 'x' => 8, 'y' => 0]);

        $this->assertGameError('workers_needed', 'demolish', ['id' => 'b4']);
        $this->act('demolish', ['id' => 'b5']);
        $this->act('demolish', ['id' => 'b4']);
    }

    public function testHappinessLowersProductionCosts(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        self::assertSame(10_000 - 16, $this->state['cash'], 'corn 43 x 0.374 = 16');
    }

    public function testHappinessNeverGoesBelowZero(): void
    {
        $this->state['cash'] = 100_000;
        $this->state['level'] = 50;
        $this->state['expansions'] = 16;
        for ($i = 0; $i < 5; $i++) {
            $this->state['buildings'][] = ['id' => "h{$i}", 'type' => 'house', 'x' => 2 * $i, 'y' => 8, 'level' => 1];
        }
        // 110 - 5 x 35 = -65: held at 0, costs stay normal (x1).
        $view = $this->decode($this->engine->view($this->state, self::NOW));
        self::assertSame(0, $view['happiness']['value']);
        self::assertSame(1, $view['happiness']['cost_factor']);

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        self::assertSame(100_000 - 43, $this->state['cash'], 'corn 43 x 1');
    }

    public function testDecorationsAddHappiness(): void
    {
        $this->act('build', ['type' => 'flowerbed', 'x' => 6, 'y' => 6]);

        $view = $this->decode($this->engine->view($this->state, self::NOW));
        self::assertSame(122, $view['happiness']['value']);
        self::assertSame(['total' => 22, 'used' => 20, 'free' => 2], $view['workers'], 'decorations need no workers');
    }

    public function testProductionCostsFollowHappinessToo(): void
    {
        $this->state['level'] = 14;
        $this->state['cash'] = 100_000;
        $this->act('build', ['type' => 'house', 'x' => 6, 'y' => 6]);
        $this->act('build', ['type' => 'chicken_coop', 'x' => 8, 'y' => 6]);
        $this->state['barn']['items'] = ['chicken_feed' => 10];
        $cash = $this->state['cash'];

        // 110 - 35 = 75 happiness: factor 0.5 + (0.39 - 0.5) x 25 / 50 = 0.445; coop costs 4 cash -> 2
        $this->act('start_production', ['id' => 'b5']);

        self::assertSame($cash - 2, $this->state['cash']);
    }

    public function testOlderFarmsGetFarmhouseAndFence(): void
    {
        $this->state['buildings'] = array_values(array_filter(
            $this->state['buildings'],
            static fn (array $b): bool => !in_array($b['type'], ['farmhouse', 'fence'], true),
        ));

        $resolved = $this->decode($this->engine->resolve($this->state, self::NOW));

        self::assertSame(['farmhouse', 'fence'], array_slice(array_column($resolved['buildings'], 'id'), -2));
        self::assertSame($this->state['next_id'], $resolved['next_id'], 'ids b1, b2... untouched');
    }

    public function testFarmhouseStandsOnTheFarmFromTheStart(): void
    {
        self::assertSame(['id' => 'farmhouse', 'type' => 'farmhouse', 'x' => 0, 'y' => 2, 'level' => 1], $this->building('farmhouse'));
        $this->assertGameError('cell_occupied', 'build', ['type' => 'house', 'x' => 0, 'y' => 2]);

        $this->act('move', ['id' => 'farmhouse', 'x' => 6, 'y' => 6]);
        self::assertSame([6, 6], [$this->building('farmhouse')['x'], $this->building('farmhouse')['y']]);
    }

    public function testOutsideFarmhouseOfOlderFarmsMovesOntoTheGrid(): void
    {
        $this->setBuilding('farmhouse', ['level' => 3]);
        foreach ($this->state['buildings'] as $i => $building) {
            if ($building['id'] === 'farmhouse') {
                $this->state['buildings'][$i] = ['id' => 'farmhouse', 'type' => 'farmhouse', 'level' => 3];
            }
        }
        $this->state['buildings'][] = ['id' => 'b9', 'type' => 'house', 'x' => 0, 'y' => 2, 'level' => 1];

        $resolved = $this->decode($this->engine->resolve($this->state, self::NOW));
        $farmhouse = array_values(array_filter($resolved['buildings'], static fn (array $b): bool => $b['id'] === 'farmhouse'))[0];

        self::assertSame(3, $farmhouse['level'], 'level kept');
        self::assertArrayHasKey('x', $farmhouse);
        self::assertNotSame([0, 2], [$farmhouse['x'], $farmhouse['y']], 'start spot taken: first free spot');
    }

    public function testFarmhouseAndFenceArePermanent(): void
    {
        $this->assertGameError('not_demolishable', 'demolish', ['id' => 'farmhouse']);
        $this->assertGameError('not_movable', 'move', ['id' => 'fence', 'x' => 5, 'y' => 5]);
        $this->assertGameError('max_count_reached', 'build', ['type' => 'fence']);

        $this->state['cash'] = 20_000;
        $this->state['level'] = 14;
        $this->act('upgrade', ['id' => 'farmhouse']);
        $this->act('upgrade', ['id' => 'fence']);
        $view = $this->decode($this->engine->view($this->state, self::NOW));
        self::assertSame(24, $view['workers']['total']);
        self::assertSame(140, $view['happiness']['value']);
    }
}
