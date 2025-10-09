<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use Fossibot\Utils\ModbusCrc;

/**
 * A command to read all Holding Registers (FC 03).
 * Used to fetch device settings.
 */
class ReadHoldingRegistersCommand extends Command
{
    private const MODBUS_ADDRESS = 17;
    private const FUNCTION_READ_HOLDING_REGISTERS = 3;
    private const START_REGISTER = 0;
    private const REGISTER_COUNT = 80;

    public static function create(): self
    {
        return new self();
    }

    public function getModbusBytes(): array
    {
        $command = [
            self::MODBUS_ADDRESS,
            self::FUNCTION_READ_HOLDING_REGISTERS,
            (self::START_REGISTER >> 8) & 0xFF,  // Start register high byte
            self::START_REGISTER & 0xFF,         // Start register low byte
            (self::REGISTER_COUNT >> 8) & 0xFF,  // Count high byte
            self::REGISTER_COUNT & 0xFF          // Count low byte
        ];

        return ModbusCrc::appendCrc($command);
    }

    public function getDescription(): string
    {
        return "Read All Holding Registers (FC 03)";
    }

    public function getResponseType(): CommandResponseType
    {
        return CommandResponseType::DELAYED;
    }

    public function getRegisterType(): RegisterType
    {
        return RegisterType::HOLDING; // FC 03 reads Holding Registers
    }

    public function getTargetRegister(): int
    {
        return self::START_REGISTER;
    }
}
