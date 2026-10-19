<?php

declare(strict_types=1);

use FermeTranquille\Api\Api;
use FermeTranquille\Api\ApiResponse;
use FermeTranquille\Auth;
use FermeTranquille\BotRepository;
use FermeTranquille\Config;
use FermeTranquille\Db;
use FermeTranquille\Engine\BotWorld;
use FermeTranquille\Engine\Engine;
use FermeTranquille\GameData;
use FermeTranquille\Session\NativeSession;
use FermeTranquille\StateRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$send = static function (ApiResponse $response): never {
    http_response_code($response->status);
    echo json_encode($response->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};

$debug = false;

try {
    $config = Config::get();
    $debug = (bool) ($config['app']['debug'] ?? false);
    date_default_timezone_set((string) ($config['app']['timezone'] ?? 'UTC'));

    $pdo = Db::pdo();
    $gameData = GameData::load();
    $engine = new Engine($gameData);
    $api = new Api(
        new Auth($pdo, new NativeSession(Config::section('session'))),
        $gameData,
        $engine,
        new StateRepository($pdo, $engine),
        bots: new BotRepository($pdo, new BotWorld($gameData)),
    );

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $route = preg_replace('#^/api(?:/index\.php)?/?#', '', $path) ?? '';

    $body = [];
    if ($method === 'POST') {
        $raw = (string) file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $send($api->error(400, 'invalid_json'));
            }
            $body = $decoded;
        }
    }

    $send($api->handle($method, $route, $body, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $_GET));
} catch (Throwable $e) {
    error_log('[ferme-tranquille] ' . $e);
    $send(new ApiResponse(500, [
        'ok' => false,
        'server_now' => time(),
        'error' => $debug ? $e->getMessage() : 'server_error',
    ]));
}
