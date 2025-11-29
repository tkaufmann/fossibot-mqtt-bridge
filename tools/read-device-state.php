#!/usr/bin/env php
<?php

declare(strict_types=1);

// ABOUTME: Standalone script to read and parse Fossibot device state from direct MQTT messages.
// This tool demonstrates parsing MODBUS-over-MQTT data without requiring cloud authentication.
//
// == HOW IT WORKS ==
//
// Fossibot devices can connect directly to a local MQTT broker instead of the cloud.
// By redirecting the DNS name "mqtt.sydpower.com" to a local MQTT server, the battery
// will send its data directly to your local network without internet dependency.
//
// DNS Setup:
//   1. Configure your router's DNS to map: mqtt.sydpower.com → <local-mqtt-server-ip>
//   2. OR: Create a WiFi hotspot with custom DNS settings
//   3. The device will obtain MQTT credentials from the cloud initially, then connect locally
//
// MQTT Server Requirements:
//   - Port: 1883 (standard MQTT, unencrypted)
//   - Authentication: Anonymous access (no username/password required)
//   - The device will NOT connect if authentication is required
//
// == MQTT TOPICS ==
//
// The device publishes to topics based on its MAC address (uppercase, no colons).
// Example for MAC 7C:2C:67:AB:5F:0E:
//
//   7C2C67AB5F0E/device/response/client/04    - Input Registers (FC 04, realtime data)
//                                                 Contains: SoC, power, output states
//                                                 Published every ~10 seconds
//
//   7C2C67AB5F0E/device/response/client/03    - Holding Registers (FC 03, settings)
//                                                 Contains: limits, timers, configuration
//                                                 Only on explicit request
//
//   7C2C67AB5F0E/device/response/state        - Device state changes (0x30=offline, 0x31=online)
//
//   7C2C67AB5F0E/client/request/data          - Send commands to device (write registers)
//
// == USAGE EXAMPLES ==
//
// Read live data from MQTT broker:
//   mosquitto_sub -h ipsymcon.office.timkaufmann.de -t "7C2C67AB5F0E/device/response/client/04" -C 1 | \
//     php tools/read-device-state.php -
//
// Monitor continuously:
//   mosquitto_sub -h ipsymcon.office.timkaufmann.de -t "7C2C67AB5F0E/device/response/client/04" | \
//     while read line; do echo "$line" | php tools/read-device-state.php -; done
//
// Parse hex string directly:
//   php tools/read-device-state.php "110400000050..."
//
// == PROTOCOL ==
//
// The device speaks MODBUS-over-MQTT:
//   - Function Code 04 (0x04): Read Input Registers (realtime sensor data)
//   - Function Code 03 (0x03): Read Holding Registers (settings)
//   - Function Code 06 (0x06): Write Single Holding Register (control)
//
// Each MQTT message contains a complete MODBUS frame:
//   [SlaveAddr][FuncCode][StartReg][RegCount][Data...][CRC16]
//
// This script parses these frames and extracts all device values.
//
// == REFERENCE ==
//
// For more details, see:
//   - https://github.com/schauveau/sydpower-mqtt/blob/main/MQTT-MODBUS.md
//   - docs/internal/ARCHITECTURE.md (register mappings)

require_once __DIR__ . '/../vendor/autoload.php';

use Fossibot\Bridge\PayloadTransformer;
use Fossibot\Device\DeviceState;
use Fossibot\Commands\RegisterType;

/**
 * Simple wrapper class for reading Fossibot device state from MODBUS payloads.
 */
class FossibotDeviceReader
{
    private PayloadTransformer $transformer;
    private DeviceState $state;

    public function __construct()
    {
        $this->transformer = new PayloadTransformer();
        $this->state = new DeviceState();
    }

    /**
     * Parse MODBUS binary payload and update device state.
     *
     * @param string $binaryPayload Raw MODBUS message (FC 04 or FC 03)
     * @return bool Success
     */
    public function parsePayload(string $binaryPayload): bool
    {
        if (strlen($binaryPayload) < 8) {
            return false;
        }

        // Extract function code to determine register type
        $functionCode = ord($binaryPayload[1]);

        $registerType = match ($functionCode) {
            0x03 => RegisterType::HOLDING,  // Holding registers (settings)
            0x04 => RegisterType::INPUT,     // Input registers (realtime data)
            default => null
        };

        if ($registerType === null) {
            return false;
        }

        // Parse registers from binary payload
        $registers = $this->transformer->parseModbusPayload($binaryPayload);

        if (empty($registers)) {
            return false;
        }

        // Update device state
        $this->state->updateFromRegisters($registers, $registerType);

        return true;
    }

    /**
     * Get state of charge (battery percentage).
     */
    public function getSoc(): float
    {
        return $this->state->soc;
    }

    /**
     * Get total input power in watts.
     */
    public function getInputWatts(): int
    {
        return $this->state->inputWatts;
    }

    /**
     * Get total output power in watts.
     */
    public function getOutputWatts(): int
    {
        return $this->state->outputWatts;
    }

    /**
     * Get DC input power in watts.
     */
    public function getDcInputWatts(): int
    {
        return $this->state->dcInputWatts;
    }

    /**
     * Check if USB output is enabled.
     */
    public function isUsbOutputOn(): bool
    {
        return $this->state->usbOutput;
    }

    /**
     * Check if AC output is enabled.
     */
    public function isAcOutputOn(): bool
    {
        return $this->state->acOutput;
    }

