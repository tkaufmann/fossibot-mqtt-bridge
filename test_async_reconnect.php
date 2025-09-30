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
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

echo "Testing AsyncCloudClient reconnect...\n";
echo "Email: $email\n\n";

$loop = Loop::get();
$client = new AsyncCloudClient($email, $password, $loop, $logger);

$connectCount = 0;

$client->on('connect', function() use ($client, $loop, &$connectCount) {
    $connectCount++;
    echo "\n✅ Connected (connect #$connectCount)\n";
    echo "   Devices: " . count($client->getDevices()) . "\n";

    if ($connectCount === 1) {
        // First connection - force disconnect after 3 seconds
        $loop->addTimer(3, function() use ($client, $loop) {
            echo "\n⚠️  Forcing disconnect...\n";
            $client->disconnect();

            // Try to reconnect after 2 seconds
            $loop->addTimer(2, function() use ($client, $loop) {
                echo "\n🔄 Attempting reconnect...\n";
                $client->reconnect()->then(
                    function() {
                        echo "✅ Reconnect promise resolved!\n";
                    },
                    function($error) {
                        echo "❌ Reconnect promise rejected: " . $error->getMessage() . "\n";
                    }
                );
            });
        });
    } elseif ($connectCount === 2) {
        // Second connection successful - stop test after 3 seconds
        echo "\n🎉 Reconnect successful! Waiting 3 more seconds...\n";
        $loop->addTimer(3, function() use ($loop) {
            echo "\n✅ Test completed successfully!\n";
            $loop->stop();
        });
    }
});

$client->on('disconnect', function() {
    echo "\n⚠️  EVENT: Disconnected\n";
});

$client->on('error', function($error) {
    echo "\n❌ EVENT: Error - " . $error->getMessage() . "\n";
});

$client->connect()->then(
    function() {
        echo "Initial connection established\n";
    },
    function($error) use ($loop) {
        echo "❌ Initial connection failed: " . $error->getMessage() . "\n";
        $loop->stop();
    }
);

// Safety timeout after 25 seconds
$loop->addTimer(25, function() use ($loop, &$connectCount) {
    if ($connectCount < 2) {
        echo "\n❌ Test timeout - reconnect did not succeed\n";
    } else {
        echo "\n⏱️  Test timeout (already succeeded)\n";
    }
    $loop->stop();
});

echo "Starting event loop...\n";
$loop->run();

echo "\nTotal connections: $connectCount\n";
echo "✅ Reconnect test completed!\n";