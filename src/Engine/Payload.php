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

    /**
     * Same payload with one value replaced (e.g. another building id).
     */
    public function with(string $key, mixed $value): self
    {
        return new self([$key => $value] + $this->values);
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

    public function bool(string $key): bool
    {
        $value = $this->values[$key] ?? null;
        if (!is_bool($value)) {
            throw self::invalid($key);
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function number(string $key, float $min, float $max): float
    {
        $value = $this->values[$key] ?? null;
        if ((!is_int($value) && !is_float($value)) || $value < $min || $value > $max) {
            throw self::invalid($key);
        }

        return (float) $value;
    }

    /**
     * A JSON object (associative array), contents checked by the caller.
     *
     * @return array<string, mixed>
     */
    public function object(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($key);
        }

        return $value;
    }

    /**
     * Object of { id: int >= 0 }.
     *
     * @return array<string, int>
     */
    public function quantities(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($key);
        }
        foreach ($value as $id => $quantity) {
            if (!is_string($id) || !is_int($quantity) || $quantity < 0) {
                throw self::invalid("{$key}.{$id}");
            }
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $key, array $allowed): string
    {
        $value = $this->values[$key] ?? null;
        if (!in_array($value, $allowed, true)) {
            throw self::invalid($key);
        }

        return $value;
    }

    private static function invalid(string $key): GameError
    {
        return new GameError('invalid_payload', "Paramètre « {$key} » manquant ou invalide.", ['field' => $key]);
    }
}
