<?php

/**
 * Debug script to verify register offset bug.
 *
 * The bug: Format 1 parsing assumes registers start at index 0,
 * but the device actually sends registers starting from register 0.
 *
 * According to ReadRegistersCommand::create(), we request:
 * - startRegister: 0
 * - count: 80
 *
 * This means the response contains registers 0-79.
 * But DeviceState.php expects:
 * - maxChargingCurrent at Register 20
 * - outputWatts at Register 39
 * - SoC at Register 56
 *
 * If Format 1 parsing is wrong, the array indices won't match.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Bridge\PayloadTransformer;

// Simulate a Modbus RTU response (Format 1)
// [SlaveID=17][FunctionCode=3][ByteCount=160][Data...][CRC]
// Data contains 80 registers (160 bytes)

$slaveId = 17;
$functionCode = 3;
$registerCount = 80;
$byteCount = $registerCount * 2; // 160 bytes

// Build fake payload
$payload = chr($slaveId) . chr($functionCode) . chr($byteCount);

// Add 80 registers with easily identifiable values
// Register 0 = 0, Register 1 = 1, Register 2 = 2, etc.
for ($i = 0; $i < $registerCount; $i++) {
    $high = ($i >> 8) & 0xFF;
    $low = $i & 0xFF;
    $payload .= chr($high) . chr($low);
}

// Add dummy CRC
$payload .= chr(0x00) . chr(0x00);

// Parse it
$transformer = new PayloadTransformer();
$registers = $transformer->parseModbusPayload($payload);

echo "Parsed registers:\n";
echo "Register count: " . count($registers) . "\n\n";

echo "First 10 registers:\n";
for ($i = 0; $i < 10; $i++) {
    echo "  registers[$i] = " . ($registers[$i] ?? 'MISSING') . "\n";
}

echo "\nCritical registers for DeviceState:\n";
echo "  registers[20] (maxChargingCurrent) = " . ($registers[20] ?? 'MISSING') . "\n";
echo "  registers[39] (outputWatts) = " . ($registers[39] ?? 'MISSING') . "\n";
echo "  registers[56] (SoC) = " . ($registers[56] ?? 'MISSING') . "\n";

echo "\nExpected values:\n";
echo "  registers[20] should be 20\n";
echo "  registers[39] should be 39\n";
echo "  registers[56] should be 56\n";

// Check if values match
$correct = true;
if (($registers[20] ?? null) !== 20) {
    echo "\n❌ FAIL: registers[20] is " . ($registers[20] ?? 'MISSING') . ", expected 20\n";
    $correct = false;
}
if (($registers[39] ?? null) !== 39) {
    echo "❌ FAIL: registers[39] is " . ($registers[39] ?? 'MISSING') . ", expected 39\n";
    $correct = false;
}
if (($registers[56] ?? null) !== 56) {
    echo "❌ FAIL: registers[56] is " . ($registers[56] ?? 'MISSING') . ", expected 56\n";
    $correct = false;
}

if ($correct) {
    echo "\n✅ All registers mapped correctly!\n";
} else {
    echo "\n❌ Register mapping is BROKEN - Format 1 parser has offset bug!\n";
}
