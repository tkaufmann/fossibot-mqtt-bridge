<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use Fossibot\Utils\ModbusCrc;

/**
 * Read holding registers command (Modbus function 3).
 */
class ReadRegistersCommand extends Command
{
    private const MODBUS_ADDRESS = 17;
    private const FUNCTION_READ_HOLDING_REGISTERS = 3;

    public function __construct(
        private readonly int $startRegister,
        private readonly int $count = 80
    ) {}

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
}