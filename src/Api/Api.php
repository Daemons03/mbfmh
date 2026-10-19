<?php

declare(strict_types=1);

namespace FermeTranquille\Api;

use Closure;
use FermeTranquille\Auth;
use FermeTranquille\BotLeagues;
use FermeTranquille\BotRepository;
use FermeTranquille\Engine\Admin;
use FermeTranquille\Engine\Engine;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\MarketUpgrades;
use FermeTranquille\GameData;
use FermeTranquille\StaleStateException;
use FermeTranquille\StateRepository;
use PDOException;

/**
 * HTTP-agnostic API dispatcher: public/api/index.php feeds it the request.
 * Every response body is { ok, server_now, error?, ... }.
 */
final class Api
{
    private const array ROUTES = [
        'session' => 'GET',
        'data' => 'GET',
        'state' => 'GET',
        'login' => 'POST',
        'logout' => 'POST',
        'action' => 'POST',
        'admin' => 'POST',
        'bots' => 'GET',
        'bot' => 'GET',
    ];

    /** Admin operations on the bot world rather than on the farm. */
    private const array BOT_OPS = ['set_bot_speed', 'reset_bots', 'advance_bots'];

    /** @var Closure(): int */
    private readonly Closure $now;

    private ?Engine $current = null;

    /** Whether this request's engine plays against the bots (tables present). */
    private bool $botsReady = false;

