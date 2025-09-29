<?php
declare(strict_types=1);

namespace Fossibot\Device;

/**
 * Manages DeviceState instances for multiple devices.
 * Central registry for all device states with callback support.
 */
class DeviceStateManager
{
    private array $deviceStates = [];    // macAddress => DeviceState
    private array $callbacks = [];       // macAddress => callable[]

    /**
     * Get DeviceState for a MAC address.
     * Creates new instance if not exists.
     */
    public function getDeviceState(string $macAddress): DeviceState
    {
        if (!isset($this->deviceStates[$macAddress])) {
            $this->deviceStates[$macAddress] = new DeviceState();
        }

        return $this->deviceStates[$macAddress];
    }

    /**
     * Update device state from MQTT registers and trigger callbacks.
     */
    public function updateDeviceState(string $macAddress, array $registers): void
    {
        $state = $this->getDeviceState($macAddress);
        $state->updateFromRegisters($registers);

        // Trigger callbacks for this device
        if (isset($this->callbacks[$macAddress])) {
            foreach ($this->callbacks[$macAddress] as $callback) {
                $callback($state);
            }
        }
    }

    /**
     * Register callback for device state changes.
     */
    public function onDeviceUpdate(string $macAddress, callable $callback): void
    {
        if (!isset($this->callbacks[$macAddress])) {
            $this->callbacks[$macAddress] = [];
        }

        $this->callbacks[$macAddress][] = $callback;
    }

    /**
     * Get all managed device MAC addresses.
     */
    public function getManagedDevices(): array
    {
        return array_keys($this->deviceStates);
    }
}