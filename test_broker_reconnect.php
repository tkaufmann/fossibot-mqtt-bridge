<?php
// ABOUTME: Interactive test script for broker reconnection with exponential backoff.
// Manual test: Stop/start Mosquitto broker to observe reconnection behavior.

require 'vendor/autoload.php';

use Fossibot\Bridge\MqttBridge;
use React\EventLoop\Loop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('broker_reconnect_test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

echo "=== Broker Reconnect Test ===\n\n";
echo "Instructions:\n";
echo "1. Script will connect to broker\n";
echo "2. Stop Mosquitto: brew services stop mosquitto\n";
echo "3. Observe reconnect attempts\n";
echo "4. Start Mosquitto: brew services start mosquitto\n";
echo "5. Observe successful reconnection\n\n";

$config = json_decode(file_get_contents('config/example.json'), true);
$loop = Loop::get();

$bridge = new MqttBridge($config, $logger);

// Note: run() is blocking, so we can't easily test shutdown in same script
// For manual testing: Run script, press Ctrl+C to test graceful shutdown
echo "Bridge starting (press Ctrl+C to test graceful shutdown)...\n\n";

try {
    $bridge->run(); // Blocking call - runs until Ctrl+C or error
} catch (\Throwable $e) {
    echo "❌ Bridge error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Bridge stopped cleanly\n";
