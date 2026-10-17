<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Market;
use FermeTranquille\Engine\Production;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Animals: 10 of their own feed + cash per run, fixed duration, product + dung.
 * Values from the original game (player's screenshots).
 */
final class AnimalTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200; // below max level, so XP is kept
        $this->state['cash'] = 100_000_000;
        $this->state['materials']['buckets'] = 1_000; // sheep pen level 1 costs 100 buckets
        $this->state['buildings'][0]['level'] = 25; // 1 400 places per product
    }

    /**
     * @return iterable<string, array{string, string, int, int, string, int, int, int}>
     */
    public static function animals(): iterable
    {
        // type, product, quantity, dung, feed, cash, duration, xp
        yield 'chickens' => ['chicken_coop', 'egg', 21, 7, 'chicken_feed', 4, 390, 5];
        yield 'pigs' => ['pigsty', 'pig', 130, 35, 'pig_feed', 24, 4200, 50];
        yield 'cows' => ['cowshed', 'milk', 230, 100, 'cattle_feed', 185, 25200, 300];
        yield 'goats' => ['goat_stable', 'goat_cheese', 160, 50, 'goat_feed', 43, 7200, 85];
        yield 'ducks' => ['duck_coop', 'feather', 180, 65, 'duck_feed', 68, 10800, 130];
        yield 'rabbits' => ['rabbit_hutch', 'gray_wool', 80, 21, 'rabbit_feed', 32, 2700, 30];
        yield 'sheep' => ['sheep_pen', 'sheep_wool', 180, 65, 'sheep_feed', 32, 3600, 60];
    }

    #[DataProvider('animals')]
    public function testFeedThenCollect(string $type, string $product, int $quantity, int $dung, string $feed, int $cash, int $duration, int $xp): void
    {
        $this->act('build', ['type' => $type, 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = [$feed => 10];
        $cashBefore = $this->state['cash'];

        $this->act('start_production', ['id' => 'b4']);
        self::assertSame([], $this->state['barn']['items'], 'feed consumed');
        self::assertSame($cashBefore - $cash, $this->state['cash']);

        $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], $duration - 1);
        $this->act('collect', ['id' => 'b4'], $duration);

        self::assertSame([$product => $quantity, 'dung' => $dung], $this->state['barn']['items']);
        self::assertSame($xp, $this->state['xp']);
    }

    public function testEachSpeciesNeedsItsOwnFeed(): void
    {
        $this->act('build', ['type' => 'pigsty', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['chicken_feed' => 50];

        $error = $this->assertGameError('not_enough_items', 'start_production', ['id' => 'b4']);
        self::assertSame(['missing' => ['pig_feed' => 10]], $error->details);
    }

    public function testCowshedNeedsABiggerBarn(): void
    {
        $this->state['buildings'][0]['level'] = 1;
        $this->act('build', ['type' => 'cowshed', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['cattle_feed' => 10];

        $error = $this->assertGameError('barn_too_small', 'start_production', ['id' => 'b4']);
        self::assertSame(['item' => 'milk', 'needed' => 230, 'capacity' => 200], $error->details);

        $this->act('upgrade', ['id' => 'b1']); // 250 places
        $this->act('start_production', ['id' => 'b4']);
        self::assertArrayHasKey('job', $this->building('b4'));
    }

    public function testRabbitsGiveWhiteWoolFromLevel5(): void
    {
        $this->act('build', ['type' => 'rabbit_hutch', 'x' => 6, 'y' => 4]);
        $this->state['buildings'][3]['level'] = 5;
        $this->state['barn']['items'] = ['rabbit_feed' => 10];

        $this->act('start_production', ['id' => 'b4']);
        $this->act('collect', ['id' => 'b4'], 2700);

        self::assertSame(['gray_wool' => 120, 'white_wool' => 10, 'dung' => 31], $this->state['barn']['items']);
    }

    public function testWindmillMakesEveryFeedFromTheMatchingCrop(): void
    {
        $this->act('build', ['type' => 'windmill', 'x' => 6, 'y' => 4]);
        $pairs = ['chicken_feed' => 'corn', 'pig_feed' => 'cabbage', 'cattle_feed' => 'wheat', 'goat_feed' => 'dandelion',
            'duck_feed' => 'lettuce', 'rabbit_feed' => 'carrot', 'sheep_feed' => 'barley'];

        $elapsed = 0;
        foreach ($pairs as $feed => $crop) {
            $recipe = Production::recipe($this->data, 'windmill', 1, $feed);
            $this->state['barn']['items'] = [$crop => $recipe['inputs'][$crop]];
            $this->act('start_production', ['id' => 'b4', 'recipe' => $feed], $elapsed);
            $elapsed += $recipe['duration'];
            $this->act('collect', ['id' => 'b4'], $elapsed);
            self::assertSame([$feed => 10], $this->state['barn']['items'], $feed);
            self::assertSame($this->data->item($crop)['unlock_level'], $this->data->item($feed)['unlock_level']);
        }
    }

    public function testAnimalProductsJoinTheMarket(): void
    {
        $this->state['level'] = 14;
        $this->act('build', ['type' => 'cowshed', 'x' => 6, 'y' => 4]);

        $eligible = (new Market($this->data))->eligibleItems(new Farm($this->state, $this->data));

        self::assertContains('milk', $eligible);
        self::assertNotContains('goat_cheese', $eligible, 'no goat stable');
    }

    public function testUnlockLevelsAreRequired(): void
    {
        $this->state['level'] = 99;
        $this->assertGameError('level_too_low', 'build', ['type' => 'sheep_pen', 'x' => 6, 'y' => 4]);
    }
}
