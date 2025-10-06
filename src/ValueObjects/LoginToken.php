<?php

declare(strict_types=1);

namespace Fossibot\ValueObjects;

/**
 * Value object representing a login authentication token from Stage 2.
 */
final readonly class LoginToken
{
    public function __construct(
        public string $token
    ) {
    }
}
