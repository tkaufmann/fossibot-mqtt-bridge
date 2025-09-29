<?php

declare(strict_types=1);

namespace Fossibot\Parsing;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Parser for Modbus responses from Fossibot devices.
 *
 * Handles parsing of binary Modbus RTU responses received via MQTT,
 * extracting register values and device states according to F2400 protocol.
 */
class ModbusResponseParser
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Parse binary Modbus response into register array.
     *
     * Expects Modbus RTU format:
     * [Device ID][Function Code][Byte Count][Data...][CRC16]
     *
     * @param string $binaryData Raw binary Modbus response
     * @return array Register values indexed by register number
     * @throws \InvalidArgumentException If data is invalid or malformed
     */
    public function parse(string $binaryData): array
    {
        if (empty($binaryData)) {
            throw new \InvalidArgumentException('Binary data cannot be empty');
        }

        if (strlen($binaryData) < 5) {
            throw new \InvalidArgumentException('Modbus response too short (minimum 5 bytes expected)');
        }

        $this->logger->debug('Parsing Modbus response', [
            'data_size' => strlen($binaryData),
            'data_hex' => bin2hex($binaryData)
        ]);

        // Parse Modbus RTU header
        $deviceId = unpack('C', $binaryData[0])[1];
        $functionCode = unpack('C', $binaryData[1])[1];

        $this->logger->debug('Modbus header parsed', [
            'device_id' => $deviceId,
            'function_code' => $functionCode
        ]);

        // Handle different function codes
        return match ($functionCode) {
            0x03 => $this->parseReadHoldingRegistersResponse($binaryData),
            0x06 => $this->parseWriteSingleRegisterResponse($binaryData),
            default => throw new \InvalidArgumentException("Unsupported Modbus function code: {$functionCode}")
        };
    }

    /**
     * Parse output states from register data.
     *
     * Extracts USB, AC, DC, and LED output states from Register 41 bitfield
     * according to F2400 device specification.
     *
     * @param array $registers Register values (index => value)
     * @return OutputStates Parsed output states
     */
    public function parseOutputStates(array $registers): OutputStates
    {
        if (!isset($registers[41])) {
            throw new \InvalidArgumentException('Register 41 (output states) not found in data');
        }

        $outputRegister = $registers[41];

        $this->logger->debug('Parsing output states from Register 41', [
            'register_value' => $outputRegister,
            'binary' => sprintf('%016b', $outputRegister)
        ]);

        // F2400 output state bit mapping (Register 41)
        $usbOutput = ($outputRegister & 0x01) !== 0;  // Bit 0
        $dcOutput = ($outputRegister & 0x02) !== 0;   // Bit 1
        $acOutput = ($outputRegister & 0x04) !== 0;   // Bit 2
        $ledOutput = ($outputRegister & 0x08) !== 0;  // Bit 3

        $states = new OutputStates($usbOutput, $acOutput, $dcOutput, $ledOutput);

        $this->logger->debug('Output states parsed', [
            'usb' => $usbOutput,
            'ac' => $acOutput,
            'dc' => $dcOutput,
            'led' => $ledOutput
        ]);

        return $states;
    }

    /**
     * Parse complete device status from register data.
     *
     * Extracts all relevant device information including battery state,
     * power values, and output states.
     *
     * @param array $registers Register values (index => value)
     * @return DeviceStatus Complete device status
     */
    public function parseDeviceStatus(array $registers): DeviceStatus
    {
        $this->logger->debug('Parsing complete device status', [
            'register_count' => count($registers)
        ]);

        // Extract key values with safe defaults
        $batteryPercent = isset($registers[56]) ? round($registers[56] / 1000 * 100, 1) : 0.0;
        $dcInput = $registers[4] ?? 0;
        $totalInput = $registers[6] ?? 0;
        $totalOutput = $registers[39] ?? 0;

        // Parse output states
        $outputStates = $this->parseOutputStates($registers);

        $status = new DeviceStatus(
            batteryPercent: $batteryPercent,
            dcInputPower: $dcInput,
            totalInputPower: $totalInput,
            totalOutputPower: $totalOutput,
            outputStates: $outputStates
        );

        $this->logger->debug('Device status parsed', [
            'battery_percent' => $batteryPercent,
            'dc_input' => $dcInput,
            'total_input' => $totalInput,
            'total_output' => $totalOutput
        ]);

        return $status;
    }

    /**
     * Parse Modbus Read Holding Registers (0x03) response.
     *
     * @param string $binaryData Raw binary response
     * @return array Register values
     */
    private function parseReadHoldingRegistersResponse(string $binaryData): array
    {
        if (strlen($binaryData) < 5) {
            throw new \InvalidArgumentException('Read Holding Registers response too short');
        }

        $byteCount = unpack('C', $binaryData[2])[1];
        $expectedLength = 3 + $byteCount + 2; // Header + Data + CRC

        if (strlen($binaryData) < $expectedLength) {
            throw new \InvalidArgumentException("Expected {$expectedLength} bytes, got " . strlen($binaryData));
        }

        $this->logger->debug('Parsing Read Holding Registers response', [
            'byte_count' => $byteCount,
            'register_count' => $byteCount / 2
        ]);

        // Extract register data (skip header, stop before CRC)
        $registerData = substr($binaryData, 3, $byteCount);
        $registers = [];

        // Parse 16-bit registers (big-endian)
        for ($i = 0; $i < $byteCount; $i += 2) {
            $registerIndex = $i / 2;
            $registerValue = unpack('n', substr($registerData, $i, 2))[1];
            $registers[$registerIndex] = $registerValue;
        }

        $this->logger->debug('Registers extracted', [
            'register_count' => count($registers),
            'sample_registers' => array_slice($registers, 0, 5, true)
        ]);

        return $registers;
    }

    /**
     * Parse Modbus Write Single Register (0x06) response.
     *
     * @param string $binaryData Raw binary response
     * @return array Register confirmation data
     */
    private function parseWriteSingleRegisterResponse(string $binaryData): array
    {
        if (strlen($binaryData) < 8) {
            throw new \InvalidArgumentException('Write Single Register response too short (expected 8 bytes)');
        }

        // Parse write response: [Device ID][Function Code][Register Address][Register Value][CRC]
        $registerAddress = unpack('n', substr($binaryData, 2, 2))[1];
        $registerValue = unpack('n', substr($binaryData, 4, 2))[1];

        $this->logger->debug('Write Single Register response parsed', [
            'register_address' => $registerAddress,
            'register_value' => $registerValue
        ]);

        return [
            'register_address' => $registerAddress,
            'register_value' => $registerValue,
            'confirmed' => true
        ];
    }
}

