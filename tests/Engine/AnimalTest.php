<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Market;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V2.1 animals: every animal eats the windmill feed and gives a product + dung.
 */
final class AnimalTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 250;
        $this->state['cash'] = 100_000_000;
        $this->state['materials']['buckets'] = 1_000; // sheep pen level 1 costs 100 buckets
        $this->state['expansions'] = 9;
    }

    /**
     * @return iterable<string, array{string, string, int, int, int}>
     */
    public static function animals(): iterable
    {
        // type, product, quantity, dung, feed
        yield 'pigsty' => ['pigsty', 'pig', 130, 35, 26];
        yield 'cowshed' => ['cowshed', 'milk', 230, 100, 23];
        yield 'goat stable' => ['goat_stable', 'cheese', 160, 50, 24];
        yield 'duck coop' => ['duck_coop', 'feather', 180, 65, 27];
        yield 'rabbit hutch' => ['rabbit_hutch', 'gray_wool', 80, 21, 24];
        yield 'sheep pen' => ['sheep_pen', 'sheep_wool', 180, 65, 27];
    }

    #[DataProvider('animals')]
    public function testFeedThenCollect(string $type, string $product, int $quantity, int $dung, int $feed): void
    {
        $this->bigBarn();
        $this->act('build', ['type' => $type, 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['chicken_feed' => $feed];

        $this->act('start_production', ['id' => 'b4']);
        self::assertSame([], $this->state['barn']['items'], 'feed consumed');

        $duration = $this->data->building($type)['production']['duration'];
        $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], $duration - 1);
        $this->act('collect', ['id' => 'b4'], $duration);

        self::assertSame([$product => $quantity, 'dung' => $dung], $this->state['barn']['items']);
    }

    public function testCowshedNeedsABiggerBarn(): void
    {
        $this->act('build', ['type' => 'cowshed', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['chicken_feed' => 23];

        $error = $this->assertGameError('barn_too_small', 'start_production', ['id' => 'b4']);
        self::assertSame(['item' => 'milk', 'needed' => 230, 'capacity' => 200], $error->details);

        $this->act('upgrade', ['id' => 'b1']); // 250 places
        $this->act('start_production', ['id' => 'b4']);
        self::assertArrayHasKey('job', $this->building('b4'));
    }

    public function testRabbitsGiveWhiteWoolFromLevel5(): void
    {
        $this->bigBarn();
        $this->act('build', ['type' => 'rabbit_hutch', 'x' => 6, 'y' => 4]);
        $this->state['buildings'][3]['level'] = 5;
        $this->state['barn']['items'] = ['chicken_feed' => 100];

        $this->act('start_production', ['id' => 'b4']);
        $this->act('collect', ['id' => 'b4'], 7200);

        self::assertSame(['chicken_feed' => 64, 'gray_wool' => 120, 'white_wool' => 10, 'dung' => 31], $this->state['barn']['items']);
    }

    public function testAnimalProductsJoinTheMarket(): void
    {
        $this->state['level'] = 14;
        $this->act('build', ['type' => 'cowshed', 'x' => 6, 'y' => 4]);

        $eligible = (new Market($this->data))->eligibleItems(new Farm($this->state, $this->data));

        self::assertContains('milk', $eligible);
        self::assertNotContains('cheese', $eligible, 'no goat stable');
    }

    public function testUnlockLevelsAreRequired(): void
    {
        $this->state['level'] = 99;
        $this->assertGameError('level_too_low', 'build', ['type' => 'sheep_pen', 'x' => 6, 'y' => 4]);
    }

    private function bigBarn(): void
    {
        $this->state['buildings'][0]['level'] = 25; // 1 400 places per product
    }
}
