<?php

declare(strict_types=1);

namespace FermeTranquille\Session;

use RuntimeException;

/**
 * PHP native session, started lazily on first access.
 *
 * Sessions live in their own save_path with PHP's own GC enabled: on
 * Debian/Ubuntu the sessionclean cron only cleans the default directory
 * using php.ini's gc_maxlifetime (24 min), which would kill 30-day logins.
 */
final class NativeSession implements Session
{
    /**
     * @param array{name?: string, lifetime?: int, secure?: bool, save_path?: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = $this->config['lifetime'] ?? 60 * 60 * 24 * 30;
        $savePath = $this->config['save_path'] ?? dirname(__DIR__, 2) . '/var/sessions';
        if (!is_dir($savePath) && !mkdir($savePath, 0700, true) && !is_dir($savePath)) {
            throw new RuntimeException("Cannot create session directory: {$savePath}");
        }

        ini_set('session.save_path', $savePath);
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name($this->config['name'] ?? 'ft_session');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $this->config['secure'] ?? true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Cannot start session');
        }
    }
}
