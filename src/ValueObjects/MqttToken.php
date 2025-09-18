<?php

declare( strict_types=1 );

namespace Fossibot\ValueObjects;

/**
 * Value object representing an MQTT authentication token from Stage 3.
 */
final readonly class MqttToken {

	public function __construct(
		public string $accessToken
	) {
	}
}