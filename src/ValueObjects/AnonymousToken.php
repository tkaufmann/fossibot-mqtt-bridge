<?php

declare(strict_types=1);

/**
 * ABOUTME: Value object representing an anonymous authentication token from Stage 1.
 */

namespace Fossibot\ValueObjects;

final readonly class AnonymousToken
{
    public function __construct(
        public string $accessToken
    ) {
    }
}
