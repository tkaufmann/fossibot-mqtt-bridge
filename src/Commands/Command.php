<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * Abstract base class for all MQTT Modbus commands.
 *
 * Defines the interface for creating Modbus commands that can be sent
 * via MQTT to Fossibot devices. Each command generates proper Modbus
 * byte sequences with CRC checksums and handles different response patterns.
 */
abstract class Command
{
    /**
     * Generate the complete Modbus byte sequence including CRC.
     *
     * @return array Array of bytes ready for MQTT transmission
     */
    abstract public function getModbusBytes(): array;

    /**
     * Get the expected response pattern for this command.
     *
     * @return CommandResponseType How the device will respond to this command
     */
    abstract public function getResponseType(): CommandResponseType;

    /**
     * Get the primary register this command targets.
     *
     * @return int Register address (0-65535)
     */
    abstract public function getTargetRegister(): int;

    /**
     * Get human-readable description of what this command does.
     *
     * @return string Command description for logging/debugging
     */
    abstract public function getDescription(): string;
}
