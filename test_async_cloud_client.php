<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;

// Load config
$configPath = __DIR__ . '/config/config.json';
if (!file_exists($configPath)) {
    echo "❌ Error: config/config.json not found\n";
    echo "   Create it from config/example.json\n";
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
$email = $config['accounts'][0]['email'] ?? '';
$password = $config['accounts'][0]['password'] ?? '';

if (empty($email) || empty($password)) {
    echo "❌ Error: No valid account in config.json\n";
    exit(1);
}

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

echo "Testing AsyncCloudClient...\n";
echo "Email: $email\n\n";

$loop = Loop::get();

$client = new AsyncCloudClient($email, $password, $loop, $logger);

// Register event handlers
$client->on('connect', function() use ($client) {
    echo "\n✅ EVENT: Connected!\n";
    echo "Devices discovered: " . count($client->getDevices()) . "\n";

    foreach ($client->getDevices() as $device) {
        echo "  - {$device->getDeviceName()} ({$device->getMqttId()})\n";
    }
});

$client->on('message', function($topic, $payload) {
    echo "\n✅ EVENT: Message received\n";
    echo "  Topic: $topic\n";
    echo "  Payload: " . strlen($payload) . " bytes\n";
    echo "  Hex: " . substr(bin2hex($payload), 0, 40) . "...\n";
});

$client->on('disconnect', function() {
    echo "\n⚠️  EVENT: Disconnected\n";
});

$client->on('error', function($error) {
    echo "\n❌ EVENT: Error - " . $error->getMessage() . "\n";
});

// Connect (returns promise)
$client->connect()->then(
    function() use ($loop) {
        echo "\n✅ Connection promise resolved\n";
        echo "Waiting for messages (30 seconds)...\n\n";

        // Stop after 30 seconds
        $loop->addTimer(30, function() use ($loop) {
            echo "\n⏱️  Test timeout reached\n";
            $loop->stop();
        });
    },
    function($error) use ($loop) {
        echo "\n❌ Connection promise rejected: " . $error->getMessage() . "\n";
        $loop->stop();
    }
);

echo "Starting event loop...\n";
$loop->run();

echo "\n✅ Test completed!\n";