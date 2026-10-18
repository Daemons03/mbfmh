<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

/**
 * Quarter turns: width and depth swap. Fields are 3 x 2, so 2 x 3 once rotated.
 * Starting farm: barn b1 (0,0), fields b2 (2,0) and b3 (2,2), farmhouse (0,2).
 */
final class RotationTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['cash'] = 10_000;
        $this->state['level'] = 10;
    }

    public function testBuildRotated(): void
    {
        $this->act('build', ['type' => 'field', 'x' => 6, 'y' => 0, 'rotated' => true]);

        self::assertSame(['id' => 'b4', 'type' => 'field', 'x' => 6, 'y' => 0, 'rotated' => true, 'level' => 1], $this->building('b4'));
        // 2 x 3: (7, 2) is taken, (8, 0) is free.
        $this->assertGameError('cell_occupied', 'build', ['type' => 'house', 'x' => 7, 'y' => 2]);
        $this->act('build', ['type' => 'house', 'x' => 8, 'y' => 0]);
    }

    public function testRotateInPlaceKeepsTheJob(): void
    {
        $this->act('build', ['type' => 'field', 'x' => 6, 'y' => 0]);
        $this->act('plant', ['id' => 'b4', 'crop' => 'corn']);

        $this->act('rotate', ['id' => 'b4']);
        self::assertTrue($this->building('b4')['rotated']);
        self::assertSame('corn', $this->building('b4')['job']['product']);

        $this->act('rotate', ['id' => 'b4']);
        self::assertArrayNotHasKey('rotated', $this->building('b4'), 'back to the normal orientation');
    }

    public function testRotateNeedsRoom(): void
    {
        // Field b2 at (2,0) rotated would need (2..3, 0..2): the field b3 at (2,2) is in the way.
        $this->assertGameError('cell_occupied', 'rotate', ['id' => 'b2']);
        $this->assertGameError('not_movable', 'rotate', ['id' => 'fence']);
    }

    public function testMoveKeepsOrChangesTheOrientation(): void
    {
        $this->act('build', ['type' => 'field', 'x' => 6, 'y' => 0, 'rotated' => true]);

        $this->act('move', ['id' => 'b4', 'x' => 6, 'y' => 4]);
        self::assertTrue($this->building('b4')['rotated'], 'kept');

        $this->act('move', ['id' => 'b4', 'x' => 6, 'y' => 4, 'rotated' => false]);
        self::assertArrayNotHasKey('rotated', $this->building('b4'));
        $this->assertGameError('invalid_payload', 'move', ['id' => 'b4', 'x' => 6, 'y' => 4, 'rotated' => 'yes']);
    }
}
