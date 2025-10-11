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
use Fossibot\Commands\ReadHoldingRegistersCommand;
use Fossibot\Commands\MaxChargingCurrentCommand;
use Fossibot\Commands\DischargeLowerLimitCommand;
use Fossibot\Commands\AcChargingUpperLimitCommand;
use Fossibot\Commands\AcSilentChargingCommand;
use Fossibot\Commands\UsbStandbyTimeCommand;
use Fossibot\Commands\AcStandbyTimeCommand;
use Fossibot\Commands\DcStandbyTimeCommand;
use Fossibot\Commands\ScreenRestTimeCommand;
use Fossibot\Commands\AcChargingTimerCommand;
use Fossibot\Commands\SleepTimeCommand;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Transforms MQTT payloads between Modbus binary and JSON formats.
 */
class PayloadTransformer
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }
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
        // ==> START: Temporäres Debug-Logging für Register-Offset-Analyse
        $byteCount = strlen($binaryPayload);
        $hexDump = bin2hex($binaryPayload);
        $this->logger->info('--- RAW MODBUS PAYLOAD ---', [
            'byte_count' => $byteCount,
            'register_count_expected' => ($byteCount - 5) / 2, // Annahme: 3 Header-Bytes + 2 CRC-Bytes
            'hex_dump' => $hexDump,
        ]);
        // <== END: Temporäres Debug-Logging

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
            $fullHeader = unpack(
                'CslaveId/CfunctionCode/nstartRegister/nregisterCount',
                substr($binaryPayload, 0, 6)
            );

            $dataStart = 6;
            $dataLength = $length - $dataStart - 2; // Subtract CRC (2 bytes)
            $registerCount = $dataLength / 2;

            if ($registerCount <= 0) {
                return [];
            }

            // More efficient & robust: Unpack all registers at once
            $data = substr($binaryPayload, $dataStart, $dataLength);
            // 'n*' reads all 2-byte big-endian shorts from the string
            $unpackedRegisters = unpack("n*", $data);

            if ($unpackedRegisters === false) {
                return [];
            }

            // Re-key the array based on the start register from the header.
            // This correctly handles any start offset.
            $startRegister = $fullHeader['startRegister'];
            return array_combine(
                range($startRegister, $startRegister + count($unpackedRegisters) - 1),
                array_values($unpackedRegisters)
            );
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
            // Battery & Power
            'soc' => $state->soc,
            'inputWatts' => $state->inputWatts,
            'outputWatts' => $state->outputWatts,
            'dcInputWatts' => $state->dcInputWatts,

            // Output States
            'usbOutput' => $state->usbOutput,
            'acOutput' => $state->acOutput,
            'dcOutput' => $state->dcOutput,
            'ledOutput' => $state->ledOutput,

            // Settings
            'maxChargingCurrent' => $state->maxChargingCurrent,
            'dischargeLowerLimit' => $state->dischargeLowerLimit,
            'acChargingUpperLimit' => $state->acChargingUpperLimit,
            'acSilentCharging' => $state->acSilentCharging,
            'usbStandbyTime' => $state->usbStandbyTime,
            'acStandbyTime' => $state->acStandbyTime,
            'dcStandbyTime' => $state->dcStandbyTime,
            'screenRestTime' => $state->screenRestTime,
            'acChargingTimer' => $state->acChargingTimer,
            'sleepTime' => $state->sleepTime,

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
            throw new InvalidArgumentException('Missing "action" field in command JSON');
        }

        $action = $data['action'];

        return match ($action) {
            'usb_on' => UsbOutputCommand::enable(),
            'usb_off' => UsbOutputCommand::disable(),
            'ac_on' => AcOutputCommand::enable(),
            'ac_off' => AcOutputCommand::disable(),
            'dc_on' => DcOutputCommand::enable(),
            'dc_off' => DcOutputCommand::disable(),
            'led_on' => LedOutputCommand::enable(),
            'led_off' => LedOutputCommand::disable(),
            'read_settings' => ReadRegistersCommand::create(),
            'read_holding_registers' => ReadHoldingRegistersCommand::create(),
            'set_charging_current' => new MaxChargingCurrentCommand(
                (int)($data['amperes'] ?? throw new InvalidArgumentException('Missing amperes parameter'))
            ),
            'set_discharge_limit' => new DischargeLowerLimitCommand(
                (int)round(($data['percentage'] ?? throw new InvalidArgumentException('Missing percentage parameter')) * 10)
            ),
            'set_ac_charging_limit' => new AcChargingUpperLimitCommand(
                (int)round(($data['percentage'] ?? throw new InvalidArgumentException('Missing percentage parameter')) * 10)
            ),
            'set_ac_silent_charging' => new AcSilentChargingCommand(
                (bool)($data['enabled'] ?? throw new InvalidArgumentException('Missing enabled parameter'))
            ),
            'set_usb_standby_time' => new UsbStandbyTimeCommand(
                (int)($data['minutes'] ?? throw new InvalidArgumentException('Missing minutes parameter'))
            ),
            'set_ac_standby_time' => new AcStandbyTimeCommand(
                (int)($data['minutes'] ?? throw new InvalidArgumentException('Missing minutes parameter'))
            ),
            'set_dc_standby_time' => new DcStandbyTimeCommand(
                (int)($data['minutes'] ?? throw new InvalidArgumentException('Missing minutes parameter'))
            ),
            'set_screen_rest_time' => new ScreenRestTimeCommand(
                (int)($data['seconds'] ?? throw new InvalidArgumentException('Missing seconds parameter'))
            ),
            'set_ac_charging_timer' => new AcChargingTimerCommand(
                (int)($data['minutes'] ?? throw new InvalidArgumentException('Missing minutes parameter'))
            ),
            'set_sleep_time' => new SleepTimeCommand(
                (int)($data['minutes'] ?? throw new InvalidArgumentException('Missing minutes parameter'))
            ),
            default => throw new InvalidArgumentException("Unknown action: $action")
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
