<?php

declare(strict_types=1);

namespace Fossibot\Contracts;

/**
 * Contract for handling MQTT response messages from Fossibot devices.
 *
 * This interface allows components to listen to device responses and react
 * to parsed data updates or errors. Used for automatic state management
 * and real-time device monitoring.
 */
interface ResponseListener
{
    /**
     * Handle a successful response from a device.
     *
     * Called when a valid MQTT response is received and parsed successfully.
     * The registers array contains parsed Modbus register values from the device.
     *
     * @param string $topic MQTT topic the response came from (e.g., "7C2C67AB5F0E/device/response/client/04")
     * @param array $registers Parsed Modbus registers (index => value pairs)
     * @param string $macAddress Device MAC address that sent the response
     */
    public function onResponse(string $topic, array $registers, string $macAddress): void;

    /**
     * Handle an error in response processing.
     *
     * Called when response parsing fails, timeouts occur, or malformed
     * data is received from the device.
     *
     * @param string $topic MQTT topic where the error occurred
     * @param string $error Human-readable error description
     * @param string $macAddress Device MAC address associated with the error
     */
    public function onError(string $topic, string $error, string $macAddress): void;

    /**
     * Handle connection state changes for MQTT topics.
     *
     * Called when subscriptions are established or lost, allowing listeners
     * to track connectivity status.
     *
     * @param string $topic MQTT topic affected
     * @param bool $connected True if subscription is active, false if lost
     * @param string $macAddress Device MAC address associated with the topic
     */
    public function onConnectionStateChanged(string $topic, bool $connected, string $macAddress): void;
}