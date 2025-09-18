<?php

declare(strict_types=1);

/**
 * ABOUTME: Value object representing a login authentication token from Stage 2.
 */

namespace Fossibot\ValueObjects;

final readonly class LoginToken
{
    public function __construct(
        public string $token
    ) {
    }
}