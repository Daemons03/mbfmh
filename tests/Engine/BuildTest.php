<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

final class BuildTest extends EngineTestCase
{
    public function testBuildFieldOnFreeOwnedCell(): void
    {
        $this->act('build', ['type' => 'field', 'x' => 5, 'y' => 5]);

        self::assertSame(2000 - 250, $this->state['cash']);
        self::assertSame(['id' => 'b4', 'type' => 'field', 'x' => 5, 'y' => 5, 'level' => 1], $this->building('b4'));
        self::assertSame(5, $this->state['next_id']);
    }

    public function testIdsAreNeverReused(): void
    {
        $this->act('build', ['type' => 'field', 'x' => 5, 'y' => 5]);
        $this->act('demolish', ['id' => 'b4']);
        $this->act('build', ['type' => 'field', 'x' => 5, 'y' => 5]);

        self::assertSame('b5', $this->state['buildings'][array_key_last($this->state['buildings'])]['id']);
    }

    public function testBuildPlacementRules(): void
    {
        $this->assertGameError('cell_locked', 'build', ['type' => 'field', 'x' => 0, 'y' => 8]);
        $this->assertGameError('cell_locked', 'build', ['type' => 'field', 'x' => 12, 'y' => 0]);
        $this->assertGameError('cell_occupied', 'build', ['type' => 'field', 'x' => 1, 'y' => 1]);
        $this->assertGameError('cell_occupied', 'build', ['type' => 'field', 'x' => 3, 'y' => 0]);
        $this->assertGameError('invalid_payload', 'build', ['type' => 'field', 'x' => -1, 'y' => 0]);
    }

    public function testMultiCellBuildingMustFitEntirely(): void
    {
        // 2x2 windmill at (10,7) would cover row 8: locked.
        $this->assertGameError('cell_locked', 'build', ['type' => 'windmill', 'x' => 10, 'y' => 7]);
        // At (2,0) it would cover field b2 at (3,0).
        $this->assertGameError('cell_occupied', 'build', ['type' => 'windmill', 'x' => 2, 'y' => 0]);

        $this->act('build', ['type' => 'windmill', 'x' => 10, 'y' => 6]);
        self::assertSame(2000 - 50, $this->state['cash']);
    }

    public function testBuildRules(): void
    {
        $this->assertGameError('unknown_building_type', 'build', ['type' => 'castle', 'x' => 5, 'y' => 5]);
        $this->assertGameError('max_count_reached', 'build', ['type' => 'barn', 'x' => 5, 'y' => 5]);

        $this->state['cash'] = 249;
        $this->assertGameError('not_enough_resources', 'build', ['type' => 'field', 'x' => 5, 'y' => 5]);
    }

    public function testMoveKeepsRunningJob(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('move', ['id' => 'b2', 'x' => 6, 'y' => 6]);

        $field = $this->building('b2');
        self::assertSame([6, 6], [$field['x'], $field['y']]);
        self::assertSame('corn', $field['job']['product']);
    }

    public function testMoveCanOverlapOwnFootprint(): void
    {
        // Field 3x2 at (2,0) shifted by one cell overlaps its old position.
        $this->act('move', ['id' => 'b2', 'x' => 3, 'y' => 0]);

        self::assertSame(3, $this->building('b2')['x']);
    }

    public function testMoveRules(): void
    {
        $this->assertGameError('cell_occupied', 'move', ['id' => 'b2', 'x' => 3, 'y' => 1]);
        $this->assertGameError('cell_locked', 'move', ['id' => 'b2', 'x' => 3, 'y' => 8]);
        $this->assertGameError('unknown_building', 'move', ['id' => 'b42', 'x' => 6, 'y' => 6]);
    }

    public function testDemolish(): void
    {
        $this->act('demolish', ['id' => 'b3']);

        self::assertSame(['b1', 'b2', 'farmhouse', 'fence'], array_column($this->state['buildings'], 'id'));
        self::assertSame(2000, $this->state['cash'], 'no refund');
    }

    public function testDemolishRules(): void
    {
        $this->assertGameError('not_demolishable', 'demolish', ['id' => 'b1']);

        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->assertGameError('busy', 'demolish', ['id' => 'b2']);
    }
}
