<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use DomainException;

/**
 * A rule violation the player can understand: a stable code for the front
 * and a French message ready to display.
 */
final class GameError extends DomainException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
