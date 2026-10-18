<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

/**
 * "Tout récolter": every ready field and production in one action.
 * Fields b2, b3 and chicken coop b4 at (6,4).
 */
final class CollectAllTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['cash'] = 10_000;
        $this->act('build', ['type' => 'chicken_coop', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['chicken_feed' => 10];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('plant', ['id' => 'b3', 'crop' => 'wildflower']);
        $this->act('start_production', ['id' => 'b4']);
    }

    public function testCollectsEverythingReady(): void
    {
        $this->act('collect_all', [], 390);

        self::assertSame(['corn' => 10, 'wildflower' => 7, 'egg' => 21, 'dung' => 7],
            $this->state['barn']['items']);
        foreach (['b2', 'b3', 'b4'] as $id) {
            self::assertArrayNotHasKey('job', $this->building($id));
        }
    }

    public function testSkipsWhatIsNotReady(): void
    {
        $this->act('collect_all', [], 120); // corn (2 min) and wildflowers ready, not the eggs

        self::assertArrayNotHasKey('job', $this->building('b2'));
        self::assertArrayHasKey('job', $this->building('b4'));
        self::assertArrayNotHasKey('egg', $this->state['barn']['items']);
    }

    public function testFullBarnSkipsOnlyThatJob(): void
    {
        $this->state['barn']['items'] = ['corn' => 200];

        $this->act('collect_all', [], 390);

        self::assertArrayHasKey('job', $this->building('b2'), 'corn does not fit');
        self::assertSame(21, $this->state['barn']['items']['egg']);
    }

    public function testNothingReady(): void
    {
        $this->assertGameError('nothing_to_collect', 'collect_all', [], 10);

        $this->state['barn']['items'] = ['corn' => 200, 'wildflower' => 200, 'egg' => 200];
        $this->assertGameError('barn_full', 'collect_all', [], 390);
    }
}
