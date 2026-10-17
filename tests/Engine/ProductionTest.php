<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Production;
use FermeTranquille\GameData;

/**
 * Windmill b4 at (6,4) and chicken coop b5 at (8,4) on top of the starting farm.
 */
final class ProductionTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['cash'] = 10_000;
        $this->act('build', ['type' => 'windmill', 'x' => 6, 'y' => 4]);
        $this->act('build', ['type' => 'chicken_coop', 'x' => 8, 'y' => 4]);
    }

    public function testRecipesFollowLevelOutputs(): void
    {
        self::assertSame(
            ['inputs' => ['corn' => 10], 'outputs' => ['chicken_feed' => 20], 'duration' => 600, 'xp' => 3],
            Production::recipe($this->data, 'windmill', 1),
        );
        self::assertSame(['corn' => 20], Production::recipe($this->data, 'windmill', 2)['inputs']);
        self::assertSame(
            ['inputs' => ['chicken_feed' => 21], 'outputs' => ['egg' => 21, 'dung' => 7], 'duration' => 1800, 'xp' => 5],
            Production::recipe($this->data, 'chicken_coop', 1),
        );
    }

    public function testInputsRoundUp(): void
    {
        $raw = $this->data->toArray();
        $raw['buildings']['windmill']['production']['inputs_per_main_unit'] = ['corn' => 0.33, 'wheat' => 1.1];
        $data = GameData::fromArray($raw);

        // Level 1 makes 20: 20 x 0.33 = 6.6 -> 7.
        self::assertSame(7, Production::recipe($data, 'windmill', 1)['inputs']['corn']);
        // Level 5 makes 90: 90 x 1.1 is 99.000000000000014 in floats, must stay 99.
        self::assertSame(99, Production::recipe($data, 'windmill', 5)['inputs']['wheat']);
    }

    public function testStartTakesInputsAndStartsJob(): void
    {
        $this->state['barn']['items'] = ['corn' => 15];

        $this->act('start_production', ['id' => 'b4']);

        self::assertSame(['corn' => 5], $this->state['barn']['items']);
        self::assertSame([
            'product' => 'chicken_feed',
            'started_at' => self::NOW,
            'duration' => 600,
            'outputs' => ['chicken_feed' => 20],
        ], $this->building('b4')['job']);
    }

    public function testStartRules(): void
    {
        $this->state['barn']['items'] = ['corn' => 9];
        $error = $this->assertGameError('not_enough_items', 'start_production', ['id' => 'b4']);
        self::assertSame(['missing' => ['corn' => 1]], $error->details);

        $this->assertGameError('not_a_production_building', 'start_production', ['id' => 'b2']);
        $this->assertGameError('not_a_production_building', 'start_production', ['id' => 'b1']);
        $this->assertGameError('not_a_field', 'plant', ['id' => 'b4', 'crop' => 'corn']);

        $this->state['barn']['items'] = ['corn' => 20];
        $this->act('start_production', ['id' => 'b4']);
        $this->assertGameError('busy', 'start_production', ['id' => 'b4']);
        $this->assertGameError('busy', 'upgrade', ['id' => 'b4']);
        $this->assertGameError('busy', 'demolish', ['id' => 'b4']);
    }

    public function testCollectAfterDuration(): void
    {
        $this->state['barn']['items'] = ['corn' => 10];
        $this->act('start_production', ['id' => 'b4']);

        $error = $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], 599);
        self::assertSame(['remaining' => 1], $error->details);

        $this->act('collect', ['id' => 'b4'], 600);

        self::assertSame(['chicken_feed' => 20], $this->state['barn']['items']);
        self::assertSame(3, $this->state['xp']);
        self::assertArrayNotHasKey('job', $this->building('b4'));
        $this->assertGameError('nothing_to_collect', 'collect', ['id' => 'b4'], 1200);
    }

    public function testFullChainCornToEggs(): void
    {
        $this->state['barn']['items'] = ['corn' => 20];
        $this->act('start_production', ['id' => 'b4']);
        $this->act('collect', ['id' => 'b4'], 600);
        $this->act('start_production', ['id' => 'b4'], 600);
        $this->act('collect', ['id' => 'b4'], 1200);
        self::assertSame(['chicken_feed' => 40], $this->state['barn']['items']);

        $this->act('start_production', ['id' => 'b5'], 1200);
        $this->act('collect', ['id' => 'b5'], 1200 + 1800);

        self::assertSame(['chicken_feed' => 19, 'egg' => 21, 'dung' => 7], $this->state['barn']['items']);
        self::assertSame(3 + 3 + 5, $this->state['xp']);
    }

    public function testCollectWhileOfflineAndAccelerated(): void
    {
        $this->state['settings']['speed'] = 60;
        $this->state['barn']['items'] = ['chicken_feed' => 21];
        $this->act('start_production', ['id' => 'b5']);

        $this->assertGameError('not_ready', 'collect', ['id' => 'b5'], 29);
        $this->act('collect', ['id' => 'b5'], 30);

        self::assertSame(['egg' => 21, 'dung' => 7], $this->state['barn']['items']);
    }

    public function testCollectRefusedIfAnyOutputDoesNotFit(): void
    {
        $this->state['barn']['items'] = ['chicken_feed' => 21, 'dung' => 195];
        $this->act('start_production', ['id' => 'b5']);

        $error = $this->assertGameError('barn_full', 'collect', ['id' => 'b5'], 1800);

        self::assertSame(['item' => 'dung', 'free' => 5, 'needed' => 7], $error->details);
        self::assertArrayHasKey('job', $this->building('b5'), 'eggs wait in the coop');
    }

    public function testOutputsAreFixedAtStart(): void
    {
        $this->state['barn']['items'] = ['corn' => 10];
        $this->act('start_production', ['id' => 'b4']);

        // Even if the building level changed (admin edit), the running job keeps its outputs.
        $this->state['buildings'][3]['level'] = 2;
        $this->act('collect', ['id' => 'b4'], 600);

        self::assertSame(['chicken_feed' => 20], $this->state['barn']['items']);
    }

    public function testUpgradedWindmillMakesMore(): void
    {
        $this->state['level'] = 11;
        $this->state['cash'] = 50_000;
        $this->act('upgrade', ['id' => 'b4']);
        $this->state['barn']['items'] = ['corn' => 20];

        $this->act('start_production', ['id' => 'b4']);
        $this->act('collect', ['id' => 'b4'], 600);

        self::assertSame(['chicken_feed' => 40], $this->state['barn']['items']);
    }

    public function testHarvestDoesNotWorkOnProductionBuildings(): void
    {
        $this->state['barn']['items'] = ['corn' => 10];
        $this->act('start_production', ['id' => 'b4']);

        $this->assertGameError('nothing_to_harvest', 'harvest', ['id' => 'b4'], 600);
        $this->assertGameError('nothing_to_collect', 'collect', ['id' => 'b2'], 600);
    }

    public function testViewShowsProductionJobs(): void
    {
        $this->state['barn']['items'] = ['corn' => 10];
        $this->act('start_production', ['id' => 'b4']);

        $view = $this->decode($this->engine->view($this->state, self::NOW + 600));

        self::assertSame(['ready_at' => self::NOW + 600, 'ready' => true], $view['jobs']['b4']);
        self::assertSame(['corn' => 10], $view['production']['b4']['inputs']);
        self::assertSame(['egg' => 21, 'dung' => 7], $view['production']['b5']['outputs']);
        self::assertArrayNotHasKey('b2', $view['production'], 'fields have no recipe');
    }
}
