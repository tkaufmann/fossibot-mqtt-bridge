<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use Fossibot\Utils\ModbusCrc;

/**
 * Read holding registers command (Modbus function 3).
 *
 * Implements Modbus function 3 to read multiple consecutive holding registers
 * from the device. Used for retrieving device status, settings, and sensor data.
 * Generates proper Modbus frame with CRC-16 checksum.
 */
class ReadRegistersCommand extends Command
{
    private const MODBUS_ADDRESS = 17;
    private const FUNCTION_READ_HOLDING_REGISTERS = 3;

    public function __construct(
        private readonly int $startRegister,
        private readonly int $count = 80
    ) {
        if ($startRegister < 0 || $startRegister > 65535) {
            throw new \InvalidArgumentException("Start register must be 0-65535, got: {$startRegister}");
        }
        if ($count < 1 || $count > 125) {
            throw new \InvalidArgumentException("Count must be 1-125 (Modbus limit), got: {$count}");
        }
        if (($startRegister + $count) > 65536) {
            throw new \InvalidArgumentException("Register range exceeds 65535: {$startRegister} + {$count}");
        }
    }

    public function getModbusBytes(): array
    {
        $command = [
            self::MODBUS_ADDRESS,
            self::FUNCTION_READ_HOLDING_REGISTERS,
            ($this->startRegister >> 8) & 0xFF,  // Start register high byte
            $this->startRegister & 0xFF,         // Start register low byte
            ($this->count >> 8) & 0xFF,          // Count high byte
            $this->count & 0xFF                  // Count low byte
        ];

        return ModbusCrc::appendCrc($command);
    }

    public function getResponseType(): CommandResponseType
    {
        return CommandResponseType::READ_RESPONSE;
    }

    public function getTargetRegister(): int
    {
        return $this->startRegister;
    }

    public function getDescription(): string
    {
        return "Read {$this->count} registers starting from {$this->startRegister}";
    }

    /**
     * Create a ReadRegistersCommand with default F2400 parameters (80 registers from 0).
     */
    public static function create(): self
    {
        return new self(startRegister: 0, count: 80);
    }
}