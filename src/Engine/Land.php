<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use InvalidArgumentException;

/**
 * Grid geometry. The farm is made of square blocks numbered from 1 and laid
 * out as a growing square: block 1 is (0,0); square k adds column x = k-1
 * top to bottom, then row y = k-1 left to right.
 *
 *   1 2 5 10
 *   4 3 6 11
 *   8 9 7 12
 *   ...
 *
 * Owning n expansions means owning blocks 1..n.
 */
final readonly class Land
{
    public function __construct(public int $blockSize)
    {
        if ($blockSize < 1) {
            throw new InvalidArgumentException('Block size must be >= 1');
        }
    }

    /**
     * @return array{int, int} block coordinates [bx, by]
     */
    public static function blockPosition(int $number): array
    {
        if ($number < 1) {
            throw new InvalidArgumentException("Invalid block number: {$number}");
        }
        $k = (int) ceil(sqrt($number));
        $offset = $number - ($k - 1) ** 2 - 1; // 0 .. 2k-2

        return $offset < $k ? [$k - 1, $offset] : [$offset - $k, $k - 1];
    }

    public static function blockNumber(int $bx, int $by): int
    {
        $k = max($bx, $by) + 1;
        $base = ($k - 1) ** 2;

        return $bx === $k - 1 ? $base + $by + 1 : $base + $k + $bx + 1;
    }

    public function isCellOwned(int $x, int $y, int $expansions): bool
    {
        if ($x < 0 || $y < 0) {
            return false;
        }

        return self::blockNumber(intdiv($x, $this->blockSize), intdiv($y, $this->blockSize)) <= $expansions;
    }

    /**
     * Visible grid side in cells: smallest square holding the owned blocks
     * and the next one to buy.
     */
    public function gridSide(int $expansions): int
    {
        return (int) ceil(sqrt($expansions + 1)) * $this->blockSize;
    }
}
