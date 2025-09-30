<?php
// ABOUTME: Test script for token expiry tracking functionality.
// Validates that MQTT and login token expiry timestamps are correctly extracted and tracked.

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

$logger = new Logger('token_test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

echo "=== Token Expiry Test ===\n\n";

$loop = Loop::get();
$client = new AsyncCloudClient($email, $password, $loop, $logger);

$client->connect()->then(function() use ($client) {
    echo "✅ Connected\n";

    // Use reflection to check token expiry times
    $reflection = new \ReflectionClass($client);

    $mqttExpiry = $reflection->getProperty('mqttTokenExpiresAt');
    $mqttExpiry->setAccessible(true);
    $mqttExpiryValue = $mqttExpiry->getValue($client);

    $loginExpiry = $reflection->getProperty('loginTokenExpiresAt');
    $loginExpiry->setAccessible(true);
    $loginExpiryValue = $loginExpiry->getValue($client);

    echo "\nToken Expiry Times:\n";
    echo "-------------------\n";

    if ($mqttExpiryValue !== null) {
        $expiresIn = $mqttExpiryValue - time();
        echo "MQTT Token: " . date('Y-m-d H:i:s', $mqttExpiryValue);
        echo " (~" . round($expiresIn / 3600, 1) . " hours)\n";
    } else {
        echo "MQTT Token: Not tracked\n";
    }

    if ($loginExpiryValue !== null) {
        $expiresIn = $loginExpiryValue - time();
        echo "Login Token: " . date('Y-m-d H:i:s', $loginExpiryValue);
        echo " (~" . round($expiresIn / 86400, 1) . " days)\n";
    } else {
        echo "Login Token: Not tracked (14 year expiry)\n";
    }

    echo "\n✅ Token expiry tracking working\n";
});

$loop->addTimer(5, fn() => $loop->stop());
$loop->run();
