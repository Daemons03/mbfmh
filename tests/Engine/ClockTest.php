<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Clock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    private const array JOB = ['started_at' => 1000, 'duration' => 300];

    public function testReadyExactlyAtStartPlusDuration(): void
    {
        self::assertFalse((new Clock(1299))->isReady(self::JOB));
        self::assertTrue((new Clock(1300))->isReady(self::JOB));
        self::assertSame(1, (new Clock(1299))->remaining(self::JOB));
        self::assertSame(0, (new Clock(5000))->remaining(self::JOB));
    }

    public function testSpeedDividesDuration(): void
    {
        self::assertSame(1030, (new Clock(0, 10))->readyAt(self::JOB));
        self::assertFalse((new Clock(1029, 10))->isReady(self::JOB));
        self::assertTrue((new Clock(1030, 10))->isReady(self::JOB));
    }

    public function testNonIntegerReadyTimeRoundsUp(): void
    {
        // 300 / 7 = 42.86 s
        self::assertSame(1043, (new Clock(0, 7))->readyAt(self::JOB));
        self::assertFalse((new Clock(1042, 7))->isReady(self::JOB));
        self::assertTrue((new Clock(1043, 7))->isReady(self::JOB));
    }

    public function testSlowSpeedLengthensJobs(): void
    {
        self::assertSame(1600, (new Clock(0, 0.5))->readyAt(self::JOB));
    }

    public function testRejectsNonPositiveSpeed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Clock(0, 0);
    }
}
