<?php

declare(strict_types=1);

namespace Fossibot\Device;

use Fossibot\Bridge\BridgeMetrics;

/**
 * Manages DeviceState instances for multiple devices.
 * Central registry for all device states with callback support.
 */
class DeviceStateManager
{
    private array $deviceStates = [];    // macAddress => DeviceState
    private array $callbacks = [];       // macAddress => callable[]
    private ?BridgeMetrics $metrics = null;

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
     * Set metrics collector (optional, for tracking spontaneous updates).
     */
    public function setMetrics(BridgeMetrics $metrics): void
    {
        $this->metrics = $metrics;
    }

    /**
     * Update device state from MQTT registers and trigger callbacks.
     *
     * @param string $macAddress Device MAC address
     * @param array $registers Modbus register array
     * @param string|null $sourceTopic MQTT topic that triggered this update
     * @param bool $wasCommandTriggered Was this update triggered by a command we sent?
     */
    public function updateDeviceState(string $macAddress, array $registers, ?string $sourceTopic = null, bool $wasCommandTriggered = false): void
    {
        $state = $this->getDeviceState($macAddress);
        $state->updateFromRegisters($registers, $sourceTopic, $wasCommandTriggered);

        // Track spontaneous updates in metrics (only for /client/04 responses not triggered by commands)
        $isImmediateResponse = $sourceTopic !== null && str_contains($sourceTopic, '/client/04');
        if ($this->metrics !== null && $isImmediateResponse && !$wasCommandTriggered) {
            $this->metrics->recordSpontaneousUpdate($macAddress);
        }

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
