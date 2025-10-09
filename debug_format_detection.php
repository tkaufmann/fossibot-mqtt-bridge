<?php

/**
 * Debug script to verify format detection logic.
 *
 * The bug might be in the format detection:
 * - Format 1: Third byte = ByteCount (160 for 80 registers)
 * - Format 2: Third byte = 0x00 (startRegister high byte)
 *
 * Problem: If third byte is 0xA0 (160 decimal), it's NOT 0x00,
 * so it should be detected as Format 1.
 *
 * But what if the device is sending Format 2 responses?
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Bridge\PayloadTransformer;

echo "=== Testing Format Detection ===\n\n";

// Test 1: Format 1 with 80 registers (ByteCount = 160 = 0xA0)
echo "Test 1: Format 1 (Standard Modbus RTU)\n";
echo "Structure: [SlaveID=17][FunctionCode=3][ByteCount=160][Data...][CRC]\n";

$slaveId = 17;
$functionCode = 3;
$byteCount = 160; // 80 registers * 2 bytes

$payload1 = chr($slaveId) . chr($functionCode) . chr($byteCount);
for ($i = 0; $i < 80; $i++) {
    $payload1 .= chr(0) . chr($i); // Register value = index
}
$payload1 .= chr(0) . chr(0); // CRC

echo "Third byte value: " . ord($payload1[2]) . " (0x" . dechex(ord($payload1[2])) . ")\n";
echo "Should detect as: Format 1 (third byte != 0x00)\n";

$transformer = new PayloadTransformer();
$registers1 = $transformer->parseModbusPayload($payload1);

echo "Parsed register count: " . count($registers1) . "\n";
echo "registers[20] = " . ($registers1[20] ?? 'MISSING') . " (expected 20)\n";
echo "registers[39] = " . ($registers1[39] ?? 'MISSING') . " (expected 39)\n\n";

// Test 2: Format 2 with startRegister = 0
echo "Test 2: Format 2 (Full Request/Response)\n";
echo "Structure: [SlaveID=17][FunctionCode=3][StartRegHigh=0][StartRegLow=0][CountHigh=0][CountLow=80][Data...][CRC]\n";

$payload2 = chr($slaveId) . chr($functionCode);
$payload2 .= chr(0) . chr(0); // startRegister = 0
$payload2 .= chr(0) . chr(80); // count = 80
for ($i = 0; $i < 80; $i++) {
    $payload2 .= chr(0) . chr($i); // Register value = index
}
$payload2 .= chr(0) . chr(0); // CRC

echo "Third byte value: " . ord($payload2[2]) . " (0x" . dechex(ord($payload2[2])) . ")\n";
echo "Should detect as: Format 2 (third byte == 0x00)\n";

$registers2 = $transformer->parseModbusPayload($payload2);

echo "Parsed register count: " . count($registers2) . "\n";
echo "registers[20] = " . ($registers2[20] ?? 'MISSING') . " (expected 20)\n";
echo "registers[39] = " . ($registers2[39] ?? 'MISSING') . " (expected 39)\n\n";

// Test 3: Format 2 with startRegister = 20 (offset scenario)
echo "Test 3: Format 2 with offset (startRegister=20)\n";
echo "Structure: [SlaveID=17][FunctionCode=3][StartRegHigh=0][StartRegLow=20][CountHigh=0][CountLow=60][Data...][CRC]\n";

$payload3 = chr($slaveId) . chr($functionCode);
$payload3 .= chr(0) . chr(20); // startRegister = 20
$payload3 .= chr(0) . chr(60); // count = 60
for ($i = 0; $i < 60; $i++) {
    // Register value = 20 + index (so register 20 has value 20, register 21 has value 21, etc.)
    $payload3 .= chr(0) . chr(20 + $i);
}
$payload3 .= chr(0) . chr(0); // CRC

echo "Third byte value: " . ord($payload3[2]) . " (0x" . dechex(ord($payload3[2])) . ")\n";
echo "Should detect as: Format 2 (third byte == 0x00)\n";

$registers3 = $transformer->parseModbusPayload($payload3);

echo "Parsed register count: " . count($registers3) . "\n";
echo "registers[20] = " . ($registers3[20] ?? 'MISSING') . " (expected 20)\n";
echo "registers[39] = " . ($registers3[39] ?? 'MISSING') . " (expected 39)\n";
echo "registers[79] = " . ($registers3[79] ?? 'MISSING') . " (expected 79)\n\n";

// Check if this is the bug scenario
if (isset($registers3[20]) && $registers3[20] !== 20) {
    echo "❌ FOUND THE BUG! Format 2 with offset creates wrong mapping!\n";
    echo "   registers[20] contains value " . $registers3[20] . " instead of 20\n";
} else {
    echo "✅ Format 2 offset handling looks correct\n";
}
