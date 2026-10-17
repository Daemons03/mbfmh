<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use InvalidArgumentException;

/**
 * Server time plus the admin speed multiplier.
 *
 * A job is ready when now >= started_at + duration / speed: changing the
 * speed also affects jobs already running.
 */
final readonly class Clock
{
    public function __construct(
        public int $now,
        public float $speed = 1.0,
    ) {
        if ($speed <= 0) {
            throw new InvalidArgumentException("Speed must be > 0, got {$speed}");
        }
    }

    /**
     * @param array{started_at: int, duration: int} $job
     */
    public function readyAt(array $job): int
    {
        // Integer ceil: for an integer now, now >= ceil(t) <=> now >= t.
        return (int) ceil($job['started_at'] + $job['duration'] / $this->speed);
    }

    /**
     * @param array{started_at: int, duration: int} $job
     */
    public function isReady(array $job): bool
    {
        return $this->now >= $this->readyAt($job);
    }

    /**
     * @param array{started_at: int, duration: int} $job
     */
    public function remaining(array $job): int
    {
        return max(0, $this->readyAt($job) - $this->now);
    }
}
