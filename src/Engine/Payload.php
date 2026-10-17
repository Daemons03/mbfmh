<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

/**
 * Typed access to an action payload; bad input becomes a GameError.
 */
final readonly class Payload
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw self::invalid($key);
        }

        return $value;
    }

    public function int(string $key, int $min = PHP_INT_MIN): int
    {
        $value = $this->values[$key] ?? null;
        if (!is_int($value) || $value < $min) {
            throw self::invalid($key);
        }

        return $value;
    }

    private static function invalid(string $key): GameError
    {
        return new GameError('invalid_payload', "Paramètre « {$key} » manquant ou invalide.", ['field' => $key]);
    }
}
