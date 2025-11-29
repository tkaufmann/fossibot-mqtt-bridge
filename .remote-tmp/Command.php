<?php

// ABOUTME: Abstract base class for all MQTT Modbus commands.
// Simplified for IP-Symcon compatibility (no type hints in abstract methods).

declare(strict_types=1);

abstract class Command
{
    /**
     * Generate the complete Modbus byte sequence including CRC.
     */
    abstract public function getModbusBytes();

    /**
     * Get the expected response pattern for this command.
     */
    abstract public function getResponseType();

    /**
     * Get the register type that this command's response will contain.
     */
    abstract public function getRegisterType();

    /**
     * Get the primary register this command targets.
     */
    abstract public function getTargetRegister();

    /**
     * Get human-readable description of what this command does.
     */
    abstract public function getDescription();
}
