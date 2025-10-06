<?php

declare(strict_types=1);

namespace Fossibot\ValueObjects;

/**
 * Represents a Fossibot device from the API device list.
 */
final class Device
{
    public function __construct(
        public readonly string $deviceId,        // aa:bb:cc:dd:ee:ff (with colons)
        public readonly string $deviceName,      // F2400
        public readonly string $productId,       // some_product_id
        public readonly string $model,           // F2400
        public readonly int $onlineStatus,       // 1 = online, 0 = offline
        public readonly string $createdAt        // 2024-01-15T10:30:00Z
    ) {
    }

    /**
     * Get device MAC address without colons for MQTT topics.
     */
    public function getMqttId(): string
    {
        return str_replace(':', '', $this->deviceId);
    }

    /**
     * Check if device is currently online.
     */
    public function isOnline(): bool
    {
        return $this->onlineStatus === 1;
    }

    /**
     * Create Device from API response array.
     *
     * Supports both snake_case and camelCase field names for compatibility.
     */
    public static function fromApiResponse(array $data): self
    {
        // Extract model from device_name if model field missing (e.g., "F2400-B" -> "F2400")
        $deviceName = $data['device_name'] ?? $data['deviceName'] ?? '';
        $modelFallback = $deviceName ? preg_replace('/-.*$/', '', $deviceName) : 'unknown';

        return new self(
            deviceId: $data['device_id'] ?? $data['deviceId'] ?? '',
            deviceName: $deviceName,
            productId: $data['product_id'] ?? $data['productId'] ?? 'unknown',
            model: $data['model'] ?? $modelFallback,
            onlineStatus: (int) ( $data['mqtt_state'] ?? $data['onlineStatus'] ?? 0 ),
            createdAt: $data['created_at'] ?? $data['createdAt'] ?? date('c')
        );
    }

    /**
     * Convert Device to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'deviceid' => $this->productId,
            'name' => $this->deviceName,
            'mac' => $this->deviceId,
            'devicetype' => $this->model
        ];
    }

    /**
     * Create Device from array (deserialization).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            deviceId: $data['mac'],
            deviceName: $data['name'],
            productId: $data['deviceid'],
            model: $data['devicetype'],
            onlineStatus: 0, // Unknown after deserialization
            createdAt: ''    // Unknown after deserialization
        );
    }
}
