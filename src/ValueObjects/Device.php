<?php

declare( strict_types=1 );

namespace Fossibot\ValueObjects;

/**
 * Represents a Fossibot device from the API device list.
 */
final class Device {

	public function __construct(
		public readonly string $deviceId,        // aa:bb:cc:dd:ee:ff (with colons)
		public readonly string $deviceName,      // F2400
		public readonly string $productId,       // some_product_id
		public readonly string $model,           // F2400
		public readonly int $onlineStatus,       // 1 = online, 0 = offline
		public readonly string $createdAt        // 2024-01-15T10:30:00Z
	) {}

	/**
	 * Get device MAC address without colons for MQTT topics.
	 */
	public function getMqttId(): string {
		return str_replace( ':', '', $this->deviceId );
	}

	/**
	 * Check if device is currently online.
	 */
	public function isOnline(): bool {
		return $this->onlineStatus === 1;
	}

	/**
	 * Create Device from API response array.
	 */
	public static function fromApiResponse( array $data ): self {
		return new self(
			deviceId: $data['device_id'] ?? '',
			deviceName: $data['device_name'] ?? '',
			productId: $data['product_id'] ?? '',
			model: $data['model'] ?? '',
			onlineStatus: (int) ( $data['mqtt_state'] ?? 0 ),
			createdAt: $data['created_at'] ?? ''
		);
	}
}