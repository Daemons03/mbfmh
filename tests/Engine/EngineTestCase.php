<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Engine;
use FermeTranquille\Engine\GameError;
use FermeTranquille\GameData;
use PHPUnit\Framework\TestCase;

/**
 * Engine tests run against data/game-data.json with two changes, so each
 * test checks its own rule without the workers/happiness economy getting in
 * the way: the farmhouse houses 10 000 workers and the fence gives 0
 * happiness (costs x1). WorkersTest uses the real data (realData()).
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
        $this->data = $this->gameData();
        $this->engine = new Engine($this->data);
        $this->state = $this->decode($this->engine->newState());
    }

    protected function gameData(): GameData
    {
        $raw = GameData::load(cacheDir: null)->toArray();
        $raw['buildings']['farmhouse']['levels'][0]['workers'] = 10_000;
        $raw['buildings']['fence']['levels'][0]['happiness'] = 0;
        $raw['gold_rewards'] = ['daily_login' => 0, 'per_level' => 0]; // GoldRewardTest checks them

        return GameData::fromArray($raw);
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
     * Sets fields of a building in $this->state (e.g. ['level' => 5]).
     *
     * @param array<string, mixed> $values
     */
    protected function setBuilding(string $id, array $values): void
    {
        foreach ($this->state['buildings'] as $index => $building) {
            if ($building['id'] === $id) {
                $this->state['buildings'][$index] = $values + $building;

                return;
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
