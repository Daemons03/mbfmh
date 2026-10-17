<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Land;
use PHPUnit\Framework\TestCase;

final class LandTest extends TestCase
{
    public function testBlockPositionsGrowAsASquare(): void
    {
        $expected = [
            1 => [0, 0], 2 => [1, 0], 3 => [1, 1], 4 => [0, 1],
            5 => [2, 0], 6 => [2, 1], 7 => [2, 2], 8 => [0, 2], 9 => [1, 2],
            10 => [3, 0], 13 => [3, 3], 14 => [0, 3], 16 => [2, 3],
        ];
        foreach ($expected as $number => $position) {
            self::assertSame($position, Land::blockPosition($number), "block {$number}");
        }
    }

    public function testBlockNumberIsTheInverseOfBlockPosition(): void
    {
        $seen = [];
        for ($n = 1; $n <= 100; $n++) {
            [$bx, $by] = Land::blockPosition($n);
            self::assertSame($n, Land::blockNumber($bx, $by));
            $seen["{$bx},{$by}"] = true;
        }
        self::assertCount(100, $seen, 'every block number maps to a distinct position');
    }

    public function testInitialSixExpansionsOwnThreeByTwoBlocks(): void
    {
        $land = new Land(4);

        self::assertTrue($land->isCellOwned(0, 0, 6));
        self::assertTrue($land->isCellOwned(11, 7, 6));
        self::assertFalse($land->isCellOwned(0, 8, 6), 'third row of blocks is locked');
        self::assertFalse($land->isCellOwned(12, 0, 6));
        self::assertFalse($land->isCellOwned(-1, 0, 6));
        self::assertTrue($land->isCellOwned(8, 8, 7), 'expansion 7 is block (2,2)');
    }

    public function testGridSideShowsNextBlockToBuy(): void
    {
        $land = new Land(4);

        self::assertSame(12, $land->gridSide(6));
        self::assertSame(12, $land->gridSide(8));
        self::assertSame(16, $land->gridSide(9));
    }
}
