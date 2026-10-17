<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Engine;
use FermeTranquille\Engine\Farm;

final class FieldTest extends EngineTestCase
{
    public function testNewStateHasStartingFarm(): void
    {
        self::assertSame(1, $this->state['level']);
        self::assertSame(2000, $this->state['cash']);
        self::assertSame(50, $this->state['gold']);
        self::assertSame(6, $this->state['expansions']);
        self::assertSame(['barn', 'field', 'field', 'farmhouse', 'fence'], array_column($this->state['buildings'], 'type'));
        self::assertSame(['b1', 'b2', 'b3', 'farmhouse', 'fence'], array_column($this->state['buildings'], 'id'));
        self::assertSame(0, array_sum($this->state['materials']));
        self::assertSame(['speed' => 1], $this->state['settings']);
        self::assertStringContainsString('"items":{}', json_encode($this->engine->newState()));
    }

    public function testUnknownAction(): void
    {
        $this->assertGameError('unknown_action', 'teleport');
    }

    public function testEveryRegisteredActionRejectsAnEmptyPayload(): void
    {
        foreach (Engine::actionTypes() as $type) {
            $this->assertGameError('invalid_payload', $type);
        }
    }

    public function testPlantPaysAndStartsJob(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        self::assertSame(2000 - 43, $this->state['cash']);
        self::assertSame(
            ['product' => 'corn', 'started_at' => self::NOW, 'duration' => 120, 'outputs' => ['corn' => 15]],
            $this->building('b2')['job'],
        );
    }

