<?php

declare(strict_types=1);

namespace FermeTranquille\Api;

use Closure;
use FermeTranquille\Auth;
use FermeTranquille\Engine\Engine;
use FermeTranquille\Engine\GameError;
use FermeTranquille\GameData;
use FermeTranquille\StaleStateException;
use FermeTranquille\StateRepository;

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
    ];

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param (Closure(): int)|null $now
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly GameData $gameData,
        private readonly Engine $engine,
        private readonly StateRepository $states,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    /**
     * @param string $route path after /api/, e.g. "login"
     * @param array<string, mixed> $body decoded JSON body
     */
    public function handle(string $method, string $route, array $body, ?string $csrfHeader): ApiResponse
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

        ['state' => $state, 'version' => $current] = $this->states->load($userId);
        if ($version !== $current) {
            return $this->stale($state, $current);
        }

        $now = ($this->now)();
        try {
            $newState = $this->engine->apply($state, $type, $payload, $now);
        } catch (GameError $e) {
            return $this->error(422, $e->errorCode, ['message' => $e->getMessage(), 'details' => (object) $e->details]);
        }

        try {
            $newVersion = $this->states->save($userId, $newState, $current);
        } catch (StaleStateException) {
            ['state' => $state, 'version' => $current] = $this->states->load($userId);

            return $this->stale($state, $current);
        }
        $this->states->logAction($userId, $type, $payload);

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
            'state' => $this->engine->normalize($state),
            'version' => $version,
            'view' => $this->engine->view($state, ($this->now)()),
        ];
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
