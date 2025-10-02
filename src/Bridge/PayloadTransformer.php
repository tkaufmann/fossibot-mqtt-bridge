<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Fossibot\Device\DeviceState;
use Fossibot\Commands\Command;
use Fossibot\Commands\UsbOutputCommand;
use Fossibot\Commands\AcOutputCommand;
use Fossibot\Commands\DcOutputCommand;
use Fossibot\Commands\LedOutputCommand;
use Fossibot\Commands\ReadRegistersCommand;
use Fossibot\Commands\MaxChargingCurrentCommand;
use Fossibot\Commands\DischargeLowerLimitCommand;
use Fossibot\Commands\AcChargingUpperLimitCommand;

/**
 * Transforms MQTT payloads between Modbus binary and JSON formats.
 */
class PayloadTransformer
{
    /**
     * Parse Modbus binary payload to register array.
     *
     * Supports two response formats:
     * 1. Standard Modbus RTU Response: [SlaveID][FunctionCode][ByteCount][Data...][CRC]
     * 2. Full Request/Response: [SlaveID][FunctionCode][StartRegHigh][StartRegLow][CountHigh][CountLow][Data...][CRC]
     *
     * @param string $binaryPayload Raw Modbus response
     * @return array Register index => value
     */
    public function parseModbusPayload(string $binaryPayload): array
    {
        $length = strlen($binaryPayload);

        if ($length < 8) {
            return [];
        }

        // Parse header
        $header = unpack('CslaveId/CfunctionCode', substr($binaryPayload, 0, 2));

        // Check third byte to detect format
        $thirdByte = ord($binaryPayload[2]);

        if ($thirdByte === 0x00) {
            // Format 2: Full Request/Response with 6-byte header
            // [SlaveID][FunctionCode][StartRegHigh][StartRegLow][CountHigh][CountLow][Data...][CRC]
            $fullHeader = unpack(
                'CslaveId/CfunctionCode/nstartRegister/nregisterCount',
                substr($binaryPayload, 0, 6)
            );

            $dataStart = 6;
            $dataLength = $length - $dataStart - 2; // Subtract CRC (2 bytes)
            $data = substr($binaryPayload, $dataStart, $dataLength);

            $registers = [];
            $registerCount = strlen($data) / 2;

            for ($i = 0; $i < $registerCount; $i++) {
                $offset = $i * 2;
                if ($offset + 1 < strlen($data)) {
                    $high = ord($data[$offset]);
                    $low = ord($data[$offset + 1]);
                    $registers[$i] = ($high << 8) | $low;
                }
            }

            return $registers;

        } else {
            // Format 1: Standard Modbus RTU Response
            // [SlaveID][FunctionCode][ByteCount][Data...][CRC]
            $byteCount = $thirdByte;
            $dataStart = 3;
            $data = substr($binaryPayload, $dataStart, $byteCount);

            $registers = [];
            $registerCount = $byteCount / 2;

            for ($i = 0; $i < $registerCount; $i++) {
                $offset = $i * 2;
                if ($offset + 1 < strlen($data)) {
                    $high = ord($data[$offset]);
                    $low = ord($data[$offset + 1]);
                    $registers[$i] = ($high << 8) | $low;
                }
            }

            return $registers;
        }
    }

    /**
     * Convert register array to DeviceState object.
     *
     * @param array $registers Register values
     * @return DeviceState
     */
    public function registersToState(array $registers): DeviceState
    {
        $state = new DeviceState();
        $state->updateFromRegisters($registers);
        return $state;
    }

    /**
     * Convert DeviceState to JSON string.
     *
     * @param DeviceState $state
     * @return string JSON
     */
    public function stateToJson(DeviceState $state): string
    {
        return json_encode([
            'soc' => $state->soc,
            'inputWatts' => $state->inputWatts,
            'outputWatts' => $state->outputWatts,
            'dcInputWatts' => $state->dcInputWatts,
            'usbOutput' => $state->usbOutput,
            'acOutput' => $state->acOutput,
            'dcOutput' => $state->dcOutput,
            'ledOutput' => $state->ledOutput,
            'maxChargingCurrent' => $state->maxChargingCurrent,
            'dischargeLowerLimit' => $state->dischargeLowerLimit,
            'acChargingUpperLimit' => $state->acChargingUpperLimit,
            'timestamp' => $state->lastFullUpdate->format('c')
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Parse JSON command string to Command object.
     *
     * @param string $json JSON command
     * @return Command
     * @throws \InvalidArgumentException If action unknown or parameters invalid
     */
    public function jsonToCommand(string $json): Command
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['action'])) {
            throw new \InvalidArgumentException('Missing "action" field in command JSON');
        }

        $action = $data['action'];

        return match($action) {
            'usb_on' => UsbOutputCommand::enable(),
            'usb_off' => UsbOutputCommand::disable(),
            'ac_on' => AcOutputCommand::enable(),
            'ac_off' => AcOutputCommand::disable(),
            'dc_on' => DcOutputCommand::enable(),
            'dc_off' => DcOutputCommand::disable(),
            'led_on' => LedOutputCommand::enable(),
            'led_off' => LedOutputCommand::disable(),
            'read_settings' => ReadRegistersCommand::create(),
            'set_charging_current' => new MaxChargingCurrentCommand(
                (int)($data['amperes'] ?? throw new \InvalidArgumentException('Missing amperes parameter'))
            ),
            'set_discharge_limit' => new DischargeLowerLimitCommand(
                (float)($data['percentage'] ?? throw new \InvalidArgumentException('Missing percentage parameter'))
            ),
            'set_ac_charging_limit' => new AcChargingUpperLimitCommand(
                (float)($data['percentage'] ?? throw new \InvalidArgumentException('Missing percentage parameter'))
            ),
            default => throw new \InvalidArgumentException("Unknown action: $action")
        };
    }

    /**
     * Convert Command object to Modbus binary string.
     *
     * @param Command $command
     * @return string Binary Modbus payload
     */
    public function commandToModbus(Command $command): string
    {
        $bytes = $command->getModbusBytes();
        $binary = '';

        foreach ($bytes as $byte) {
            $binary .= chr($byte);
        }

        return $binary;
    }
}