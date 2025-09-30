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
     * @param string $binaryPayload Raw Modbus response
     * @return array Register index => value
     */
    public function parseModbusPayload(string $binaryPayload): array
    {
        if (strlen($binaryPayload) < 3) {
            return [];
        }

        // Modbus RTU response: [Slave ID][Function Code][Byte Count][Data...][CRC]
        $header = unpack('CslaveId/CfunctionCode/CbyteCount', substr($binaryPayload, 0, 3));
        $byteCount = $header['byteCount'];

        $dataStart = 3;
        $dataEnd = $dataStart + $byteCount;

        if ($dataEnd > strlen($binaryPayload)) {
            return [];
        }

        $data = substr($binaryPayload, $dataStart, $byteCount);
        $registers = [];

        // Parse 16-bit registers (big-endian)
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