<?php

declare(strict_types=1);

namespace FermeTranquille\Api;

use Closure;
use FermeTranquille\Auth;
use FermeTranquille\GameData;

/**
 * HTTP-agnostic API dispatcher: public/api/index.php feeds it the request.
 * Every response body is { ok, server_now, error?, ... }.
 */
final class Api
{
    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param (Closure(): int)|null $now
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly GameData $gameData,
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
            ['GET', 'data'] => $this->data(),
            ['POST', 'login'] => $this->login($body),
            ['POST', 'logout'] => $this->logout(),
            default => $this->notFound($method, $route),
        };
    }

    public function error(int $status, string $error): ApiResponse
    {
        return new ApiResponse($status, ['ok' => false, 'server_now' => ($this->now)(), 'error' => $error]);
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

    private function data(): ApiResponse
    {
        if ($this->auth->userId() === null) {
            return $this->error(401, 'unauthenticated');
        }

        return $this->ok(['data' => $this->gameData->toArray()]);
    }

    private function notFound(string $method, string $route): ApiResponse
    {
        $knownRoutes = ['session' => 'GET', 'data' => 'GET', 'login' => 'POST', 'logout' => 'POST'];
        if (isset($knownRoutes[$route]) && $knownRoutes[$route] !== $method) {
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
