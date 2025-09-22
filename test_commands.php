<?php

declare(strict_types=1);

/**
 * ABOUTME: Test script for USB control using new DeviceFacade API.
 * Tests real device control with the complete new architecture.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Device\Device;
use Fossibot\Device\DeviceFacade;
use Fossibot\Queue\QueueManager;
use Fossibot\Commands\UsbOutputCommand;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !str_starts_with($line, '#')) {
            putenv($line);
        }
    }
}

$email = getenv('FOSSIBOT_EMAIL');
$password = getenv('FOSSIBOT_PASSWORD');

if (!$email || !$password) {
    echo "❌ FOSSIBOT_EMAIL and FOSSIBOT_PASSWORD environment variables required\n";
    exit(1);
}

function setupDeviceFacade(): DeviceFacade {
    global $email, $password;

    echo "=== Setting up Device with New API ===\n";

    // Setup logger
    $logger = new Logger('usb-test');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

    // Get QueueManager singleton and add connection
    $queueManager = QueueManager::getInstance($logger);
    echo "📡 Adding connection and discovering devices...\n";
    $queueManager->addConnection($email, $password);

    $registeredMacs = $queueManager->getRegisteredMacs();
    if (empty($registeredMacs)) {
        throw new RuntimeException("No devices found!");
    }

    $macAddress = $registeredMacs[0];
    echo "✅ Found device: {$macAddress}\n\n";

    // Create DeviceFacade for first device
    $deviceData = [
        'device_id' => $macAddress,
        'device_name' => 'F2400 via New API',
        'product_id' => 'test',
        'model' => 'F2400',
        'mqtt_state' => 1,
        'created_at' => '2024-01-01T00:00:00Z'
    ];

    $device = Device::fromApiResponse($deviceData);
    return new DeviceFacade($device, $queueManager);
}

function testUsbControl(DeviceFacade $deviceFacade): void {
    echo "=== Testing USB Control ===\n";
    echo "Device: " . $deviceFacade->getDeviceName() . " (" . $deviceFacade->getMqttId() . ")\n\n";

    // Show USB command details first
    $usbOnCmd = UsbOutputCommand::enable();
    $usbOffCmd = UsbOutputCommand::disable();

    echo "Command Details:\n";
    echo "  USB ON:  " . $usbOnCmd->getDescription() . "\n";
    echo "           Hex: " . implode(' ', array_map(fn($b) => sprintf('%02X', $b), $usbOnCmd->getModbusBytes())) . "\n";
    echo "  USB OFF: " . $usbOffCmd->getDescription() . "\n";
    echo "           Hex: " . implode(' ', array_map(fn($b) => sprintf('%02X', $b), $usbOffCmd->getModbusBytes())) . "\n\n";

    try {
        echo "🔌 Turning USB ON...\n";
        $deviceFacade->usbOn();
        echo "✅ USB ON command sent successfully!\n\n";

        echo "⏱️  Waiting 5 seconds...\n";
        sleep(5);

        echo "🔌 Turning USB OFF...\n";
        $deviceFacade->usbOff();
        echo "✅ USB OFF command sent successfully!\n\n";

        echo "⏱️  Waiting 3 seconds...\n";
        sleep(3);

        echo "🔌 Turning USB ON again...\n";
        $deviceFacade->usbOn();
        echo "✅ USB ON command sent successfully!\n\n";

    } catch (Exception $e) {
        echo "❌ USB control failed: " . $e->getMessage() . "\n";
        throw $e;
    }
}


function main(): void {
    echo "🔌 USB Control Test mit neuer DeviceFacade API\n";
    echo "============================================\n\n";

    try {
        // Setup device with new API
        $deviceFacade = setupDeviceFacade();

        // Automatic USB test sequence
        testUsbControl($deviceFacade);

        echo "\n✅ USB test completed successfully!\n";
        echo "\n📋 Summary:\n";
        echo "- ✅ New DeviceFacade API working perfectly\n";
        echo "- ✅ Automatic authentication and device discovery\n";
        echo "- ✅ USB ON/OFF commands sent successfully\n";
        echo "- ✅ Real MQTT communication with F2400 device\n";
        echo "- ✅ Commands: ON -> wait 5s -> OFF -> wait 3s -> ON\n";

    } catch (Exception $e) {
        echo "❌ Test failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

main();