    public function testPlantFreeCrop(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'wildflower']);

        self::assertSame(2000, $this->state['cash']);
    }

    public function testPlantRules(): void
    {
        $this->assertGameError('level_too_low', 'plant', ['id' => 'b2', 'crop' => 'cabbage']);
        $this->assertGameError('unknown_crop', 'plant', ['id' => 'b2', 'crop' => 'tulip']);
        $this->assertGameError('not_a_field', 'plant', ['id' => 'b1', 'crop' => 'corn']);
        $this->assertGameError('unknown_building', 'plant', ['id' => 'b99', 'crop' => 'corn']);
        $this->assertGameError('invalid_payload', 'plant', ['id' => 'b2']);

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->assertGameError('busy', 'plant', ['id' => 'b2', 'crop' => 'corn']);
    }

    public function testPlantNeedsCash(): void
    {
        $this->state['cash'] = 42;

        $error = $this->assertGameError('not_enough_resources', 'plant', ['id' => 'b2', 'crop' => 'corn']);
        self::assertSame(['missing' => ['cash' => 1]], $error->details);
    }

    public function testHarvestBeforeReady(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $error = $this->assertGameError('not_ready', 'harvest', ['id' => 'b2'], 119);
        self::assertSame(['remaining' => 1], $error->details);
    }

    public function testHarvestFillsBarnAndGivesXp(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(['corn' => 15], $this->state['barn']['items']);
        self::assertSame(1, $this->state['xp']);
        self::assertArrayNotHasKey('job', $this->building('b2'));
        $this->assertGameError('nothing_to_harvest', 'harvest', ['id' => 'b2'], 600);
    }

    public function testHarvestWorksAfterLongOfflinePeriod(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('plant', ['id' => 'b3', 'crop' => 'wildflower']);

        // Player comes back three days later: nothing ran in between.
        $threeDays = 3 * 24 * 3600;
        $this->act('harvest', ['id' => 'b2'], $threeDays);
        $this->act('harvest', ['id' => 'b3'], $threeDays);

        self::assertSame(['corn' => 15, 'wildflower' => 11], $this->state['barn']['items']);
    }

    public function testAcceleratedTime(): void
    {
        $this->state['settings']['speed'] = 10;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $this->assertGameError('not_ready', 'harvest', ['id' => 'b2'], 11);
        $this->act('harvest', ['id' => 'b2'], 12); // 120 s / 10

        self::assertSame(['corn' => 15], $this->state['barn']['items']);
    }

    public function testSpeedChangeAppliesToRunningJob(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->assertGameError('not_ready', 'harvest', ['id' => 'b2'], 60);

        $this->state['settings']['speed'] = 5;
        $this->act('harvest', ['id' => 'b2'], 60);

        self::assertSame(['corn' => 15], $this->state['barn']['items']);
    }

    public function testHarvestRefusedWhenThatProductIsFull(): void
    {
        $this->state['barn']['items'] = ['corn' => 190];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $error = $this->assertGameError('barn_full', 'harvest', ['id' => 'b2'], 300);

        self::assertSame(['item' => 'corn', 'free' => 10, 'needed' => 15], $error->details);
        self::assertArrayHasKey('job', $this->building('b2'), 'crop stays in the field');
    }

    public function testCapacityIsPerProduct(): void
    {
        $this->state['barn']['items'] = ['wildflower' => 200, 'cabbage' => 200];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(['wildflower' => 200, 'cabbage' => 200, 'corn' => 15], $this->state['barn']['items']);
    }

    public function testHarvestCanFillAProductExactly(): void
    {
        $this->state['barn']['items'] = ['corn' => 185];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('plant', ['id' => 'b3', 'crop' => 'corn']);

        $this->act('harvest', ['id' => 'b2'], 300);
        self::assertSame(['corn' => 200], $this->state['barn']['items']);

        $this->state['barn']['items']['corn'] = 186;
        $this->assertGameError('barn_full', 'harvest', ['id' => 'b3'], 300);
    }

    public function testHarvestLevelsUp(): void
    {
        $this->state['xp'] = $this->data->xpForLevel(1) - 1;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(2, $this->state['level']);
        self::assertSame(0, $this->state['xp']);
    }

    public function testBigXpGainCrossesSeveralLevels(): void
    {
        $farm = new Farm($this->state, $this->data);
        $farm->addXp($this->data->xpForLevel(1) + $this->data->xpForLevel(2) + 3);

        self::assertSame([3, 3], [$farm->level(), $farm->xp()]);
    }

    public function testYieldAndFlowerNectarFollowFieldLevel(): void
    {
        $this->setBuilding('b2', ['level' => 10]);
        $this->state['level'] = 14;
        $this->act('plant', ['id' => 'b2', 'crop' => 'wheat']);
        $this->act('harvest', ['id' => 'b2'], 6 * 3600);

        self::assertSame(['wheat' => 173, 'flower_nectar' => 162], $this->state['barn']['items']);
    }

    public function testNoFlowerNectarBelowFieldLevel8(): void
    {
        $this->setBuilding('b2', ['level' => 7]);
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        self::assertSame(['corn' => 24], $this->building('b2')['job']['outputs']);
    }

    public function testLegacyFieldJobWithoutOutputs(): void
    {
        $this->setBuilding('b2', ['job' => ['product' => 'corn', 'started_at' => self::NOW, 'duration' => 300]]);

        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(['corn' => 15], $this->state['barn']['items']);
    }

    public function testHumusGivesTwentyFivePercentMore(): void
    {
        $this->state['barn']['items'] = ['humus' => 1];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn', 'humus' => true]);
        self::assertSame([], $this->state['barn']['items'], 'humus spent at planting');
        self::assertTrue($this->building('b2')['job']['humus']);

        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(['corn' => 18], $this->state['barn']['items'], '15 x 1.25 = 18.75, rounded down');
    }

    public function testHumusRules(): void
    {
        $error = $this->assertGameError('not_enough_items', 'plant', ['id' => 'b2', 'crop' => 'corn', 'humus' => true]);
        self::assertSame(['missing' => ['humus' => 1]], $error->details);
        $this->assertGameError('invalid_payload', 'plant', ['id' => 'b2', 'crop' => 'corn', 'humus' => 'yes']);

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn', 'humus' => false]);
        self::assertArrayNotHasKey('humus', $this->building('b2')['job']);
    }

    public function testInputStateIsNeverModified(): void
    {
        $before = $this->state;
        $after = $this->engine->apply($this->state, 'plant', ['id' => 'b2', 'crop' => 'corn'], self::NOW);

        self::assertSame($before, $this->state);
        self::assertNotSame($before['cash'], $after['cash']);
    }
}
