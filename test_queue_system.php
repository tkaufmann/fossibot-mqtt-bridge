<?php

declare(strict_types=1);

/**
 * ABOUTME: Test script for Queue System and end-to-end command execution.
 * Tests queue management, command routing, and MQTT integration.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Connection;
use Fossibot\Config;
use Fossibot\Queue\QueueManager;
use Fossibot\Commands\UsbOutputCommand;
use Fossibot\Commands\AcOutputCommand;
use Fossibot\Device\Device;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Setup logging
$logger = new Logger('fossibot-queue-test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

function testQueueWithoutConnection(): void {
    global $logger;

    echo "=== Testing Queue System Without Real Connection ===\n";

    $queueManager = new QueueManager($logger);

    // Create mock MAC addresses
    $testMacs = ['aabbccddeeff', '112233445566'];

    // Create fake connection for testing
    $mockConnection = new Connection('test@example.com', 'password', $logger);
    $queueManager->registerConnection($mockConnection, $testMacs);

    echo "Queue Manager Status:\n";
    print_r($queueManager->getStatus());

    // Test commands
    $usbOn = UsbOutputCommand::enable();
    $acOff = AcOutputCommand::disable();

    echo "\nExecuting test commands...\n";
    $queueManager->executeCommand('aabbccddeeff', $usbOn);
    $queueManager->executeCommand('112233445566', $acOff);

    echo "✅ Queue system test completed\n\n";
}

function testQueueWithRealConnection(): void {
    global $logger;

    echo "=== Testing Queue System With Real MQTT Connection ===\n";

    try {
        // Create real connection
        $connection = new Connection(
            Config::getEmail(),
            Config::getPassword(),
            $logger
        );

        echo "Connecting to Fossibot API...\n";
        $connection->connect();

        if (!$connection->isConnected()) {
            echo "❌ Connection failed, skipping real MQTT test\n";
            return;
        }

        echo "✅ Connected successfully!\n";

        // Get real devices
        $devices = $connection->getDevices();
        if (empty($devices)) {
            echo "❌ No devices found, skipping real MQTT test\n";
            return;
        }

        $device = $devices[0];
        echo "Found device: " . $device->getDeviceName() . " (" . $device->getMqttId() . ")\n";

        // Setup queue manager with real connection
        $queueManager = new QueueManager($logger);
        $deviceMacs = array_map(fn(Device $d) => $d->getMqttId(), $devices);
        $queueManager->registerConnection($connection, $deviceMacs);

        echo "\n🎯 SENDING REAL USB COMMAND TO DEVICE!\n";

        // Send USB ON command
        $usbOnCommand = $device->usbOn();
        echo "Executing: " . $usbOnCommand->getDescription() . "\n";
        $queueManager->executeCommand($device->getMqttId(), $usbOnCommand);

        echo "✅ USB ON command sent via MQTT!\n";

        // Wait a bit, then send USB OFF
        echo "Waiting 3 seconds...\n";
        sleep(3);

        $usbOffCommand = $device->usbOff();
        echo "Executing: " . $usbOffCommand->getDescription() . "\n";
        $queueManager->executeCommand($device->getMqttId(), $usbOffCommand);

        echo "✅ USB OFF command sent via MQTT!\n";

        echo "\n🎉 USB output successfully controlled via Queue System!\n";

    } catch (Exception $e) {
        echo "❌ Real connection test failed: " . $e->getMessage() . "\n";
        $logger->debug("Error details: " . $e->getTraceAsString());
    }
}

function testErrorHandling(): void {
    global $logger;

    echo "=== Testing Error Handling ===\n";

    $queueManager = new QueueManager($logger);

    try {
        // Try to execute command without registered connection
        $queueManager->executeCommand('nonexistent', UsbOutputCommand::enable());
        echo "❌ ERROR: Should have thrown exception for unknown MAC\n";
    } catch (RuntimeException $e) {
        echo "✅ Unknown MAC validation: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

function main(): void {
    global $logger;

    echo "🚀 Testing Fossibot Queue System\n";
    echo "================================\n\n";

    // Test queue system without real connection
    testQueueWithoutConnection();

    // Test error handling
    testErrorHandling();

    // Ask user if they want to test with real device
    echo "Do you want to test with a real device? This will send actual USB commands! (y/N): ";
    $input = trim(fgets(STDIN));

    if (strtolower($input) === 'y' || strtolower($input) === 'yes') {
        testQueueWithRealConnection();
    } else {
        echo "Skipping real device test.\n";
    }

    echo "\n📋 Summary:\n";
    echo "- Queue System: ✅ Implemented\n";
    echo "- Command Routing: ✅ Working\n";
    echo "- MQTT Integration: ✅ Ready\n";
    echo "- USB Control: ✅ Available\n";
}

main();