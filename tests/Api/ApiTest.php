<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Api;

use FermeTranquille\Api\Api;
use FermeTranquille\Auth;
use FermeTranquille\Engine\Engine;
use FermeTranquille\GameData;
use FermeTranquille\StateRepository;
use FermeTranquille\Tests\Support\ArraySession;
use FermeTranquille\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    private const int NOW = 1_760_000_000;

    private PDO $pdo;
    private Auth $auth;
    private StateRepository $states;
    private Api $api;
    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->auth = new Auth($this->pdo, new ArraySession());
        $this->auth->createUser('maxime', 'secret-pass');
        $data = GameData::load(cacheDir: null);
        $engine = new Engine($data);
        $clock = fn (): int => $this->now;
        $this->states = new StateRepository($this->pdo, $engine, $clock, purgeChance: 0);
        $this->api = new Api($this->auth, $data, $engine, $this->states, $clock);
    }

    public function testSessionReturnsCsrfTokenWhenAnonymous(): void
    {
        $response = $this->api->handle('GET', 'session', [], null);

        self::assertSame(200, $response->status);
        self::assertTrue($response->body['ok']);
        self::assertSame(self::NOW, $response->body['server_now']);
        self::assertFalse($response->body['authenticated']);
        self::assertNull($response->body['login']);
        self::assertSame($this->auth->csrfToken(), $response->body['csrf_token']);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'secret-pass'], null);

        self::assertSame(403, $response->status);
        self::assertSame(['ok' => false, 'server_now' => self::NOW, 'error' => 'csrf_invalid'], $response->body);
        self::assertNull($this->auth->userId());
    }

    public function testLoginFlow(): void
    {
        $token = $this->csrf();

        $response = $this->api->handle('POST', '/login/', ['login' => 'maxime', 'password' => 'secret-pass'], $token);

        self::assertSame(200, $response->status);
        self::assertTrue($response->body['authenticated']);
        self::assertSame('maxime', $response->body['login']);
        self::assertNotSame($token, $response->body['csrf_token']);
    }

    public function testLoginWithWrongPassword(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'bad-pass'], $this->csrf());

        self::assertSame(401, $response->status);
        self::assertSame('invalid_credentials', $response->body['error']);
    }

    public function testLoginWithMissingFields(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => 'maxime'], $this->csrf());

        self::assertSame(400, $response->status);
        self::assertSame('missing_credentials', $response->body['error']);
    }

    public function testLoginRejectsNonStringFields(): void
    {
        $response = $this->api->handle('POST', 'login', ['login' => ['maxime'], 'password' => 123], $this->csrf());

        self::assertSame(400, $response->status);
    }

    public function testLogout(): void
    {
        $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'secret-pass'], $this->csrf());

        $response = $this->api->handle('POST', 'logout', [], $this->csrf());

        self::assertSame(200, $response->status);
        self::assertFalse($response->body['authenticated']);
        self::assertNull($this->auth->userId());
    }

    public function testUnknownRoute(): void
    {
        $response = $this->api->handle('GET', 'nope', [], null);

        self::assertSame(404, $response->status);
        self::assertSame('not_found', $response->body['error']);
    }

    public function testWrongMethod(): void
    {
        $response = $this->api->handle('GET', 'login', [], null);

        self::assertSame(405, $response->status);
        self::assertSame('method_not_allowed', $response->body['error']);
    }

    public function testDataRequiresLogin(): void
    {
        $response = $this->api->handle('GET', 'data', [], null);

        self::assertSame(401, $response->status);
        self::assertSame('unauthenticated', $response->body['error']);
    }

    public function testDataReturnsGameData(): void
    {
        $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'secret-pass'], $this->csrf());

        $response = $this->api->handle('GET', 'data', [], null);

        self::assertSame(200, $response->status);
        self::assertSame('Maïs', $response->body['data']['items']['corn']['name']);
    }

    public function testStateRequiresLogin(): void
    {
        self::assertSame(401, $this->api->handle('GET', 'state', [], null)->status);
        self::assertSame(401, $this->api->handle('POST', 'action', [], $this->csrf())->status);
    }

    public function testStateReturnsInitialFarmWithView(): void
    {
        $this->login();

        $response = $this->api->handle('GET', 'state', [], null);

        self::assertSame(200, $response->status);
        self::assertSame(1, $response->body['version']);
        self::assertSame(2000, $response->body['state']['cash']);
        self::assertSame(200, $response->body['view']['barn']['capacity']);
        self::assertSame(self::NOW, $response->body['server_now']);
    }

    public function testActionAppliesSavesAndLogs(): void
    {
        $this->login();
        $this->api->handle('GET', 'state', [], null);

        $response = $this->action('plant', ['id' => 'b2', 'crop' => 'corn'], 1);

        self::assertSame(200, $response->status, json_encode($response->body));
        self::assertSame(2, $response->body['version']);
        self::assertSame(1953, $response->body['state']['cash']);
        self::assertSame(self::NOW + 300, $response->body['view']['jobs']->b2['ready_at']);
        self::assertSame(2, $this->states->load(1)['version'], 'persisted');
        self::assertSame('plant', $this->pdo->query('SELECT action_type FROM action_log')->fetchColumn());
    }

    public function testFullCycleOverTime(): void
    {
        $this->login();
        $this->action('plant', ['id' => 'b2', 'crop' => 'corn'], 1);
        $this->now += 300;
        $this->action('harvest', ['id' => 'b2'], 2);

        $response = $this->action('sell_barn', ['item' => 'corn', 'quantity' => 10], 3);

        self::assertSame(200, $response->status, json_encode($response->body));
        self::assertSame(1953 + 50, $response->body['state']['cash']);
        self::assertSame(4, $response->body['version']);
    }

    public function testStaleVersionReturns409WithFreshState(): void
    {
        $this->login();
        $this->action('plant', ['id' => 'b2', 'crop' => 'corn'], 1);

        // Another tab still believes the state is at version 1.
        $response = $this->action('plant', ['id' => 'b3', 'crop' => 'corn'], 1);

        self::assertSame(409, $response->status);
        self::assertSame('stale_state', $response->body['error']);
        self::assertSame(2, $response->body['version']);
        self::assertSame(1953, $response->body['state']['cash']);
        self::assertArrayNotHasKey('job', $response->body['state']['buildings'][2], 'second action was not applied');
    }

    public function testRuleViolationReturns422(): void
    {
        $this->login();

        $response = $this->action('harvest', ['id' => 'b2'], 1);

        self::assertSame(422, $response->status);
        self::assertSame('nothing_to_harvest', $response->body['error']);
        self::assertSame('Rien à récolter ici.', $response->body['message']);
        self::assertSame(1, $this->states->load(1)['version'], 'nothing saved');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM action_log')->fetchColumn());
    }

    public function testUnknownActionReturns422(): void
    {
        $this->login();

        self::assertSame('unknown_action', $this->action('teleport', [], 1)->body['error']);
    }

    public function testMalformedActionRequest(): void
    {
        $this->login();
        $token = $this->csrf();

        foreach ([[], ['type' => 'plant'], ['type' => 'plant', 'version' => '1'], ['type' => 'plant', 'version' => 1, 'payload' => 'x']] as $body) {
            $response = $this->api->handle('POST', 'action', $body, $token);
            self::assertSame(400, $response->status, json_encode($body));
            self::assertSame('invalid_action_request', $response->body['error']);
        }
    }

    public function testGetStatePersistsResolvedMarketForLegacyFarms(): void
    {
        $this->login();
        ['state' => $state] = $this->states->load(1);
        unset($state['contracts'], $state['market_seed'], $state['next_contract']);
        $this->states->save(1, $state, 1);

        $response = $this->api->handle('GET', 'state', [], null);

        self::assertSame(3, $response->body['version'], 'migration saved');
        self::assertCount(6, $this->states->load(1)['state']['contracts']);
        self::assertSame(3, $this->api->handle('GET', 'state', [], null)->body['version'], 'no write when up to date');
    }

    public function testMarketFlowThroughApi(): void
    {
        $this->login();
        $state = $this->api->handle('GET', 'state', [], null)->body['state'];
        $contract = $state['contracts'][0];

        $discard = $this->action('discard_contract', ['id' => $contract['id']], 1);
        self::assertSame(200, $discard->status);
        $waitingId = $discard->body['state']['contracts'][0]['id'];
        self::assertFalse($discard->body['view']['contracts']->{$waitingId}['available']);

        $early = $this->action('fulfill_contract', ['id' => $waitingId], 2);
        self::assertSame(422, $early->status);
        self::assertSame('contract_waiting', $early->body['error']);
    }

    public function testAdminRouteAppliesLogsAndChecksVersion(): void
    {
        self::assertSame(401, $this->api->handle('POST', 'admin', ['op' => 'unlock_all', 'version' => 1], $this->csrf())->status);
        $this->login();
        $this->api->handle('GET', 'state', [], null);

        $response = $this->api->handle('POST', 'admin', ['op' => 'set_speed', 'payload' => ['speed' => 50], 'version' => 1], $this->csrf());
        self::assertSame(200, $response->status, json_encode($response->body));
        self::assertSame(50, $response->body['state']['settings']['speed']);
        self::assertSame(2, $response->body['version']);
        self::assertSame('admin:set_speed', $this->pdo->query('SELECT action_type FROM action_log')->fetchColumn());

        $stale = $this->api->handle('POST', 'admin', ['op' => 'unlock_all', 'version' => 1], $this->csrf());
        self::assertSame(409, $stale->status);

        $invalid = $this->api->handle('POST', 'admin', ['op' => 'set_level', 'payload' => ['level' => 0], 'version' => 2], $this->csrf());
        self::assertSame(422, $invalid->status);
        self::assertSame(400, $this->api->handle('POST', 'admin', ['op' => 'unlock_all'], $this->csrf())->status);
    }

    private function login(): void
    {
        $this->api->handle('POST', 'login', ['login' => 'maxime', 'password' => 'secret-pass'], $this->csrf());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function action(string $type, array $payload, int $version): \FermeTranquille\Api\ApiResponse
    {
        return $this->api->handle('POST', 'action', ['type' => $type, 'payload' => $payload, 'version' => $version], $this->csrf());
    }

    private function csrf(): string
    {
        return $this->api->handle('GET', 'session', [], null)->body['csrf_token'];
    }
}
