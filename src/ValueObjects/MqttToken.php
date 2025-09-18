<?php

declare(strict_types=1);

/**
 * ABOUTME: Value object representing an MQTT authentication token from Stage 3.
 */

namespace Fossibot\ValueObjects;

final readonly class MqttToken
{
    public function __construct(
        public string $token
    ) {
    }
}