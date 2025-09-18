<?php

declare( strict_types=1 );

namespace Fossibot\ValueObjects;

/**
 * Value object representing a Stage 3 MQTT token request.
 */
final readonly class MqttTokenRequest {

	public function __construct(
		public string $method,
		public string $params,
		public string $spaceId,
		public int $timestamp,
		public string $token
	) {
	}

	public function toArray(): array {
		return [
			'method' => $this->method,
			'params' => $this->params,
			'spaceId' => $this->spaceId,
			'timestamp' => $this->timestamp,
			'token' => $this->token,
		];
	}
}