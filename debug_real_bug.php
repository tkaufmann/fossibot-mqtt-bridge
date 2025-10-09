<?php

/**
 * Debug script to reproduce the exact bug scenario:
 * - maxChargingCurrent (Register 20) shows 83
 * - outputWatts (Register 39) should be 83
 *
 * This suggests registers are offset by 19 positions!
 * Register 20 is reading from position 39 (39 - 20 = 19 offset)
 *
 * Hypothesis: The device sends registers starting from Register 19,
 * but the parser treats them as starting from Register 0.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Bridge\PayloadTransformer;
use Fossibot\Device\DeviceState;

echo "=== Reproducing the Bug ===\n\n";

echo "Scenario: Device sends 81 registers starting from Register 0\n";
echo "BUT the response might actually contain different start register!\n\n";

// Test: What if the device sends Format 2 with startRegister=19?
echo "Test: Format 2 response with startRegister = 19\n";
echo "This would cause:\n";
echo "  - Register 19 data written to array index 19\n";
echo "  - Register 20 data written to array index 20\n";
echo "  - Register 39 data written to array index 39\n";
echo "  - Register 56 data written to array index 56\n\n";

$slaveId = 17;
$functionCode = 3;
$startRegister = 19; // HYPOTHESIS: Device starts at 19, not 0!
$registerCount = 81;

// Build Format 2 response
$payload = chr($slaveId) . chr($functionCode);
$payload .= chr(($startRegister >> 8) & 0xFF) . chr($startRegister & 0xFF); // startRegister = 19
$payload .= chr(($registerCount >> 8) & 0xFF) . chr($registerCount & 0xFF); // count = 81

// Fill with test data
for ($i = 0; $i < $registerCount; $i++) {
    $actualRegister = $startRegister + $i;

    // Special values to test:
    // - Register 39 (outputWatts) = 83
    // - Register 20 (maxChargingCurrent) = should be 4, but will read 83 if offset!

    if ($actualRegister === 39) {
        $value = 83; // outputWatts = 83W
    } elseif ($actualRegister === 20) {
        $value = 4; // maxChargingCurrent = 4A
    } elseif ($actualRegister === 56) {
        $value = 950; // SoC = 95.0%
    } else {
        $value = $actualRegister; // Others = register number
    }

    $payload .= chr(($value >> 8) & 0xFF) . chr($value & 0xFF);
}

$payload .= chr(0) . chr(0); // CRC

echo "Third byte: " . ord($payload[2]) . " (0x" . sprintf('%02X', ord($payload[2])) . ")\n";
echo "Expected format detection: Format 2 (third byte == 0x00)\n\n";

// Parse
$transformer = new PayloadTransformer();
$registers = $transformer->parseModbusPayload($payload);

echo "Parsed " . count($registers) . " registers\n\n";

echo "Register values:\n";
echo "  registers[20] (maxChargingCurrent) = " . ($registers[20] ?? 'MISSING') . " (expected 4)\n";
echo "  registers[39] (outputWatts) = " . ($registers[39] ?? 'MISSING') . " (expected 83)\n";
echo "  registers[56] (SoC) = " . ($registers[56] ?? 'MISSING') . " (expected 950)\n\n";

// Convert to DeviceState
$state = new DeviceState();
$state->updateFromRegisters($registers);

echo "DeviceState values:\n";
echo "  maxChargingCurrent = " . $state->maxChargingCurrent . "A\n";
echo "  outputWatts = " . $state->outputWatts . "W\n";
echo "  soc = " . $state->soc . "%\n\n";

// Check for the bug
if ($state->maxChargingCurrent === 83) {
    echo "❌ BUG CONFIRMED!\n";
    echo "   maxChargingCurrent shows 83 (value from outputWatts)\n";
    echo "   This means Register 20 is reading from array position 39!\n";
    echo "   Root cause: Device sends registers 19-99, parser creates array indices 19-99,\n";
    echo "               but code expects indices 0-80\n";
} elseif ($state->maxChargingCurrent === 4) {
    echo "✅ Values are correct - no bug with this scenario\n";
} else {
    echo "⚠️  Unexpected value: " . $state->maxChargingCurrent . "\n";
}