    /**
     * @param (Closure(): int)|null $now
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly GameData $gameData,
        private readonly Engine $engine,
        private readonly StateRepository $states,
        ?Closure $now = null,
        private readonly ?BotRepository $bots = null,
    ) {
        $this->now = $now ?? time(...);
    }

    /**
     * @param string $route path after /api/, e.g. "login"
     * @param array<string, mixed> $body decoded JSON body
     * @param array<string, mixed> $query query string (GET bot?id=)
     */
    public function handle(string $method, string $route, array $body, ?string $csrfHeader, array $query = []): ApiResponse
    {
        $route = trim($route, '/');

        if ($method === 'POST' && !$this->auth->checkCsrf($csrfHeader)) {
            return $this->error(403, 'csrf_invalid');
        }

        return match ([$method, $route]) {
            ['GET', 'session'] => $this->session(),
            ['GET', 'data'] => $this->authenticated(fn (): ApiResponse => $this->ok(['data' => $this->gameData->toArray()])),
            ['GET', 'state'] => $this->authenticated($this->state(...)),
            ['POST', 'login'] => $this->login($body),
            ['POST', 'logout'] => $this->logout(),
            ['POST', 'action'] => $this->authenticated(fn (int $userId): ApiResponse => $this->action($userId, $body)),
            ['POST', 'admin'] => $this->authenticated(fn (int $userId): ApiResponse => $this->admin($userId, $body)),
            ['GET', 'bots'] => $this->authenticated($this->ranking(...)),
            ['GET', 'bot'] => $this->authenticated(fn (): ApiResponse => $this->botProfile($query)),
            default => $this->notFound($method, $route),
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function error(int $status, string $error, array $extra = []): ApiResponse
    {
        return new ApiResponse($status, ['ok' => false, 'server_now' => ($this->now)(), 'error' => $error] + $extra);
    }

    private function session(): ApiResponse
    {
        return $this->ok([
            'authenticated' => $this->auth->userId() !== null,
            'login' => $this->auth->userLogin(),
            'csrf_token' => $this->auth->csrfToken(),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function login(array $body): ApiResponse
    {
        $login = $body['login'] ?? null;
        $password = $body['password'] ?? null;
        if (!is_string($login) || !is_string($password) || $login === '' || $password === '') {
            return $this->error(400, 'missing_credentials');
        }

        if (!$this->auth->login($login, $password)) {
            return $this->error(401, 'invalid_credentials');
        }

        return $this->session();
    }

    private function logout(): ApiResponse
    {
        $this->auth->logout();

        return $this->session();
    }

    private function state(int $userId): ApiResponse
    {
        ['state' => $state, 'version' => $version] = $this->states->load($userId);

        // Persist what time alone changes (e.g. empty market slots on a new farm).
        $resolved = $this->engine()->resolve($state, ($this->now)());
        if (json_encode($resolved) !== json_encode($state)) {
            try {
                $version = $this->states->save($userId, $resolved, $version);
                $state = $resolved;
            } catch (StaleStateException) {
                ['state' => $state, 'version' => $version] = $this->states->load($userId);
            }
        }

        return $this->ok($this->stateBody($state, $version));
    }

    /**
     * body: { type, payload?, version }
     *
     * @param array<string, mixed> $body
     */
    private function action(int $userId, array $body): ApiResponse
    {
        $type = $body['type'] ?? null;
        $payload = $body['payload'] ?? [];
        $version = $body['version'] ?? null;
        if (!is_string($type) || !is_array($payload) || !is_int($version)) {
            return $this->error(400, 'invalid_action_request');
        }

        return $this->mutate($userId, $version, $type, $payload,
            fn (array $state, int $now): array => $this->engine()->apply($state, $type, $payload, $now));
    }

    /**
     * body: { op, payload?, version }
     *
     * @param array<string, mixed> $body
     */
    private function admin(int $userId, array $body): ApiResponse
    {
        $op = $body['op'] ?? null;
        $payload = $body['payload'] ?? [];
        $version = $body['version'] ?? null;
        if (!is_string($op) || !is_array($payload) || !is_int($version)) {
            return $this->error(400, 'invalid_admin_request');
        }
        if (in_array($op, self::BOT_OPS, true)) {
            return $this->botAdmin($userId, $op, $payload);
        }
        $admin = new Admin($this->gameData, $this->engine());

        return $this->mutate($userId, $version, "admin:{$op}", $payload,
            fn (array $state, int $now): array => $admin->apply($state, $op, $payload, $now));
    }

    /**
     * Load -> version check -> change -> optimistic save -> log.
     *
     * @param array<string, mixed> $payload
     * @param Closure(array<string, mixed>, int): array<string, mixed> $change
     */
    private function mutate(int $userId, int $version, string $logType, array $payload, Closure $change): ApiResponse
    {
        ['state' => $state, 'version' => $current] = $this->states->load($userId);
        if ($version !== $current) {
            return $this->stale($state, $current);
        }

        try {
            $newState = $change($state, ($this->now)());
        } catch (GameError $e) {
            return $this->error(422, $e->errorCode, ['message' => $e->getMessage(), 'details' => (object) $e->details]);
        }

        try {
            $newVersion = $this->states->save($userId, $newState, $current);
        } catch (StaleStateException) {
            ['state' => $state, 'version' => $current] = $this->states->load($userId);

            return $this->stale($state, $current);
        }
        $this->states->logAction($userId, $logType, $payload);

        return $this->ok($this->stateBody($newState, $newVersion));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function stale(array $state, int $version): ApiResponse
    {
        return $this->error(409, 'stale_state', [
            'message' => 'La ferme a été modifiée ailleurs, elle a été rechargée.',
        ] + $this->stateBody($state, $version));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function stateBody(array $state, int $version): array
    {
        return [
            'state' => $this->engine()->normalize($state),
            'version' => $version,
            'view' => $this->engine()->view($state, ($this->now)()),
        ];
    }

    /**
     * The engine of this request: the bot world brought up to date first,
     * the tournament played against its bots. Without the bot tables (not
     * migrated yet) the game goes on with the invented farms.
     */
    private function engine(): Engine
    {
        if ($this->current !== null) {
            return $this->current;
        }
        if ($this->bots === null) {
            return $this->current = $this->engine;
        }
        try {
            $this->bots->ensure(($this->now)());
        } catch (PDOException $e) {
            error_log('[ferme-tranquille] bots unavailable: ' . $e->getMessage());

            return $this->current = $this->engine;
        }

        $this->botsReady = true;

        return $this->current = $this->engine->withOpponents(new BotLeagues($this->bots, $this->gameData));
    }

    /**
     * Ranking: every bot (id, name, title, level, market level, tier) and the player.
     */
    private function ranking(int $userId): ApiResponse
    {
        $tournament = $this->engine()->tournament();
        if (!$this->botsReady) {
            return $this->error(404, 'no_bots');
        }
        $state = $this->engine()->normalize($this->states->load($userId)['state']);
        $farm = new Farm($state, $this->gameData);
        $tierNames = [];
        $row = fn (array $bot): array => [
            'id' => $bot['id'], 'name' => $bot['name'], 'title' => $bot['title'],
            'level' => $bot['level'], 'market_xp' => $bot['market_xp'],
            'market_level' => MarketUpgrades::levelFor($this->gameData, $bot['market_xp'])['level'],
            'tier' => $bot['tier'], 'tier_name' => $tierNames[$bot['tier']] ??= $tournament->tierInfo($bot['tier'])['name'],
        ];
        $tier = $farm->tournament()['tier'] ?? 0;

        return $this->ok([
            'bots' => array_map($row, $this->bots->bots()),
            'me' => [
                'level' => $farm->level(), 'market_xp' => $farm->marketXp(),
                'market_level' => MarketUpgrades::levelFor($this->gameData, $farm->marketXp())['level'],
                'tier' => $tier, 'tier_name' => $tournament->tierInfo($tier)['name'],
            ],
            'world' => $this->bots->meta(),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function botProfile(array $query): ApiResponse
    {
        $id = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT);
        $this->engine();
        $bot = $id === false || !$this->botsReady ? null : $this->bots->bot($id);
        if ($bot === null) {
            return $this->error(404, 'unknown_bot');
        }
        $buildings = [];
        foreach ($bot['buildings'] as [$type, $level]) {
            $buildings[$type][] = $level;
        }

        return $this->ok(['bot' => [
            'id' => $bot['id'], 'name' => $bot['name'], 'title' => $bot['title'],
            'level' => $bot['level'], 'cash' => $bot['cash'], 'gold' => $bot['gold'] ?? 0, 'expansions' => $bot['expansions'],
            'market_xp' => $bot['market_xp'],
            'market_level' => MarketUpgrades::levelFor($this->gameData, $bot['market_xp'])['level'],
            'tier' => $bot['tier'], 'tier_name' => $this->engine()->tournament()->tierInfo($bot['tier'])['name'],
            'buildings' => array_map(static fn (string $type, array $levels): array => ['type' => $type, 'levels' => $levels], array_keys($buildings), $buildings),
        ]]);
    }

    /**
     * set_bot_speed { speed }, reset_bots, advance_bots { days }: the bot
     * world changes, the farm does not.
     *
     * @param array<string, mixed> $payload
     */
    private function botAdmin(int $userId, string $op, array $payload): ApiResponse
    {
        $this->engine();
        if (!$this->botsReady) {
            return $this->error(404, 'no_bots');
        }
        $now = ($this->now)();
        switch ($op) {
            case 'set_bot_speed':
                $speed = $payload['speed'] ?? null;
                if (!is_int($speed) && !is_float($speed) || $speed < 0.1 || $speed > 100) {
                    return $this->error(422, 'invalid_payload', ['message' => 'Vitesse des bots entre 0,1 et 100.', 'details' => (object) ['field' => 'speed']]);
                }
                $this->bots->setSpeed($now, (float) $speed);
                break;
            case 'reset_bots':
                $this->bots->reset($now);
                break;
            case 'advance_bots':
                $days = $payload['days'] ?? null;
                if (!is_int($days) || $days < 1 || $days > 60) {
                    return $this->error(422, 'invalid_payload', ['message' => 'Entre 1 et 60 jours.', 'details' => (object) ['field' => 'days']]);
                }
                $this->bots->advanceDays($now, $days);
                break;
        }
        $this->current = null; // world changed: fresh opponents for the answer
        $this->states->logAction($userId, "admin:{$op}", $payload);
        ['state' => $state, 'version' => $version] = $this->states->load($userId);

        return $this->ok($this->stateBody($state, $version) + ['bot_world' => $this->bots->meta()]);
    }

    /**
     * @param Closure(int): ApiResponse $handler receives the user id
     */
    private function authenticated(Closure $handler): ApiResponse
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            return $this->error(401, 'unauthenticated');
        }

        return $handler($userId);
    }

    private function notFound(string $method, string $route): ApiResponse
    {
        if (isset(self::ROUTES[$route]) && self::ROUTES[$route] !== $method) {
            return $this->error(405, 'method_not_allowed');
        }

        return $this->error(404, 'not_found');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data = []): ApiResponse
    {
        return new ApiResponse(200, ['ok' => true, 'server_now' => ($this->now)()] + $data);
    }
}
