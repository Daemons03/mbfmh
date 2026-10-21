<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Market;

final class MarketTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['market_seed'] = 'test-seed';
        $this->resolve();
    }

    public function testResolveFillsAllSlotsOnce(): void
    {
        self::assertCount(6, $this->state['contracts']);
        self::assertSame(['c1', 'c2', 'c3', 'c4', 'c5', 'c6'], array_column($this->state['contracts'], 'id'));
        self::assertSame(7, $this->state['next_contract']);

        $before = $this->state;
        $this->resolve(3600);
        self::assertSame($before, $this->state, 'full market: nothing to do');
    }

    public function testContractsRespectMarketRules(): void
    {
        $market = $this->data->market();
        foreach ($this->manyContracts(200) as $contract) {
            $count = count($contract['items']);
            self::assertGreaterThanOrEqual($market['products_per_contract'][0], $count);
            self::assertLessThanOrEqual(2, $count, 'only 2 products unlocked at level 1');

            $cash = 0.0;
            foreach ($contract['items'] as $item => $quantity) {
                self::assertContains($item, ['wildflower', 'corn']);
                self::assertGreaterThanOrEqual(3, $quantity, 'level 1: 3 to 10 of each');
                self::assertLessThanOrEqual(10, $quantity);
                $cash += $quantity * $this->data->item($item)['market_price'];
            }
            self::assertSame((int) round($cash), $contract['reward']['cash']);
            self::assertSame(max(1, (int) round($cash * $market['xp_per_cash'])), $contract['reward']['xp']);
            self::assertSame(max(1, (int) round(0.055 * $contract['reward']['cash'] ** 0.87)), $contract['reward']['market_xp']);

            self::assertCount(1, $contract['reward']['materials'], 'always one material');
            foreach ($contract['reward']['materials'] as $material => $quantity) {
                self::assertArrayHasKey($material, $market['material_reward']['materials']);
                self::assertSame(1, $quantity, 'small contracts: 1');
            }
        }
    }

    public function testBiggerFarmsGetBiggerContracts(): void
    {
        $market = new \FermeTranquille\Engine\Market($this->data);
        self::assertSame([3, 10], $market->quantityRange(1));
        self::assertSame([57, 190], $market->quantityRange(151), 'about 100 at level 150');

        // Market XP of the real contracts the formula comes from.
        self::assertSame(29, \FermeTranquille\Engine\Market::marketXpFor($this->data, 1326));
        self::assertEqualsWithDelta(380, \FermeTranquille\Engine\Market::marketXpFor($this->data, 17935), 110);
        self::assertEqualsWithDelta(498, \FermeTranquille\Engine\Market::marketXpFor($this->data, 34563), 110);
    }

    public function testMaterialsFollowTheContractValue(): void
    {
        $this->state['level'] = 151;
        $this->state['contracts'] = [];
        $this->state['barn']['items'] = [];
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));

        foreach ($this->state['contracts'] as $contract) {
            [$material, $quantity] = [array_key_first($contract['reward']['materials']), current($contract['reward']['materials'])];
            $factor = $this->data->market()['material_reward']['materials'][$material];
            self::assertSame(max(1, (int) round($contract['reward']['cash'] * 0.01 * $factor)), $quantity);
        }
    }

    public function testGenerationIsDeterministic(): void
    {
        $again = $this->engine->newState();
        $again['market_seed'] = 'test-seed';
        $again = $this->decode($this->engine->resolve($again, self::NOW + 999));
        self::assertSame($this->state['contracts'], $again['contracts'], 'same seed, same contracts');

        $other = $this->engine->newState();
        $other['market_seed'] = 'other-seed';
        $other = $this->decode($this->engine->resolve($other, self::NOW));
        self::assertNotSame($this->state['contracts'], $other['contracts']);
    }

    public function testLegacyStateGetsASeed(): void
    {
        $legacy = $this->engine->newState();
        unset($legacy['contracts']);

        $resolved = $this->decode($this->engine->resolve($legacy, self::NOW));

        self::assertSame(16, strlen($resolved['market_seed']));
        self::assertCount(6, $resolved['contracts']);
    }

    public function testHigherLevelUnlocksMoreProducts(): void
    {
        $this->state['level'] = 100;
        $items = [];
        foreach ($this->manyContracts(100) as $contract) {
            $items += $contract['items'];
        }

        self::assertContains('barley', array_keys($items));
        self::assertNotContains('egg', array_keys($items), 'no coop yet');
    }

    public function testOwnedProductionBuildingsAddTheirOutputs(): void
    {
        $this->act('build', ['type' => 'chicken_coop', 'x' => 6, 'y' => 4]);

        $farm = new Farm($this->state, $this->data);
        self::assertSame(['wildflower', 'corn', 'egg'], (new Market($this->data))->eligibleItems($farm), 'dung is not sold at the market');
    }

    public function testOnlyMarketableProductsAreRequested(): void
    {
        $this->state['level'] = 200;
        $this->state['cash'] = 100_000_000;
        $this->state['materials']['mallets'] = 100;
        $this->state['expansions'] = 16;
        foreach (['windmill' => [6, 4], 'silo' => [8, 4], 'composter' => [11, 4], 'dairy' => [6, 6], 'beehive' => [9, 6], 'apple_orchard' => [10, 6]] as $type => [$x, $y]) {
            $this->act('build', ['type' => $type, 'x' => $x, 'y' => $y]);
        }

        $eligible = (new Market($this->data))->eligibleItems(new Farm($this->state, $this->data));

        foreach (['chicken_feed', 'fertilizer', 'humus', 'leaves', 'nectar', 'dung', 'cream', 'butter'] as $never) {
            self::assertNotContains($never, $eligible);
        }
        foreach (['apple', 'forest_honey', 'flower_honey', 'barley'] as $sold) {
            self::assertContains($sold, $eligible);
        }
    }

    public function testFulfillPaysAndRefillsTheSlot(): void
    {
        $contract = $this->state['contracts'][2];
        $this->state['barn']['items'] = ['wildflower' => 100, 'corn' => 100];

        $this->act('fulfill_contract', ['id' => $contract['id']]);

        $expectedBarn = ['wildflower' => 100, 'corn' => 100];
        foreach ($contract['items'] as $item => $quantity) {
            $expectedBarn[$item] -= $quantity;
        }
        self::assertSame($expectedBarn, $this->state['barn']['items']);
        self::assertSame(2000 + $contract['reward']['cash'], $this->state['cash']);
        self::assertLessThan($this->data->xpForLevel(1), $contract['reward']['xp'], 'no level-up in this test');
        self::assertSame($contract['reward']['xp'], $this->state['xp']);
        foreach ($contract['reward']['materials'] ?? [] as $material => $quantity) {
            self::assertSame($quantity, $this->state['materials'][$material]);
        }

        $replacement = $this->state['contracts'][2];
        self::assertSame('c7', $replacement['id'], 'same slot, new contract');
        self::assertArrayNotHasKey('wait', $replacement, 'available right away');
    }

    public function testFulfillGivesMaterials(): void
    {
        foreach ($this->manyContracts(50) as $contract) {
            if (isset($contract['reward']['materials'])) {
                $this->state['contracts'][0] = $contract;
                break;
            }
        }
        $this->state['barn']['items'] = ['wildflower' => 100, 'corn' => 100];

        $this->act('fulfill_contract', ['id' => $this->state['contracts'][0]['id']]);

        self::assertGreaterThan(0, array_sum($this->state['materials']));
    }

    public function testFulfillNeedsEveryProduct(): void
    {
        $contract = $this->state['contracts'][0];
        $item = array_key_first($contract['items']);
        $this->state['barn']['items'] = [$item => $contract['items'][$item] - 1];

        $error = $this->assertGameError('not_enough_items', 'fulfill_contract', ['id' => $contract['id']]);

        self::assertSame(1, $error->details['missing'][$item]);
    }

    public function testDiscardReplacesWithAWaitingContract(): void
    {
        $this->act('discard_contract', ['id' => 'c1'], 100);

        $replacement = $this->state['contracts'][0];
        self::assertSame('c7', $replacement['id']);
        self::assertSame(['started_at' => self::NOW + 100, 'duration' => 600], $replacement['wait']);

        $this->state['barn']['items'] = ['wildflower' => 100, 'corn' => 100];
        $this->assertGameError('contract_waiting', 'fulfill_contract', ['id' => 'c7'], 699);
        $this->assertGameError('contract_waiting', 'discard_contract', ['id' => 'c7'], 699);
        $this->act('fulfill_contract', ['id' => 'c7'], 700);

        self::assertSame('c8', $this->state['contracts'][0]['id']);
    }

    public function testDiscardDelayFollowsSpeed(): void
    {
        $this->state['settings']['speed'] = 10;
        $this->act('discard_contract', ['id' => 'c1']);
        $this->state['barn']['items'] = ['wildflower' => 100, 'corn' => 100];

        $this->assertGameError('contract_waiting', 'fulfill_contract', ['id' => 'c7'], 59);
        $this->act('fulfill_contract', ['id' => 'c7'], 60);
    }

    public function testViewListsWaitingContracts(): void
    {
        $this->act('discard_contract', ['id' => 'c3']);

        $view = $this->decode($this->engine->view($this->state, self::NOW + 10));

        self::assertSame(['c7' => ['available_at' => self::NOW + 600, 'available' => false]], $view['contracts']);
    }

    public function testUnknownContract(): void
    {
        $this->assertGameError('unknown_contract', 'fulfill_contract', ['id' => 'c99']);
        $this->assertGameError('unknown_contract', 'discard_contract', ['id' => 'c99']);
    }

    private function resolve(int $elapsed = 0): void
    {
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + $elapsed));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manyContracts(int $count): array
    {
        $farm = new Farm($this->state, $this->data);
        $market = new Market($this->data);
        $clock = new Clock(self::NOW);
        $contracts = [];
        for ($i = 0; $i < $count; $i++) {
            $contracts[] = $this->decode($market->generate($farm, $clock, waiting: false));
        }

        return $contracts;
    }
}
