<?php

declare(strict_types=1);

namespace Fossibot\Device;

use Fossibot\Contracts\CommandExecutor;
use Fossibot\Commands\UsbOutputCommand;
use Fossibot\Commands\AcOutputCommand;
use Fossibot\Commands\DcOutputCommand;
use Fossibot\Commands\LedOutputCommand;
use Fossibot\Commands\ReadRegistersCommand;

/**
 * Smart facade for Device operations with command execution.
 *
 * Provides a clean, high-level API for device control by combining
 * a Device data object with a CommandExecutor for seamless command
 * execution. This separates concerns while offering convenient methods.
 */
class DeviceFacade
{
    public function __construct(
        private readonly Device $device,
        private readonly CommandExecutor $executor
    ) {}

    /**
     * Get the underlying device data object.
     *
     * @return Device Pure device value object
     */
    public function getDevice(): Device
    {
        return $this->device;
    }

    /**
     * Get device MAC address without colons for MQTT topics.
     *
     * @return string Device MAC address (12 hex characters)
     */
    public function getMqttId(): string
    {
        return $this->device->getMqttId();
    }

    /**
     * Get device name.
     *
     * @return string Human-readable device name
     */
    public function getDeviceName(): string
    {
        return $this->device->getDeviceName();
    }

    /**
     * Get device model.
     *
     * @return string Device model identifier
     */
    public function getModel(): string
    {
        return $this->device->getModel();
    }

    /**
     * Check if device is currently online.
     *
     * @return bool True if device is online and reachable
     */
    public function isOnline(): bool
    {
        return $this->device->isOnline();
    }

    // Command Execution Methods:

    /**
     * Turn on USB output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function usbOn(): void
    {
        $command = $this->device->usbOn();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Turn off USB output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function usbOff(): void
    {
        $command = $this->device->usbOff();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Turn on AC output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function acOn(): void
    {
        $command = $this->device->acOn();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Turn off AC output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function acOff(): void
    {
        $command = $this->device->acOff();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Turn on DC output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function dcOn(): void
    {
        $command = $this->device->dcOn();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Turn off DC output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function dcOff(): void
    {
        $command = $this->device->dcOff();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Turn on LED output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function ledOn(): void
    {
        $command = $this->device->ledOn();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Turn off LED output.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function ledOff(): void
    {
        $command = $this->device->ledOff();
        $this->executor->execute($this->getMqttId(), $command);
    }

    /**
     * Read device settings and status.
     *
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID is invalid
     */
    public function readSettings(): void
    {
        $command = $this->device->readSettings();
        $this->executor->execute($this->getMqttId(), $command);
    }
}