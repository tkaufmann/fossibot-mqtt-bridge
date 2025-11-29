<?php

// ABOUTME: Write single register command (Modbus function 6).
// PHP 7 compatible version without readonly properties.

declare(strict_types=1);

require_once __DIR__ . '/../Utils/ModbusCrc.php';
require_once __DIR__ . '/Command.php';
require_once __DIR__ . '/CommandResponseType.php';
require_once __DIR__ . '/../../RegisterType.php';

class WriteRegisterCommand extends Command
{
    const MODBUS_ADDRESS = 17;
    const FUNCTION_WRITE_SINGLE_REGISTER = 6;

    private $register;
    private $value;
    private $responseType;

    public function __construct($register, $value, $responseType)
    {
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

        $this->register = $register;
        $this->value = $value;
        $this->responseType = $responseType;
    }

    public function getModbusBytes()
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

    public function getResponseType()
    {
        return $this->responseType;
    }

    public function getRegisterType()
    {
        // Write commands trigger /client/04 responses with INPUT registers
        return RegisterType::INPUT;
    }

    public function getTargetRegister()
    {
        return $this->register;
    }

    public function getDescription()
    {
        return "Write value {$this->value} to register {$this->register}";
    }
}
