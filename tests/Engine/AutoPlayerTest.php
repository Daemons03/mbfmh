<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\AutoPlayer;
use FermeTranquille\Engine\Farm;

/**
 * Real bots: a turn of AutoPlayer plays through the engine like a player.
 */
final class AutoPlayerTest extends EngineTestCase
{
    private function turn(int $elapsed = 0): array
    {
        $turn = (new AutoPlayer($this->engine, $this->data))->turn($this->state, self::NOW + $elapsed);
        $this->state = $turn['state'];

        return array_column($turn['actions'], 0);
    }

    public function testHarvestsThenPlantsAgain(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $actions = $this->turn(300);

        self::assertContains('harvest', $actions);
        self::assertGreaterThan(0, $this->state['xp'] + ($this->state['level'] - 1), 'harvest XP (its corn may already be delivered)');
        self::assertArrayHasKey('job', $this->building('b2'), 'planted again');
        self::assertArrayHasKey('job', $this->building('b3'), 'the free field too');
    }

    public function testDeliversWhatItCanAndDropsWhatItCannotMake(): void
    {
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));
        $this->state['contracts'][0]['items'] = ['corn' => 5];
        $this->state['contracts'][1]['items'] = ['milk' => 5]; // no cowshed
        $this->state['barn']['items'] = ['corn' => 50];
        [$deliverable, $impossible] = [$this->state['contracts'][0]['id'], $this->state['contracts'][1]['id']];

        $this->turn();

        $ids = array_column($this->state['contracts'], 'id');
        self::assertNotContains($deliverable, $ids, 'delivered');
        self::assertNotContains($impossible, $ids, 'discarded');
        self::assertGreaterThan(0, $this->state['market_xp']);
    }

    public function testSellsWhatCrowdsTheBarn(): void
    {
        $this->state['barn']['items'] = ['wheat' => 190]; // 200 a product
        $cash = $this->state['cash'];

        $actions = $this->turn();

        self::assertContains('sell_barn', $actions);
        self::assertLessThanOrEqual(100, $this->state['barn']['items']['wheat'] ?? 0);
        self::assertGreaterThan($cash - 200, $this->state['cash'] + 0);
    }

    public function testAWeekOfTurnsMakesTheFarmGrow(): void
    {
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));
        for ($turn = 1; $turn <= 7 * 96; $turn++) {
            $this->turn($turn * AutoPlayer::TURN);
        }
        $farm = new Farm($this->state, $this->data);

        self::assertGreaterThan(15, $farm->level());
        self::assertGreaterThan(10, count($farm->buildings()), 'it built');
        self::assertGreaterThan(500, $farm->marketXp(), 'it delivers contracts');
        self::assertGreaterThanOrEqual(0, $farm->resource('cash'));
    }
}