    /**
     * Check if DC output is enabled.
     */
    public function isDcOutputOn(): bool
    {
        return $this->state->dcOutput;
    }

    /**
     * Check if LED output is enabled.
     */
    public function isLedOutputOn(): bool
    {
        return $this->state->ledOutput;
    }

    /**
     * Get maximum charging current in amperes.
     */
    public function getMaxChargingCurrent(): int
    {
        return $this->state->maxChargingCurrent;
    }

    /**
     * Get discharge lower limit percentage.
     */
    public function getDischargeLowerLimit(): float
    {
        return $this->state->dischargeLowerLimit;
    }

    /**
     * Get AC charging upper limit percentage.
     */
    public function getAcChargingUpperLimit(): float
    {
        return $this->state->acChargingUpperLimit;
    }

    /**
     * Check if AC silent charging is enabled.
     */
    public function isAcSilentChargingOn(): bool
    {
        return $this->state->acSilentCharging;
    }

    /**
     * Get USB standby timeout in minutes.
     */
    public function getUsbStandbyTime(): int
    {
        return $this->state->usbStandbyTime;
    }

    /**
     * Get AC standby timeout in minutes.
     */
    public function getAcStandbyTime(): int
    {
        return $this->state->acStandbyTime;
    }

    /**
     * Get DC standby timeout in minutes.
     */
    public function getDcStandbyTime(): int
    {
        return $this->state->dcStandbyTime;
    }

    /**
     * Get screen rest timeout in seconds.
     */
    public function getScreenRestTime(): int
    {
        return $this->state->screenRestTime;
    }

    /**
     * Get AC charging timer countdown in minutes (0 = disabled).
     */
    public function getAcChargingTimer(): int
    {
        return $this->state->acChargingTimer;
    }

    /**
     * Get sleep timeout in minutes.
     */
    public function getSleepTime(): int
    {
        return $this->state->sleepTime;
    }

    /**
     * Get last update timestamp.
     */
    public function getLastUpdate(): \DateTime
    {
        return $this->state->lastFullUpdate;
    }

    /**
     * Export complete state as JSON string.
     *
     * @param bool $prettyPrint Format JSON with indentation
     * @return string JSON
     */
    public function toJson(bool $prettyPrint = false): string
    {
        $data = [
            // Battery & Power
            'soc' => $this->state->soc,
            'inputWatts' => $this->state->inputWatts,
            'outputWatts' => $this->state->outputWatts,
            'dcInputWatts' => $this->state->dcInputWatts,

            // Output States
            'usbOutput' => $this->state->usbOutput,
            'acOutput' => $this->state->acOutput,
            'dcOutput' => $this->state->dcOutput,
            'ledOutput' => $this->state->ledOutput,

            // Settings
            'maxChargingCurrent' => $this->state->maxChargingCurrent,
            'dischargeLowerLimit' => $this->state->dischargeLowerLimit,
            'acChargingUpperLimit' => $this->state->acChargingUpperLimit,
            'acSilentCharging' => $this->state->acSilentCharging,
            'usbStandbyTime' => $this->state->usbStandbyTime,
            'acStandbyTime' => $this->state->acStandbyTime,
            'dcStandbyTime' => $this->state->dcStandbyTime,
            'screenRestTime' => $this->state->screenRestTime,
            'acChargingTimer' => $this->state->acChargingTimer,
            'sleepTime' => $this->state->sleepTime,

            'timestamp' => $this->state->lastFullUpdate->format('c')
        ];

        $flags = JSON_THROW_ON_ERROR;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }

    /**
     * Get raw DeviceState object for advanced access.
     */
    public function getState(): DeviceState
    {
        return $this->state;
    }
}

// Example usage when run directly
if (basename($argv[0] ?? '') === 'read-device-state.php') {
    echo "Fossibot Device State Reader\n";
    echo "============================\n\n";

    if ($argc < 2) {
        echo "Usage:\n";
        echo "  {$argv[0]} <hex_payload>\n\n";
        echo "Example:\n";
        echo "  {$argv[0]} 110400000050...\n\n";
        echo "Or pipe MQTT message:\n";
        echo "  mosquitto_sub -h localhost -t '7C2C67AB5F0E/device/response/client/04' | xxd -r -p | php {$argv[0]} -\n";
        exit(1);
    }

    $reader = new FossibotDeviceReader();

    // Read from stdin or argument
    if ($argv[1] === '-') {
        $binaryPayload = file_get_contents('php://stdin');
    } else {
        // Convert hex string to binary
        $hexPayload = preg_replace('/\s+/', '', $argv[1]);
        $binaryPayload = hex2bin($hexPayload);
    }

    if ($binaryPayload === false) {
        echo "Error: Invalid hex payload\n";
        exit(1);
    }

    if (!$reader->parsePayload($binaryPayload)) {
        echo "Error: Failed to parse payload\n";
        exit(1);
    }

    // Output as pretty JSON
    echo $reader->toJson(true) . "\n";

    echo "\nIndividual getters demo:\n";
    echo "  SoC: " . $reader->getSoc() . "%\n";
    echo "  Output Power: " . $reader->getOutputWatts() . "W\n";
    echo "  USB Output: " . ($reader->isUsbOutputOn() ? 'ON' : 'OFF') . "\n";
    echo "  AC Output: " . ($reader->isAcOutputOn() ? 'ON' : 'OFF') . "\n";
}
