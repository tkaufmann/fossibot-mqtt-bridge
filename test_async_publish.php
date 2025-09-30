<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use Fossibot\Commands\UsbOutputCommand;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;

// Load config
$configPath = __DIR__ . '/config/config.json';
if (!file_exists($configPath)) {
    echo "❌ Error: config/config.json not found\n";
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

echo "Testing AsyncCloudClient publish...\n";
echo "Email: $email\n\n";

$loop = Loop::get();
$client = new AsyncCloudClient($email, $password, $loop, $logger);

$messageCount = 0;

$client->on('connect', function() use ($client, $loop, &$messageCount) {
    echo "✅ Connected\n";

    $devices = $client->getDevices();
    if (empty($devices)) {
        echo "❌ No devices found\n";
        $loop->stop();
        return;
    }

    $device = $devices[0];
    $mac = $device->getMqttId();

    echo "Testing device: {$device->getDeviceName()} ($mac)\n\n";

    // Send USB On command
    $command = UsbOutputCommand::enable();
    $modbusBytes = $command->getModbusBytes();
    $payload = '';
    foreach ($modbusBytes as $byte) {
        $payload .= chr($byte);
    }

    echo "📤 Sending USB On command...\n";
    echo "   Command hex: " . bin2hex($payload) . "\n";
    echo "   Topic: $mac/client/request/data\n\n";

    $client->publish("$mac/client/request/data", $payload);

    // Wait for state update
    $loop->addTimer(5, function() use (&$messageCount) {
        echo "\n⏱️  5 seconds elapsed. Messages received so far: $messageCount\n";
    });

    // Stop after 15 seconds
    $loop->addTimer(15, function() use ($loop, &$messageCount) {
        echo "\n✅ Test completed\n";
        echo "Total messages received: $messageCount\n";
        $loop->stop();
    });
});

$client->on('message', function($topic, $payload) use (&$messageCount) {
    $messageCount++;
    echo "\n📨 Message #$messageCount received\n";
    echo "  Topic: $topic\n";
    echo "  Payload length: " . strlen($payload) . " bytes\n";

    // Show hex dump (first 40 chars)
    if (strlen($payload) > 0) {
        $hex = bin2hex($payload);
        $preview = strlen($hex) > 40 ? substr($hex, 0, 40) . '...' : $hex;
        echo "  Hex: $preview\n";
    }
});

$client->on('disconnect', function() {
    echo "\n⚠️  EVENT: Disconnected\n";
});

$client->on('error', function($error) use ($loop) {
    echo "\n❌ EVENT: Error - " . $error->getMessage() . "\n";
    $loop->stop();
});

$client->connect()->then(
    function() {
        echo "Connection established, waiting for connect event...\n\n";
    },
    function($error) use ($loop) {
        echo "❌ Connection failed: " . $error->getMessage() . "\n";
        $loop->stop();
    }
);

echo "Starting event loop...\n";
$loop->run();

echo "\n✅ Publish test completed!\n";