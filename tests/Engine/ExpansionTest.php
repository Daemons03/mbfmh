<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

final class ExpansionTest extends EngineTestCase
{
    public function testBuyWithGold(): void
    {
        $this->state['level'] = 4;

        $this->act('buy_expansion', ['option' => 0]);

        self::assertSame(7, $this->state['expansions']);
        self::assertSame(0, $this->state['gold']);
        self::assertSame(2000, $this->state['cash']);
    }

    public function testBuyWithCash(): void
    {
        $this->state['level'] = 4;

        $this->act('buy_expansion', ['option' => 1]);

        self::assertSame(7, $this->state['expansions']);
        self::assertSame(50, $this->state['gold']);
        self::assertSame(1600, $this->state['cash']);
    }

    public function testCashOptionCanRequireMaterials(): void
    {
        $this->state['level'] = 14;
        $this->state['expansions'] = 10;
        $this->state['cash'] = 50_000;

        $error = $this->assertGameError('not_enough_resources', 'buy_expansion', ['option' => 1]);
        self::assertSame(['missing' => ['nails' => 5]], $error->details);

        $this->state['materials']['nails'] = 5;
        $this->act('buy_expansion', ['option' => 1]);

        self::assertSame(11, $this->state['expansions']);
        self::assertSame(8000, $this->state['cash']);
        self::assertSame(0, $this->state['materials']['nails']);
    }

    public function testBuyRules(): void
    {
        $this->assertGameError('level_too_low', 'buy_expansion', ['option' => 0]);

        $this->state['level'] = 4;
        $this->assertGameError('invalid_payload', 'buy_expansion', ['option' => 2]);
        $this->assertGameError('invalid_payload', 'buy_expansion', ['option' => -1]);
        $this->assertGameError('invalid_payload', 'buy_expansion', []);

        $this->state['gold'] = 49;
        $this->assertGameError('not_enough_resources', 'buy_expansion', ['option' => 0]);
    }

    public function testNewBlockBecomesBuildable(): void
    {
        $this->state['level'] = 4;
        $this->assertGameError('cell_locked', 'build', ['type' => 'field', 'x' => 8, 'y' => 8]);

        $this->act('buy_expansion', ['option' => 0]);
        $this->act('build', ['type' => 'field', 'x' => 8, 'y' => 8]);

        self::assertSame(['x' => 8, 'y' => 8], array_intersect_key($this->building('b4'), ['x' => 0, 'y' => 0]));
    }

    public function testGridGrowsInTheView(): void
    {
        $this->state['level'] = 10;
        $this->state['gold'] = 10_000;

        $this->act('buy_expansion', ['option' => 0]); // 7
        $this->act('buy_expansion', ['option' => 0]); // 8
        $grid = $this->decode($this->engine->view($this->state, self::NOW))['grid'];
        self::assertSame(12, $grid['side']);
        self::assertSame([1, 2], $grid['next_block'], 'block 9');

        $this->act('buy_expansion', ['option' => 0]); // 9: 3x3 full, next is in the 4th column
        $grid = $this->decode($this->engine->view($this->state, self::NOW))['grid'];
        self::assertSame(16, $grid['side']);
        self::assertSame([3, 0], $grid['next_block']);
        self::assertCount(9, $grid['owned_blocks']);
    }

    public function testCannotBuyPastTheLastExpansion(): void
    {
        $this->state['expansions'] = 55;
        $this->state['level'] = 200;

        $this->assertGameError('max_expansions', 'buy_expansion', ['option' => 0]);
    }
}
