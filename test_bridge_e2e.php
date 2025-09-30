<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\MqttBridge;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('bridge');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

echo "╔═══════════════════════════════════════════════════════╗\n";
echo "║    MqttBridge End-to-End Integration Test            ║\n";
echo "╚═══════════════════════════════════════════════════════╝\n\n";

// Load config
$configPath = 'config/config.json';

if (!file_exists($configPath)) {
    echo "❌ Config file not found: $configPath\n";
    echo "   Copy config/example.json and add your credentials.\n";
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);

echo "Config loaded:\n";
echo "  Accounts: " . count($config['accounts']) . "\n";
echo "  Broker: {$config['mosquitto']['host']}:{$config['mosquitto']['port']}\n\n";

echo "Starting bridge...\n";
echo "(Press Ctrl+C to stop)\n\n";

$bridge = new MqttBridge($config, $logger);

// Handle Ctrl+C gracefully
pcntl_signal(SIGINT, function() use ($bridge) {
    echo "\n\nShutting down...\n";
    $bridge->shutdown();
});

try {
    $bridge->run(); // Blocks here
} catch (\Exception $e) {
    echo "\n❌ Bridge error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n✅ Bridge stopped cleanly\n";