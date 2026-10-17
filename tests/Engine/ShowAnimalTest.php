<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\ShowAnimals;

/**
 * Show animals: daily tokens, chests, cards, levels, free activations.
 */
final class ShowAnimalTest extends EngineTestCase
{
    private ShowAnimals $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new ShowAnimals($this->data);
        $this->state['gold'] = 100_000;
        $this->setBuilding('b1', ['level' => 25]); // 1 400 places per product
    }

    public function testEveryFarmHasTheBuildingAtLevel1(): void
    {
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));

        self::assertSame(['id' => 'show_animal', 'type' => 'show_animal', 'level' => 1], $this->building('show_animal'));
        self::assertSame(1, $this->rules->slots(new \FermeTranquille\Engine\Farm($this->state, $this->data)));
    }

    public function testTwentyTokensOncePerDay(): void
    {
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));
        self::assertSame(20, $this->state['materials']['tokens']);

        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + 60));
        self::assertSame(20, $this->state['materials']['tokens'], 'same day');

        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + 86400));
        self::assertSame(40, $this->state['materials']['tokens']);
    }

    public function testBuyTokensWithGold(): void
    {
        $this->act('buy_tokens', ['packs' => 3]);

        self::assertSame(100_000 - 3000, $this->state['gold']);
        self::assertSame(20 + 30, $this->state['materials']['tokens']);
        $this->state['gold'] = 999;
        $this->assertGameError('not_enough_resources', 'buy_tokens', ['packs' => 1]);
    }

    public function testDrawRatesFollowTheGame(): void
    {
        $counts = ['blueprints' => 0, 'grey' => 0, 'green' => 0, 'blue' => 0, 'purple' => 0];
        $animals = $this->rules->config()['animals'];
        for ($i = 0; $i < 20_000; $i++) {
            $draw = $this->rules->draw(['seed' => 'rates', 'opened' => $i]);
            isset($draw['blueprints']) ? $counts['blueprints']++ : $counts[$animals[$draw['animal']]['rarity']]++;
        }

        // 21 animals per rarity: grey 63 %, green 27.3 %, blue 8.4 %, purple 2.1 %, blueprints 0.4 % (normalized)
        self::assertEqualsWithDelta(0.62, $counts['grey'] / 20_000, 0.02);
        self::assertEqualsWithDelta(0.27, $counts['green'] / 20_000, 0.02);
        self::assertEqualsWithDelta(0.083, $counts['blue'] / 20_000, 0.01);
        self::assertEqualsWithDelta(0.021, $counts['purple'] / 20_000, 0.005);
        self::assertEqualsWithDelta(0.004, $counts['blueprints'] / 20_000, 0.003);
    }

    public function testOneChestGivesACardOrBlueprints(): void
    {
        $this->act('open_chest', ['count' => 1]);

        self::assertSame(10, $this->state['materials']['tokens']);
        $draw = $this->state['show']['last_draw'];
        if (isset($draw['animal'])) {
            self::assertSame(['level' => 1, 'cards' => 0], $this->state['show']['animals'][$draw['animal']]);
            self::assertTrue($draw['new']);
        } else {
            self::assertSame(1, $this->state['materials']['blueprints']);
        }
    }

    public function testElevenChestsGiveElevenTimesTheSameDraw(): void
    {
        $this->state['materials']['tokens'] = 100;
        $this->state['show'] = ['seed' => 'eleven', 'opened' => 0, 'token_day' => date('Y-m-d', self::NOW), 'animals' => [], 'active' => []];
        $expected = $this->rules->draw($this->state['show']);

        $this->act('open_chest', ['count' => 11]);

        self::assertSame(0, $this->state['materials']['tokens']);
        self::assertSame($expected['animal'], $this->state['show']['last_draw']['animal']);
        // 1 card unlocks, 10 more: level 2 needs 5, level 3 needs 20 -> level 2 with 5 cards.
        self::assertSame(['level' => 2, 'cards' => 5], $this->state['show']['animals'][$expected['animal']]);
        $this->assertGameError('invalid_payload', 'open_chest', ['count' => 5]);
    }

    public function testCardsLevelUpToFive(): void
    {
        $show = ['animals' => []];
        $show = $this->rules->addCards($show, 'chicken_grey_1', 1 + 5 + 20 + 50 + 100 + 7);

        self::assertSame(['level' => 5, 'cards' => 7], $show['animals']['chicken_grey_1']);
    }

    public function testChickenQuantitiesMatchTheGame(): void
    {
        self::assertSame(['egg' => 10], $this->rules->outputs('chicken_grey_1', 1, 0));
        self::assertSame(['egg' => 582], $this->rules->outputs('chicken_purple_2', 1, 2));
        self::assertSame(['egg' => 102], $this->rules->outputs('chicken_green_3', 5, 1));
        self::assertSame(['egg' => 1745], $this->rules->outputs('chicken_purple_1', 5, 2));
    }

    public function testActivateThenCollect(): void
    {
        $this->state['show'] = ['animals' => ['chicken_blue_1' => ['level' => 1, 'cards' => 0]], 'active' => []];

        $this->act('start_show_animal', ['animal' => 'chicken_blue_1', 'duration' => 1]);
        self::assertSame(['egg' => 38], $this->state['show']['active']['chicken']['outputs']);
        $this->assertGameError('not_ready', 'collect_show_animal', ['species' => 'chicken'], 1199);
        $this->act('collect_show_animal', ['species' => 'chicken'], 1200);

        self::assertSame(['egg' => 38], $this->state['barn']['items']);
        self::assertSame([], $this->state['show']['active']);
    }

    public function testActivationRules(): void
    {
        $this->state['show'] = ['animals' => [
            'chicken_grey_1' => ['level' => 1, 'cards' => 0],
            'chicken_grey_2' => ['level' => 1, 'cards' => 0],
            'pig_grey_1' => ['level' => 1, 'cards' => 0],
        ], 'active' => []];

        $this->assertGameError('animal_not_owned', 'start_show_animal', ['animal' => 'cow_grey_1', 'duration' => 0]);
        $this->assertGameError('unknown_animal', 'start_show_animal', ['animal' => 'dragon', 'duration' => 0]);
        $this->assertGameError('invalid_payload', 'start_show_animal', ['animal' => 'chicken_grey_1', 'duration' => 3]);

        $this->act('start_show_animal', ['animal' => 'chicken_grey_1', 'duration' => 0]);
        $this->assertGameError('species_busy', 'start_show_animal', ['animal' => 'chicken_grey_2', 'duration' => 0]);
        $this->assertGameError('no_free_slot', 'start_show_animal', ['animal' => 'pig_grey_1', 'duration' => 0]);

        // Level 2 building: 2 animals at once.
        $this->setBuilding('show_animal', ['level' => 2]);
        $this->act('start_show_animal', ['animal' => 'pig_grey_1', 'duration' => 0]);
        self::assertCount(2, $this->state['show']['active']);
    }

    public function testWorksOnANormalizedState(): void
    {
        $this->state['show'] = ['animals' => ['chicken_grey_1' => ['level' => 1, 'cards' => 0]], 'active' => []];
        $normalized = $this->engine->normalize($this->state);

        $next = $this->engine->apply($normalized, 'start_show_animal', ['animal' => 'chicken_grey_1', 'duration' => 0], self::NOW);
        $view = $this->engine->view($this->engine->normalize($next), self::NOW + 390);

        self::assertTrue($view['show']['active']->chicken['ready']);
    }
}
