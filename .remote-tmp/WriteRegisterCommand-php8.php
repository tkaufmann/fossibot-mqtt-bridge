<?php

// ABOUTME: Write single register command (Modbus function 6).

declare(strict_types=1);

require_once __DIR__ . '/../Utils/ModbusCrc.php';
require_once __DIR__ . '/Command.php';
require_once __DIR__ . '/CommandResponseType.php';
require_once __DIR__ . '/../../RegisterType.php';

class WriteRegisterCommand extends Command
{
    private const MODBUS_ADDRESS = 17;
    private const FUNCTION_WRITE_SINGLE_REGISTER = 6;

    public function __construct(
        private readonly int $register,
        private readonly int $value,
        private readonly CommandResponseType $responseType
    ) {
        if ($register < 0 || $register > 65535) {
            throw new Exception("Register must be 0-65535, got: {$register}");
        }
        if ($value < 0 || $value > 65535) {
            throw new Exception("Value must be 0-65535, got: {$value}");
        }

        // ⚠️ CRITICAL: Register 68 (Sleep Time) must NEVER be 0 - bricks device!
        if ($register === 68 && $value === 0) {
            throw new Exception(
                "CRITICAL: Register 68 (Sleep Time) cannot be set to 0 - this will brick the device! " .
                "Valid values: 5, 10, 30, 480 minutes"
            );
        }
    }

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

    public function getRegisterType(): RegisterType
    {
        // Write commands trigger /client/04 responses with INPUT registers
        return RegisterType::INPUT;
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
