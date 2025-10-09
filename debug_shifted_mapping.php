<?php

/**
 * Debug script: What if the register mapping in DeviceState.php is wrong?
 *
 * Symptom: maxChargingCurrent (supposedly Register 20) shows 83
 *          outputWatts (supposedly Register 39) should be 83
 *
 * Hypothesis: The REAL mapping is different!
 * - outputWatts might be at Register 20 (not 39)
 * - maxChargingCurrent might be at Register 1 or somewhere else
 *
 * Let's test if there's a constant offset in the register mapping.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Bridge\PayloadTransformer;
use Fossibot\Device\DeviceState;

echo "=== Testing Register Mapping Offset ===\n\n";

// Scenario: Device sends 81 registers starting at Register 0
// Parser creates array[0..80]
// But what if the DOCUMENTATION has wrong register numbers?

echo "Test: What if outputWatts is actually at Register 20 (not 39)?\n\n";

$slaveId = 17;
$functionCode = 3;
$byteCount = 162; // 81 registers = 162 bytes

// Build Format 1 response
$payload = chr($slaveId) . chr($functionCode) . chr($byteCount);

// Fill with test data
for ($i = 0; $i < 81; $i++) {
    // Put outputWatts value (83) at Register 20
    if ($i === 20) {
        $value = 83; // This is where outputWatts REALLY is!
    } elseif ($i === 39) {
        $value = 999; // This is NOT outputWatts (would be 83 if mapping was correct)
    } elseif ($i === 56) {
        $value = 950; // SoC
    } else {
        $value = $i;
    }

    $payload .= chr(($value >> 8) & 0xFF) . chr($value & 0xFF);
}

$payload .= chr(0) . chr(0); // CRC

// Parse
$transformer = new PayloadTransformer();
$registers = $transformer->parseModbusPayload($payload);

echo "Parsed " . count($registers) . " registers\n\n";

echo "Register array contents:\n";
echo "  registers[20] = " . ($registers[20] ?? 'MISSING') . "\n";
echo "  registers[39] = " . ($registers[39] ?? 'MISSING') . "\n";
echo "  registers[56] = " . ($registers[56] ?? 'MISSING') . "\n\n";

// Update DeviceState
$state = new DeviceState();
$state->updateFromRegisters($registers);

echo "DeviceState reads:\n";
echo "  maxChargingCurrent = " . $state->maxChargingCurrent . "A (from registers[20])\n";
echo "  outputWatts = " . $state->outputWatts . "W (from registers[39])\n";
echo "  soc = " . $state->soc . "% (from registers[56])\n\n";

if ($state->maxChargingCurrent === 83) {
    echo "❌ BUG CONFIRMED!\n";
    echo "   DeviceState.maxChargingCurrent = 83\n";
    echo "   This matches registers[20] = 83\n";
    echo "   Conclusion: DeviceState is correctly reading from Register 20,\n";
    echo "               but Register 20 contains outputWatts (83W), not maxChargingCurrent!\n";
    echo "   \n";
    echo "   Possible causes:\n";
    echo "   1. Documentation is wrong (Register 20 is NOT maxChargingCurrent)\n";
    echo "   2. Device sends different register layout than documented\n";
    echo "   3. Register numbers in ARCHITECTURE.md are offset by some amount\n";
} else {
    echo "✅ This scenario doesn't match the bug\n";
}
