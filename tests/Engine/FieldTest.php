<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Engine;

final class FieldTest extends EngineTestCase
{
    public function testNewStateHasStartingFarm(): void
    {
        self::assertSame(1, $this->state['level']);
        self::assertSame(2000, $this->state['cash']);
        self::assertSame(50, $this->state['gold']);
        self::assertSame(6, $this->state['expansions']);
        self::assertSame(['barn', 'field', 'field'], array_column($this->state['buildings'], 'type'));
        self::assertSame(['b1', 'b2', 'b3'], array_column($this->state['buildings'], 'id'));
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

        self::assertSame(2000 - 47, $this->state['cash']);
        self::assertSame(['product' => 'corn', 'started_at' => self::NOW, 'duration' => 300], $this->building('b2')['job']);
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
        $this->state['cash'] = 46;

        $error = $this->assertGameError('not_enough_resources', 'plant', ['id' => 'b2', 'crop' => 'corn']);
        self::assertSame(['missing' => ['cash' => 1]], $error->details);
    }

    public function testHarvestBeforeReady(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $error = $this->assertGameError('not_ready', 'harvest', ['id' => 'b2'], 299);
        self::assertSame(['remaining' => 1], $error->details);
    }

    public function testHarvestFillsBarnAndGivesXp(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(['corn' => 10], $this->state['barn']['items']);
        self::assertSame(2, $this->state['xp']);
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

        self::assertSame(['corn' => 10, 'wildflower' => 10], $this->state['barn']['items']);
    }

    public function testAcceleratedTime(): void
    {
        $this->state['settings']['speed'] = 10;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $this->assertGameError('not_ready', 'harvest', ['id' => 'b2'], 29);
        $this->act('harvest', ['id' => 'b2'], 30);

        self::assertSame(['corn' => 10], $this->state['barn']['items']);
    }

    public function testSpeedChangeAppliesToRunningJob(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->assertGameError('not_ready', 'harvest', ['id' => 'b2'], 60);

        $this->state['settings']['speed'] = 5;
        $this->act('harvest', ['id' => 'b2'], 60);

        self::assertSame(['corn' => 10], $this->state['barn']['items']);
    }

    public function testHarvestRefusedWhenBarnFull(): void
    {
        $this->state['barn']['items'] = ['wildflower' => 195];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $error = $this->assertGameError('barn_full', 'harvest', ['id' => 'b2'], 300);

        self::assertSame(['free' => 5, 'needed' => 10], $error->details);
        self::assertArrayHasKey('job', $this->building('b2'), 'crop stays in the field');
    }

    public function testHarvestCanFillBarnExactly(): void
    {
        $this->state['barn']['items'] = ['wildflower' => 190];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('plant', ['id' => 'b3', 'crop' => 'corn']);

        $this->act('harvest', ['id' => 'b2'], 300);
        self::assertSame(200, array_sum($this->state['barn']['items']));

        $this->state['barn']['items']['wildflower'] = 191 - 10;
        $this->assertGameError('barn_full', 'harvest', ['id' => 'b3'], 300);
    }

    public function testHarvestLevelsUp(): void
    {
        $this->state['xp'] = $this->data->xpForLevel(1) - 1;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(2, $this->state['level']);
        self::assertSame(1, $this->state['xp']);
    }

    public function testBigXpGainCrossesSeveralLevels(): void
    {
        $this->state['xp'] = $this->data->xpForLevel(1) + $this->data->xpForLevel(2) + 3 - 2;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('harvest', ['id' => 'b2'], 300);

        self::assertSame(3, $this->state['level']);
        self::assertSame(3, $this->state['xp']);
    }

    public function testInputStateIsNeverModified(): void
    {
        $before = $this->state;
        $after = $this->engine->apply($this->state, 'plant', ['id' => 'b2', 'crop' => 'corn'], self::NOW);

        self::assertSame($before, $this->state);
        self::assertNotSame($before['cash'], $after['cash']);
    }
}
