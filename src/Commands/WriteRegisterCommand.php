<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use Fossibot\Utils\ModbusCrc;

/**
 * Write single register command (Modbus function 6).
 */
class WriteRegisterCommand extends Command
{
    private const MODBUS_ADDRESS = 17;
    private const FUNCTION_WRITE_SINGLE_REGISTER = 6;

    public function __construct(
        private readonly int $register,
        private readonly int $value,
        private readonly CommandResponseType $responseType
    ) {}

    public function getModbusBytes(): array
    {
        $command = [
            self::MODBUS_ADDRESS,
            self::FUNCTION_WRITE_SINGLE_REGISTER,
            ($this->register >> 8) & 0xFF,  // Register high byte
            $this->register & 0xFF,         // Register low byte
            ($this->value >> 8) & 0xFF,     // Value high byte
            $this->value & 0xFF             // Value low byte
        ];

        return ModbusCrc::appendCrc($command);
    }

    public function getResponseType(): CommandResponseType
    {
        return $this->responseType;
    }

    public function getTargetRegister(): int
    {
        return $this->register;
    }

    public function getDescription(): string
    {
        return "Write value {$this->value} to register {$this->register}";
    }
}