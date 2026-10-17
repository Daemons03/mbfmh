<?php

declare(strict_types=1);

namespace FermeTranquille\Session;

interface Session
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** New session id, data kept (call on privilege change). */
    public function regenerate(): void;

    /** Drop all data and start over with a new id. */
    public function destroy(): void;
}