/**
 * Value object representing device output states.
 */
readonly class OutputStates
{
    public function __construct(
        public bool $usbOutput,
        public bool $acOutput,
        public bool $dcOutput,
        public bool $ledOutput
    ) {}

    /**
     * Check if any outputs are active.
     */
    public function hasAnyOutputActive(): bool
    {
        return $this->usbOutput || $this->acOutput || $this->dcOutput || $this->ledOutput;
    }

    /**
     * Get array representation for logging/debugging.
     */
    public function toArray(): array
    {
        return [
            'usb' => $this->usbOutput,
            'ac' => $this->acOutput,
            'dc' => $this->dcOutput,
            'led' => $this->ledOutput
        ];
    }
}

/**
 * Value object representing complete device status.
 */
readonly class DeviceStatus
{
    public function __construct(
        public float $batteryPercent,
        public int $dcInputPower,
        public int $totalInputPower,
        public int $totalOutputPower,
        public OutputStates $outputStates
    ) {}

    /**
     * Check if device is charging (input > output).
     */
    public function isCharging(): bool
    {
        return $this->totalInputPower > $this->totalOutputPower;
    }

    /**
     * Get array representation for logging/debugging.
     */
    public function toArray(): array
    {
        return [
            'battery_percent' => $this->batteryPercent,
            'dc_input_power' => $this->dcInputPower,
            'total_input_power' => $this->totalInputPower,
            'total_output_power' => $this->totalOutputPower,
            'is_charging' => $this->isCharging(),
            'output_states' => $this->outputStates->toArray()
        ];
    }
}