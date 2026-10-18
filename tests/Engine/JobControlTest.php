<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

/**
 * Cancelling a running job (everything engaged is lost) and speeding it up for gold.
 * Field b2 at (2,0); corn grows in 2 min. Starting gold: 50.
 */
final class JobControlTest extends EngineTestCase
{
    public function testCancelLosesEverythingAndFreesTheField(): void
    {
        $this->state['barn']['items'] = ['humus' => 3];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn', 'humus' => true]);
        $cash = $this->state['cash'];

        $this->act('cancel_job', ['id' => 'b2'], 60);

        self::assertArrayNotHasKey('job', $this->building('b2'));
        self::assertSame($cash, $this->state['cash'], 'nothing refunded');
        self::assertSame(['humus' => 2], $this->state['barn']['items']);

        $this->act('plant', ['id' => 'b2', 'crop' => 'wildflower'], 60);
        self::assertSame('wildflower', $this->building('b2')['job']['product']);
    }

    public function testCancelAndSpeedUpRules(): void
    {
        $this->assertGameError('no_job', 'cancel_job', ['id' => 'b2']);
        $this->assertGameError('no_job', 'speed_up', ['id' => 'b2']);
        $this->assertGameError('no_job', 'cancel_job', ['id' => 'b1']); // the barn

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->assertGameError('already_ready', 'cancel_job', ['id' => 'b2'], 300);
        $this->assertGameError('already_ready', 'speed_up', ['id' => 'b2'], 300);
    }

    public function testSpeedUpCostsTenGoldPerStartedMinute(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $this->act('speed_up', ['id' => 'b2'], 90); // 30 s left: one minute at least
        self::assertSame(40, $this->state['gold']);

        $this->act('harvest', ['id' => 'b2'], 90);
        self::assertSame(['corn' => 10], $this->state['barn']['items']);
        self::assertSame(1, $this->state['xp'], 'XP kept');
    }

    public function testSpeedUpCountsMinutesLeftAtTheCurrentSpeed(): void
    {
        $this->state['settings']['speed'] = 0.5;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']); // 240 s at speed 0.5
        $this->state['gold'] = 39;

        $error = $this->assertGameError('not_enough_resources', 'speed_up', ['id' => 'b2']);
        self::assertSame(['missing' => ['gold' => 1]], $error->details, '4 minutes: 40 gold');
        self::assertArrayHasKey('job', $this->building('b2'));
    }
}
