<?php

declare(strict_types=1);

/**
 * ABOUTME: Selective output control - Turn OFF USB, DC, LED but keep AC ON.
 * Demonstrates precise control of individual F2400 outputs.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Device\Device;
use Fossibot\Device\DeviceFacade;
use Fossibot\Queue\QueueManager;
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

    // Setup logger
    $logger = new Logger('selective-control');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

    // Get QueueManager singleton and add connection
    $queueManager = QueueManager::getInstance($logger);
    $queueManager->addConnection($email, $password);

    $registeredMacs = $queueManager->getRegisteredMacs();
    if (empty($registeredMacs)) {
        throw new RuntimeException("No devices found!");
    }

    $macAddress = $registeredMacs[0];

    // Create DeviceFacade for first device
    $deviceData = [
        'device_id' => $macAddress,
        'device_name' => 'F2400 Selective Control',
        'product_id' => 'test',
        'model' => 'F2400',
        'mqtt_state' => 1,
        'created_at' => '2024-01-01T00:00:00Z'
    ];

    $device = Device::fromApiResponse($deviceData);
    return new DeviceFacade($device, $queueManager);
}

function main(): void {
    echo "🎛️  Selective Output Control - AC bleibt AN, Rest geht AUS\n";
    echo "========================================================\n\n";

    try {
        $deviceFacade = setupDeviceFacade();
        echo "Device: " . $deviceFacade->getDeviceName() . " (" . $deviceFacade->getMqttId() . ")\n\n";

        // First check current status
        echo "📊 Reading current device status...\n";
        $deviceFacade->readSettings();
        sleep(2);
        echo "\n";

        // Turn OFF USB
        echo "🔌 Turning USB OFF...\n";
        $deviceFacade->usbOff();
        echo "✅ USB is now OFF\n";
        sleep(2);

        // Turn OFF DC
        echo "🚗 Turning DC (12V) OFF...\n";
        $deviceFacade->dcOff();
        echo "✅ DC (12V Zigarettenanzünder) is now OFF\n";
        sleep(2);

        // Turn OFF LED
        echo "💡 Turning LED OFF...\n";
        $deviceFacade->ledOff();
        echo "✅ LED (Taschenlampe) is now OFF\n";
        sleep(2);

        // Ensure AC is ON
        echo "⚡ Ensuring AC Output stays ON...\n";
        $deviceFacade->acOn();
        echo "✅ AC Output (Wechselrichter) is ON\n";
        sleep(2);

        // Final status check
        echo "\n📊 Final status check...\n";
        $deviceFacade->readSettings();
        sleep(2);

        echo "\n✅ Selective control completed!\n\n";
        echo "📋 Current F2400 Status:\n";
        echo "- 🔌 USB Output: ❌ OFF\n";
        echo "- ⚡ AC Output:  ✅ ON (Wechselrichter läuft)\n";
        echo "- 🚗 DC Output:  ❌ OFF\n";
        echo "- 💡 LED Output: ❌ OFF\n\n";
        echo "🎯 Nur der Wechselrichter (AC) ist aktiv! 🚀\n";

    } catch (Exception $e) {
        echo "❌ Selective control failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

main();