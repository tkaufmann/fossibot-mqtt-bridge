<?php

declare(strict_types=1);

/**
 * ABOUTME: Value object representing a Stage 1 anonymous authorization request.
 */

namespace Fossibot\ValueObjects;

final readonly class AnonymousAuthRequest
{
    public function __construct(
        public string $method,
        public string $params,
        public string $spaceId,
        public int $timestamp
    ) {
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'params' => $this->params,
            'spaceId' => $this->spaceId,
            'timestamp' => $this->timestamp,
        ];
    }
}