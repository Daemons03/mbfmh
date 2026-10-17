<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

final class OutsideBuildingTest extends EngineTestCase
{
    public function testBuildOutsideBuildingWithoutPosition(): void
    {
        $this->state['level'] = 13;

        $this->act('build', ['type' => 'external_barn']);

        self::assertSame(['id' => 'b4', 'type' => 'external_barn', 'level' => 1], $this->building('b4'));
        self::assertSame(0, $this->state['gold'], 'level 1 costs 50 gold');
    }

    public function testExternalBarnAddsCapacity(): void
    {
        $this->state['level'] = 13;
        $this->act('build', ['type' => 'external_barn']);
        $this->state['barn']['items'] = ['corn' => 240];

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(['corn' => 250], $this->state['barn']['items'], '200 barn + 50 external barn');
    }

    public function testOutsideBuildingsDoNotBlockTheGrid(): void
    {
        $this->state['level'] = 15;
        $this->state['cash'] = 10_000;
        $this->act('build', ['type' => 'library']);

        $this->act('build', ['type' => 'field', 'x' => 0, 'y' => 2]);
        self::assertCount(7, $this->state['buildings'], 'barn, 2 fields, farmhouse, fence, library, new field');
    }

    public function testOutsideBuildingRules(): void
    {
        $this->assertGameError('level_too_low', 'build', ['type' => 'external_barn']);

        $this->state['level'] = 15;
        $this->act('build', ['type' => 'library']);
        $this->assertGameError('max_count_reached', 'build', ['type' => 'library']);
        $this->assertGameError('not_movable', 'move', ['id' => 'b4', 'x' => 5, 'y' => 5]);
        $this->assertGameError('not_demolishable', 'demolish', ['id' => 'b4']);
    }

    public function testUpgradeOutsideBuilding(): void
    {
        $this->state['level'] = 15;
        $this->act('build', ['type' => 'library']);
        $this->state['materials']['bricks'] = 150;
        $this->state['materials']['tiles'] = 150;
        $this->state['materials']['glass'] = 10;

        $this->act('upgrade', ['id' => 'b4']);

        self::assertSame(2, $this->building('b4')['level']);
        self::assertSame(0, $this->state['materials']['bricks']);
    }
}
