<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\PayloadTransformer;
use Fossibot\Commands\UsbOutputCommand;

echo "Testing PayloadTransformer...\n\n";

$transformer = new PayloadTransformer();

// Test 1: Parse Modbus
echo "Test 1: parseModbusPayload\n";
$modbusHex = '1103a2' . str_repeat('0000', 81); // SlaveID=0x11, FunctionCode=0x03, ByteCount=0xA2 (162 bytes = 81 registers)
$modbus = hex2bin($modbusHex);
$registers = $transformer->parseModbusPayload($modbus);
assert(count($registers) === 81, "Expected 81 registers");
echo "✅ Parsed 81 registers\n\n";

// Test 2: Registers to State
echo "Test 2: registersToState\n";
$registers = [
    56 => 855,  // SoC = 85.5%
    41 => 0b0000000001000000,  // USB=1
    20 => 12
];
$state = $transformer->registersToState($registers);
assert($state->soc === 85.5, "SoC should be 85.5");
assert($state->usbOutput === true, "USB should be on");
echo "✅ State conversion correct\n\n";

// Test 3: State to JSON
echo "Test 3: stateToJson\n";
$json = $transformer->stateToJson($state);
$decoded = json_decode($json, true);
assert($decoded['soc'] === 85.5, "JSON SoC should be 85.5");
assert($decoded['usbOutput'] === true, "JSON USB should be true");
assert(isset($decoded['inputWatts']), "JSON should have inputWatts");
assert(isset($decoded['outputWatts']), "JSON should have outputWatts");
assert(isset($decoded['dcInputWatts']), "JSON should have dcInputWatts");
echo "✅ JSON: " . substr($json, 0, 100) . "...\n\n";

// Test 4: JSON to Command
echo "Test 4: jsonToCommand\n";
$commandJson = '{"action":"usb_on"}';
$command = $transformer->jsonToCommand($commandJson);
assert($command instanceof UsbOutputCommand, "Should be UsbOutputCommand");
echo "✅ Command created: " . get_class($command) . "\n\n";

// Test 5: Command to Modbus
echo "Test 5: commandToModbus\n";
$modbus = $transformer->commandToModbus($command);
$hex = bin2hex($modbus);
echo "✅ Modbus hex: $hex\n";
assert(strlen($modbus) === 8, "Should be 8 bytes");

echo "\n✅ All PayloadTransformer tests passed!\n";