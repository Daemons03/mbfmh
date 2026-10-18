<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

/**
 * Market upgrades: more contract slots, and cash / XP / materials added to
 * every delivered contract.
 */
final class MarketUpgradeTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));
    }

    public function testContractsUpgradeAddsASlot(): void
    {
        $this->state['materials']['bricks'] = 10;
        $this->state['materials']['tiles'] = 10;
        self::assertCount(6, $this->state['contracts']);

        $this->act('upgrade_market', ['track' => 'contracts']);

        self::assertSame(['contracts' => 1], $this->state['market_upgrades']);
        self::assertCount(7, $this->state['contracts'], 'filled right away');
        self::assertSame(0, $this->state['materials']['bricks']);
        self::assertSame(7, $this->engine->view($this->state, self::NOW)['market']['slots']);
    }

    public function testLevelsUseKeysOnceBricksAreNotEnough(): void
    {
        $levels = $this->data->toArray()['market_upgrades']['tracks']['contracts']['levels'];

        self::assertSame(['keys' => 100], $levels[5]['cost']);
        self::assertSame(6, $levels[5]['bonus']);
    }

    public function testEveryDeliveredContractGetsTheBonus(): void
    {
        // Cash: 10 + 5 (gold) = 15 points x 0.25 % = 3.75 % of the contract; XP, market XP and materials are flat.
        $this->state['market_upgrades'] = ['cash' => 2, 'cash_gold' => 1, 'xp' => 1, 'xp_gold' => 1, 'market_xp_gold' => 2, 'boards' => 1, 'scrolls' => 3];
        $contract = $this->state['contracts'][0];
        $contract['reward']['cash'] = 2000; // a bigger contract, so the percentage shows
        $this->state['contracts'][0] = $contract;
        $this->state['barn']['items'] = ['wildflower' => 100, 'corn' => 100];
        $materials = $this->state['materials'];

        $this->act('fulfill_contract', ['id' => $contract['id']]);

        self::assertSame(2000 + 2000 + 75, $this->state['cash']);
        self::assertSame($contract['reward']['xp'] + 2 + 3, $this->state['xp']);
        self::assertSame($contract['reward']['market_xp'] + 10, $this->state['market_xp']);
        self::assertSame($materials['boards'] + ($contract['reward']['materials']['boards'] ?? 0) + 2, $this->state['materials']['boards']);
        self::assertSame($materials['scrolls'] + ($contract['reward']['materials']['scrolls'] ?? 0) + 6, $this->state['materials']['scrolls']);

        $view = $this->engine->view($this->state, self::NOW);
        self::assertSame(3.75, $view['market']['bonus']['cash_percent']);
        self::assertSame(10, $view['market']['bonus']['market_xp']);
        self::assertEquals((object) ['boards' => 2, 'scrolls' => 6], $view['market']['bonus']['materials']);
    }

    public function testGoldTracksDoubleTheirPrice(): void
    {
        $this->state['gold'] = 300;
        $this->act('upgrade_market', ['track' => 'cash_gold']);
        $this->act('upgrade_market', ['track' => 'cash_gold']);

        self::assertSame(0, $this->state['gold'], '100 then 200 gold');
        $this->assertGameError('not_enough_resources', 'upgrade_market', ['track' => 'cash_gold']);
        self::assertSame(161_061_273_600, $this->data->toArray()['market_upgrades']['tracks']['market_xp_gold']['levels'][29]['cost']['gold']);
    }

    public function testRules(): void
    {
        $this->assertGameError('unknown_upgrade', 'upgrade_market', ['track' => 'gold']);
        $this->assertGameError('not_enough_resources', 'upgrade_market', ['track' => 'cash']);

        $this->state['market_upgrades'] = ['contracts' => 6];
        $this->state['materials']['keys'] = 1_000;
        $this->assertGameError('max_level', 'upgrade_market', ['track' => 'contracts']);
    }

    public function testWorksOnANormalizedState(): void
    {
        $this->state['materials']['bricks'] = 20;
        $this->state['materials']['tiles'] = 20;
        $this->act('upgrade_market', ['track' => 'cash']);
        $state = $this->engine->normalize($this->state);

        $next = $this->decode($this->engine->apply($state, 'upgrade_market', ['track' => 'contracts'], self::NOW));

        self::assertSame(['contracts' => 1, 'cash' => 1], $next['market_upgrades']);
    }

    public function testDeliveriesRaiseTheMarketLevel(): void
    {
        $this->state['barn']['items'] = ['wildflower' => 200, 'corn' => 200];
        self::assertSame(['level' => 1, 'xp' => 0, 'needed' => 1800], $this->engine->view($this->state, self::NOW)['market']['level']);

        $contract = $this->state['contracts'][0];
        $this->act('fulfill_contract', ['id' => $contract['id']]);
        self::assertSame($contract['reward']['market_xp'], $this->state['market_xp']);

        $this->state['market_xp'] = 7_205; // level 3 starts at 1 800 x 2² = 7 200
        self::assertSame(['level' => 3, 'xp' => 5, 'needed' => 9_000], $this->engine->view($this->state, self::NOW)['market']['level']);

        $this->state['market_xp'] = 38_784_880; // the player's market, level 147
        self::assertSame(147, $this->engine->view($this->state, self::NOW)['market']['level']['level']);
    }

    public function testOldContractsGetTheirMarketXpAtDelivery(): void
    {
        $contract = $this->state['contracts'][0];
        unset($contract['reward']['market_xp']);
        $this->state['contracts'][0] = $contract;
        $this->state['barn']['items'] = ['wildflower' => 200, 'corn' => 200];

        $this->act('fulfill_contract', ['id' => $contract['id']]);

        self::assertSame(\FermeTranquille\Engine\Market::marketXpFor($this->data, $contract['reward']['cash']), $this->state['market_xp']);
    }
}
