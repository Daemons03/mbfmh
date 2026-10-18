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
        $this->state['market_upgrades'] = ['cash' => 2, 'xp' => 1, 'boards' => 1, 'scrolls' => 3];
        $contract = $this->state['contracts'][0];
        $this->state['barn']['items'] = ['wildflower' => 100, 'corn' => 100];
        $materials = $this->state['materials'];

        $this->act('fulfill_contract', ['id' => $contract['id']]);

        self::assertSame(2000 + $contract['reward']['cash'] + 10, $this->state['cash']);
        self::assertSame($contract['reward']['xp'] + 2, $this->state['xp']);
        self::assertSame($materials['boards'] + ($contract['reward']['materials']['boards'] ?? 0) + 2, $this->state['materials']['boards']);
        self::assertSame($materials['scrolls'] + ($contract['reward']['materials']['scrolls'] ?? 0) + 6, $this->state['materials']['scrolls']);

        $view = $this->engine->view($this->state, self::NOW);
        self::assertSame(10, $view['market']['bonus']['cash']);
        self::assertEquals((object) ['boards' => 2, 'scrolls' => 6], $view['market']['bonus']['materials']);
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
        self::assertSame(['level' => 1, 'xp' => 0, 'needed' => 40], $this->engine->view($this->state, self::NOW)['market']['level']);

        $this->act('fulfill_contract', ['id' => $this->state['contracts'][0]['id']]);
        self::assertSame(10, $this->state['market_xp'], 'base market XP per contract');

        $this->state['market_upgrades'] = ['market_xp' => 2]; // +3
        $this->act('fulfill_contract', ['id' => $this->state['contracts'][1]['id']]);
        self::assertSame(23, $this->state['market_xp']);

        $this->state['market_xp'] = 155;
        $view = $this->engine->view($this->state, self::NOW)['market'];
        self::assertSame(['level' => 3, 'xp' => 5, 'needed' => 170], $view['level']);
        self::assertSame(13, $view['xp_per_contract']);
    }
}
