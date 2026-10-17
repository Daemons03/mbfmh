<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Production;
use FermeTranquille\GameData;

/**
 * Production engine: per-level recipes, run and batch modes.
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

    public function testRecipesComeFromTheLevel(): void
    {
        self::assertSame(
            ['id' => 'egg', 'inputs' => ['chicken_feed' => 10], 'cost' => ['cash' => 4], 'outputs' => ['egg' => 21, 'dung' => 7],
                'duration' => 390, 'xp' => 5],
            Production::recipe($this->data, 'chicken_coop', 1),
        );
        self::assertSame(['egg' => 42, 'dung' => 14], Production::recipe($this->data, 'chicken_coop', 12)['outputs']);
        self::assertCount(7, Production::recipes($this->data, 'windmill', 1), 'one feed per species');
    }

    public function testBatchCapacityFollowsTheLevel(): void
    {
        self::assertSame(2, Production::maxQuantity($this->data, 'windmill', 1, 'chicken_feed'), '20 feed = 2 batches of 10');
        self::assertSame(24, Production::maxQuantity($this->data, 'windmill', 12, 'chicken_feed'));
        self::assertSame(3, Production::maxQuantity($this->data, 'silo', 1, 'fertilizer'), '30 fertilizer = 3 batches of 10');
        self::assertSame(1, Production::maxQuantity($this->data, 'chicken_coop', 1, 'egg'), 'run buildings: one batch');
    }

    public function testScaledMultipliesEverything(): void
    {
        $recipe = Production::recipe($this->data, 'silo', 1);

        self::assertSame(
            ['inputs' => ['dung' => 630], 'cost' => ['cash' => 627], 'outputs' => ['fertilizer' => 30], 'duration' => 3 * 3360, 'xp' => 1800],
            Production::scaled($recipe, 3),
        );
    }

    public function testStartTakesInputsPaysAndStartsJob(): void
    {
        $this->state['barn']['items'] = ['chicken_feed' => 15];

        $this->act('start_production', ['id' => 'b5']);

        self::assertSame(['chicken_feed' => 5], $this->state['barn']['items']);
        self::assertSame(10_000 - 50 - 1500 - 4, $this->state['cash']);
        self::assertSame([
            'product' => 'egg',
            'quantity' => 1,
            'started_at' => self::NOW,
            'duration' => 390,
            'outputs' => ['egg' => 21, 'dung' => 7],
            'xp' => 5,
        ], $this->building('b5')['job']);
    }

    public function testWindmillRecipesAreTheGameValues(): void
    {
        self::assertSame(
            ['id' => 'chicken_feed', 'inputs' => ['corn' => 25], 'cost' => ['cash' => 2], 'outputs' => ['chicken_feed' => 10],
                'duration' => 180, 'xp' => 50],
            Production::recipe($this->data, 'windmill', 1, 'chicken_feed'),
        );
        $cattle = Production::recipe($this->data, 'windmill', 12, 'cattle_feed');
        self::assertSame([['wheat' => 58], ['cash' => 299], 2170], [$cattle['inputs'], $cattle['cost'], $cattle['duration']]);
    }

    public function testBatchJobLastsLongerAndMakesMore(): void
    {
        $this->state['barn']['items'] = ['corn' => 55];
        $cash = $this->state['cash'];

        $this->act('start_production', ['id' => 'b4', 'recipe' => 'chicken_feed', 'quantity' => 2]);

        self::assertSame(['corn' => 5], $this->state['barn']['items']);
        self::assertSame($cash - 4, $this->state['cash']);
        self::assertSame(['chicken_feed' => 20], $this->building('b4')['job']['outputs']);
        self::assertSame(360, $this->building('b4')['job']['duration']);
        $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], 359);
        $this->act('collect', ['id' => 'b4'], 360);
        self::assertSame(['corn' => 5, 'chicken_feed' => 20], $this->state['barn']['items']);
        // 2 batches x 50 XP = 100: level 1 needs 50, so level 2 with 50 XP left.
        self::assertSame([2, 50], [$this->state['level'], $this->state['xp']]);
    }

    public function testStartRules(): void
    {
        $this->state['barn']['items'] = ['chicken_feed' => 9];
        $error = $this->assertGameError('not_enough_items', 'start_production', ['id' => 'b5']);
        self::assertSame(['missing' => ['chicken_feed' => 1]], $error->details);

        $this->state['barn']['items'] = ['chicken_feed' => 10];
        $this->state['cash'] = 3;
        $error = $this->assertGameError('not_enough_resources', 'start_production', ['id' => 'b5']);
        self::assertSame(['missing' => ['cash' => 1]], $error->details);
        $this->state['cash'] = 10_000;

        $this->assertGameError('invalid_payload', 'start_production', ['id' => 'b4'], 0); // several recipes: must choose
        $this->assertGameError('unknown_recipe', 'start_production', ['id' => 'b4', 'recipe' => 'egg']);
        $this->state['barn']['items'] = ['corn' => 200];
        $error = $this->assertGameError('quantity_too_high', 'start_production', ['id' => 'b4', 'recipe' => 'chicken_feed', 'quantity' => 3]);
        self::assertSame(['max' => 2], $error->details);
        $this->assertGameError('quantity_too_high', 'start_production', ['id' => 'b5', 'quantity' => 2]);
        $this->assertGameError('invalid_payload', 'start_production', ['id' => 'b4', 'recipe' => 'chicken_feed', 'quantity' => 0]);

        $this->assertGameError('not_a_production_building', 'start_production', ['id' => 'b2']);
        $this->assertGameError('not_a_field', 'plant', ['id' => 'b4', 'crop' => 'corn']);

        $this->act('start_production', ['id' => 'b4', 'recipe' => 'chicken_feed']);
        $this->assertGameError('busy', 'start_production', ['id' => 'b4', 'recipe' => 'chicken_feed']);
        $this->assertGameError('busy', 'upgrade', ['id' => 'b4']);
        $this->assertGameError('busy', 'demolish', ['id' => 'b4']);
    }

    public function testFullChainCornToEggs(): void
    {
        $this->state['barn']['items'] = ['corn' => 25];
        $this->act('start_production', ['id' => 'b4', 'recipe' => 'chicken_feed']);
        $this->act('collect', ['id' => 'b4'], 180);
        $this->act('start_production', ['id' => 'b5'], 180);
        $this->act('collect', ['id' => 'b5'], 180 + 390);

        self::assertSame(['egg' => 21, 'dung' => 7], $this->state['barn']['items']);
        self::assertSame(['level' => 2, 'xp' => 50 + 5 - 50], ['level' => $this->state['level'], 'xp' => $this->state['xp']]);
    }

    public function testCollectWhileOfflineAndAccelerated(): void
    {
        $this->state['settings']['speed'] = 13;
        $this->state['barn']['items'] = ['chicken_feed' => 10];
        $this->act('start_production', ['id' => 'b5']);

        $this->assertGameError('not_ready', 'collect', ['id' => 'b5'], 29);
        $this->act('collect', ['id' => 'b5'], 30); // 390 / 13
        self::assertSame(['egg' => 21, 'dung' => 7], $this->state['barn']['items']);
    }

    public function testCollectRefusedIfAnyOutputDoesNotFit(): void
    {
        $this->state['barn']['items'] = ['chicken_feed' => 10, 'dung' => 195];
        $this->act('start_production', ['id' => 'b5']);

        $error = $this->assertGameError('barn_full', 'collect', ['id' => 'b5'], 390);

        self::assertSame(['item' => 'dung', 'free' => 5, 'needed' => 7], $error->details);
        self::assertArrayHasKey('job', $this->building('b5'), 'eggs wait in the coop');
    }

    public function testJobIsFixedAtStart(): void
    {
        $this->state['barn']['items'] = ['chicken_feed' => 10];
        $this->act('start_production', ['id' => 'b5']);

        // Even if the building level changed (admin edit), the running job keeps its outputs and XP.
        $this->setBuilding('b5', ['level' => 12]);
        $this->act('collect', ['id' => 'b5'], 390);

        self::assertSame(['egg' => 21, 'dung' => 7], $this->state['barn']['items']);
        self::assertSame(5, $this->state['xp']);
    }

    public function testLegacyJobsWithoutXpStillCollect(): void
    {
        $this->setBuilding('b5', ['job' => ['product' => 'egg', 'started_at' => self::NOW, 'duration' => 1800,
            'outputs' => ['egg' => 21, 'dung' => 7]]]);

        $this->act('collect', ['id' => 'b5'], 1800);

        self::assertSame(5, $this->state['xp']);
    }

    public function testHarvestAndCollectAreDistinct(): void
    {
        $this->state['barn']['items'] = ['chicken_feed' => 10];
        $this->act('start_production', ['id' => 'b5']);

        $this->assertGameError('nothing_to_harvest', 'harvest', ['id' => 'b5'], 390);
        $this->assertGameError('nothing_to_collect', 'collect', ['id' => 'b2'], 390);
    }

    public function testViewListsRecipesWithMaxQuantity(): void
    {
        $this->state['barn']['items'] = ['chicken_feed' => 10];
        $this->act('start_production', ['id' => 'b5']);

        $view = $this->decode($this->engine->view($this->state, self::NOW + 390));

        self::assertSame(['ready_at' => self::NOW + 390, 'ready' => true], $view['jobs']['b5']);
        self::assertSame('batch', $view['production']['b4']['mode']);
        self::assertSame(2, $view['production']['b4']['recipes']['cattle_feed']['max_quantity']);
        self::assertSame(['wheat' => 82], $view['production']['b4']['recipes']['cattle_feed']['inputs']);
        self::assertSame('run', $view['production']['b5']['mode']);
        self::assertArrayNotHasKey('b2', $view['production'], 'fields have no recipe');
    }

    public function testValidatorChecksRecipes(): void
    {
        $raw = $this->data->toArray();
        $raw['buildings']['windmill']['levels'][0]['max_output'] = 5;
        $raw['buildings']['silo']['levels'][0]['recipes']['fertilizer']['outputs'] = ['dung' => 1];
        $raw['buildings']['chicken_coop']['production']['mode'] = 'magic';
        $raw['buildings']['cowshed']['levels'][2]['recipes'] = [];

        $errors = implode("\n", GameData::validate($raw));

        self::assertStringContainsString('windmill.levels[0].recipes.chicken_feed: max_output is smaller than one batch', $errors);
        self::assertStringContainsString('silo.levels[0].recipes.fertilizer.outputs: must contain the main output fertilizer', $errors);
        self::assertStringContainsString('chicken_coop.production.mode: must be run, batch or instant', $errors);
        self::assertStringContainsString('cowshed.levels[2].recipes: must be a non-empty object', $errors);
    }

    public function testUnknownRecipeIsAGameError(): void
    {
        $this->expectException(GameError::class);
        Production::recipe($this->data, 'windmill', 1, 'gold_bars');
    }
}
