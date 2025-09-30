<?php
// ABOUTME: Test script for WebSocket disconnect and auto-reconnect scenarios.
// Tests Tier 1 simple reconnect by forcing WebSocket closure after 5 seconds.

require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use React\EventLoop\Loop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load config
$config = json_decode(file_get_contents('config/config.json'), true);
$account = $config['accounts'][0];
$email = $account['email'];
$password = $account['password'];

$logger = new Logger('reconnect_test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

echo "=== Reconnect Scenario Tests ===\n\n";

$loop = Loop::get();

// Test 1: Automatic reconnect on WebSocket close
echo "Test 1: WebSocket disconnect and auto-reconnect\n";
echo "-----------------------------------------------\n";

$client = new AsyncCloudClient($email, $password, $loop, $logger);

$client->on('connect', function() {
    echo "✅ Connected\n";
});

$client->on('disconnect', function() {
    echo "⚠️  Disconnected\n";
});

$client->on('reconnect', function() {
    echo "✅ Reconnected successfully\n";
});

$client->on('reconnect_scheduled', function($delay) {
    echo "⏱️  Reconnect scheduled in {$delay} seconds\n";
});

$client->on('error', function($error) {
    echo "❌ Error: " . $error->getMessage() . "\n";
});

// Connect and then force WebSocket close (not graceful disconnect) after 5 seconds
$client->connect()->then(function() use ($client, $loop) {
    echo "Initial connection successful, will force close WebSocket in 5s\n\n";

    $loop->addTimer(5, function() use ($client) {
        echo "Simulating connection loss (force close WebSocket)...\n";

        // Force close WebSocket (simulates network failure)
        // Note: Using reflection to access protected property for testing
        $reflection = new \ReflectionClass($client);
        $wsProperty = $reflection->getProperty('websocket');
        $wsProperty->setAccessible(true);
        $ws = $wsProperty->getValue($client);

        if ($ws !== null) {
            $ws->close(); // This triggers auto-reconnect
        }
    });
});

// Run for 30 seconds to observe reconnection
$loop->addTimer(30, function() use ($loop) {
    echo "\nTest complete\n";
    $loop->stop();
});

$loop->run();
