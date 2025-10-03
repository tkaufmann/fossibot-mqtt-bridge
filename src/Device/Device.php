<?php

declare(strict_types=1);

namespace Fossibot\Device;

use Fossibot\Commands\UsbOutputCommand;
use Fossibot\Commands\AcOutputCommand;
use Fossibot\Commands\DcOutputCommand;
use Fossibot\Commands\LedOutputCommand;
use Fossibot\Commands\ReadRegistersCommand;

/**
 * Represents a Fossibot device with command factory methods.
 */
class Device
{
    public function __construct(
        private readonly string $macAddress,
        private readonly string $deviceName,
        private readonly string $productId,
        private readonly string $model,
        private readonly int $onlineStatus,
        private readonly string $createdAt
    ) {}

    /**
     * Get device MAC address without colons for MQTT topics.
     */
    public function getMqttId(): string
    {
        return str_replace(':', '', $this->macAddress);
    }

    /**
     * Check if device is currently online.
     */
    public function isOnline(): bool
    {
        return $this->onlineStatus === 1;
    }

    /**
     * Get device name.
     */
    public function getDeviceName(): string
    {
        return $this->deviceName;
    }

    /**
     * Get device model.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    // Command Factory Methods:

    public function usbOn(): UsbOutputCommand
    {
        return UsbOutputCommand::enable();
    }

    public function usbOff(): UsbOutputCommand
    {
        return UsbOutputCommand::disable();
    }

    public function acOn(): AcOutputCommand
    {
        return AcOutputCommand::enable();
    }

    public function acOff(): AcOutputCommand
    {
        return AcOutputCommand::disable();
    }

    public function dcOn(): DcOutputCommand
    {
        return DcOutputCommand::enable();
    }

    public function dcOff(): DcOutputCommand
    {
        return DcOutputCommand::disable();
    }

    public function ledOn(): LedOutputCommand
    {
        return LedOutputCommand::enable();
    }

    public function ledOff(): LedOutputCommand
    {
        return LedOutputCommand::disable();
    }

    public function readSettings(): ReadRegistersCommand
    {
        return new ReadRegistersCommand(0, 80);
    }

    /**
     * Create Device from API response array.
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            macAddress: $data['device_id'] ?? '',
            deviceName: $data['device_name'] ?? '',
            productId: $data['product_id'] ?? '',
            model: $data['model'] ?? '',
            onlineStatus: (int) ($data['mqtt_state'] ?? 0),
            createdAt: $data['created_at'] ?? ''
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
            'mac' => $this->macAddress,
            'devicetype' => $this->model
        ];
    }

    /**
     * Create Device from array (deserialization).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            macAddress: $data['mac'],
            deviceName: $data['name'],
            productId: $data['deviceid'],
            model: $data['devicetype'],
            onlineStatus: 0, // Unknown after deserialization
            createdAt: ''    // Unknown after deserialization
        );
    }
}