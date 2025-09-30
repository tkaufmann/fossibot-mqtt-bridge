<?php
require 'vendor/autoload.php';

use Fossibot\Connection;
use Fossibot\Device\DeviceState;
use Fossibot\Device\DeviceStateManager;
use Fossibot\Commands\UsbOutputCommand;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

echo "Testing existing components...\n\n";

// Test 1: DeviceState
echo "Test 1: DeviceState\n";
$state = new DeviceState();
$state->updateFromRegisters([56 => 855, 41 => 64]);
assert($state->soc === 85.5, "SoC should be 85.5");
assert($state->usbOutput === true, "USB should be on");
echo "✅ DeviceState works\n\n";

// Test 2: DeviceStateManager
echo "Test 2: DeviceStateManager\n";
$manager = new DeviceStateManager();
$manager->updateDeviceState('7C2C67AB5F0E', [56 => 900]);
$state = $manager->getDeviceState('7C2C67AB5F0E');
assert($state->soc === 90.0, "SoC should be 90.0");
echo "✅ DeviceStateManager works\n\n";

// Test 3: Commands
echo "Test 3: Commands\n";
$command = UsbOutputCommand::enable();
$bytes = $command->getModbusBytes();
assert(count($bytes) === 8, "Should have 8 bytes");
echo "✅ Commands work\n\n";

// Test 4: Connection (requires credentials)
echo "Test 4: Connection (skipped - requires credentials)\n";
echo "   Note: Connection class will be tested in Phase 1\n\n";

echo "✅ All existing component tests passed!\n";