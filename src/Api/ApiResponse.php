<?php

declare(strict_types=1);

namespace FermeTranquille\Api;

final readonly class ApiResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {
    }
}
