<?php

declare(strict_types=1);

namespace FermeTranquille\Tests;

use FermeTranquille\Engine\Engine;
use FermeTranquille\GameData;
use FermeTranquille\StaleStateException;
use FermeTranquille\StateRepository;
use FermeTranquille\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class StateRepositoryTest extends TestCase
{
    private const int USER = 1;

    private PDO $pdo;
    private Engine $engine;
    private int $now = 1_760_000_000;
    private StateRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->engine = new Engine(GameData::load(cacheDir: null));
        $this->repo = new StateRepository($this->pdo, $this->engine, fn (): int => $this->now, purgeChance: 0);
    }

    public function testFirstLoadCreatesInitialState(): void
    {
        $loaded = $this->repo->load(self::USER);

        self::assertSame(1, $loaded['version']);
        self::assertSame(2000, $loaded['state']['cash']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM player_state')->fetchColumn());

        self::assertSame(json_encode($loaded), json_encode($this->repo->load(self::USER)), 'second load reads the same row');
    }

    public function testLoadKeepsEmptyObjects(): void
    {
        $state = $this->repo->load(self::USER)['state'];

        self::assertStringContainsString('"items":{}', json_encode($state));
    }

    public function testSaveIncrementsVersion(): void
    {
        ['state' => $state] = $this->repo->load(self::USER);
        $state['cash'] = 1234;

        self::assertSame(2, $this->repo->save(self::USER, $state, 1));

        $reloaded = $this->repo->load(self::USER);
        self::assertSame(2, $reloaded['version']);
        self::assertSame(1234, $reloaded['state']['cash']);
    }

    public function testSaveWithStaleVersionIsRejected(): void
    {
        ['state' => $state] = $this->repo->load(self::USER);
        $this->repo->save(self::USER, ['cash' => 1] + $state, 1);

        try {
            $this->repo->save(self::USER, ['cash' => 2] + $state, 1);
            self::fail('Expected StaleStateException');
        } catch (StaleStateException) {
        }

        self::assertSame(1, $this->repo->load(self::USER)['state']['cash'], 'first writer wins');
    }

    public function testSaveForUnknownUserIsRejected(): void
    {
        $this->expectException(StaleStateException::class);

        $this->repo->save(42, $this->engine->newState(), 1);
    }

    public function testStatesAreIsolatedPerUser(): void
    {
        ['state' => $state] = $this->repo->load(1);
        $this->repo->save(1, ['cash' => 7] + $state, 1);

        self::assertSame(2000, $this->repo->load(2)['state']['cash']);
    }

    public function testLogActionAndPurge(): void
    {
        $this->repo->logAction(self::USER, 'plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->now += 31 * 86400;
        $this->repo->logAction(self::USER, 'harvest', ['id' => 'b2']);

        $rows = $this->pdo->query('SELECT action_type, payload_json FROM action_log ORDER BY id')->fetchAll();
        self::assertSame([
            ['action_type' => 'plant', 'payload_json' => '{"id":"b2","crop":"corn"}'],
            ['action_type' => 'harvest', 'payload_json' => '{"id":"b2"}'],
        ], $rows);

        self::assertSame(1, $this->repo->purgeLog());
        self::assertSame('harvest', $this->pdo->query('SELECT action_type FROM action_log')->fetchColumn());
    }
}
