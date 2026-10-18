<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

/**
 * "Tout planter" / "tout produire": the same start on every idle building of
 * the group while resources last. Starting fields: b2 (2,0) and b3 (2,2).
 */
final class StartAllTest extends EngineTestCase
{
    public function testPlantAllFreeFields(): void
    {
        $cash = $this->state['cash'];

        $this->act('plant_all', ['id' => 'b3', 'crop' => 'corn']);

        self::assertSame('corn', $this->building('b2')['job']['product']);
        self::assertSame('corn', $this->building('b3')['job']['product']);
        self::assertLessThan($cash, $this->state['cash']);
    }

    public function testPlantAllStopsWhereResourcesRunOut(): void
    {
        $this->state['barn']['items'] = ['humus' => 1];
        $cashBefore = $this->state['cash'];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn', 'humus' => true]);
        $onePlanting = $cashBefore - $this->state['cash'];
        $this->act('cancel_job', ['id' => 'b2']);
        $this->state['barn']['items'] = ['humus' => 1];
        $cash = $this->state['cash'];

        $this->act('plant_all', ['id' => 'b2', 'crop' => 'corn', 'humus' => true]);

        self::assertTrue($this->building('b2')['job']['humus']);
        self::assertArrayNotHasKey('job', $this->building('b3'), 'no humus left: stays free');
        self::assertSame($cash - $onePlanting, $this->state['cash'], 'the refused field paid nothing');
        self::assertSame([], $this->state['barn']['items']);
    }

    public function testPlantAllRefusedWhenNothingCanStart(): void
    {
        $this->assertGameError('not_enough_items', 'plant_all', ['id' => 'b2', 'crop' => 'corn', 'humus' => true]);
    }

    public function testStartProductionOnEveryIdleCoopWhileFeedLasts(): void
    {
        $this->state['cash'] = 10_000;
        $this->act('build', ['type' => 'chicken_coop', 'x' => 6, 'y' => 4]);
        $this->act('build', ['type' => 'chicken_coop', 'x' => 8, 'y' => 4]);
        $this->state['barn']['items'] = ['chicken_feed' => 15];
        [$first, $second] = array_values(array_filter($this->state['buildings'], fn ($b) => $b['type'] === 'chicken_coop'));

        $this->act('start_production_all', ['id' => $second['id']]);

        self::assertArrayHasKey('job', $this->building($second['id']), 'the chosen one first');
        self::assertArrayNotHasKey('job', $this->building($first['id']), '5 feed left: not enough for 10');
        self::assertSame(['chicken_feed' => 5], $this->state['barn']['items']);
    }
}
