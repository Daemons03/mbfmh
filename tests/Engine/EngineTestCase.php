<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Engine;
use FermeTranquille\Engine\GameError;
use FermeTranquille\GameData;
use PHPUnit\Framework\TestCase;

/**
 * Engine tests run against the real data/game-data.json.
 * Starting farm: barn b1 at (0,0) 2x2, fields b2 (3,0) and b3 (3,1).
 */
abstract class EngineTestCase extends TestCase
{
    protected const int NOW = 1_760_000_000;

    protected GameData $data;
    protected Engine $engine;

    /** @var array<string, mixed> */
    protected array $state;

    protected function setUp(): void
    {
        $this->data = GameData::load(cacheDir: null);
        $this->engine = new Engine($this->data);
        $this->state = $this->decode($this->engine->newState());
    }

    /**
     * Applies an action to $this->state, at NOW + $elapsed seconds.
     *
     * @param array<string, mixed> $payload
     */
    protected function act(string $type, array $payload = [], int $elapsed = 0): void
    {
        $this->state = $this->decode($this->engine->apply($this->state, $type, $payload, self::NOW + $elapsed));
    }

    /**
     * Asserts the action fails with $code and leaves the state untouched.
     *
     * @param array<string, mixed> $payload
     */
    protected function assertGameError(string $code, string $type, array $payload = [], int $elapsed = 0): GameError
    {
        $before = $this->state;
        try {
            $this->engine->apply($this->state, $type, $payload, self::NOW + $elapsed);
        } catch (GameError $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            self::assertSame($before, $this->state);

            return $e;
        }

        self::fail("Expected GameError {$code}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function building(string $id): array
    {
        foreach ($this->state['buildings'] as $building) {
            if ($building['id'] === $id) {
                return $building;
            }
        }
        self::fail("No building {$id}");
    }

    /**
     * Round-trips through JSON, as the state does through MySQL.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    protected function decode(array $state): array
    {
        return json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
