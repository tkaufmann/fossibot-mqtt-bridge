<?php
require 'vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "Testing Mosquitto connection...\n";

try {
    $mqtt = new MqttClient('localhost', 1883, 'test_client_' . uniqid());
    $connectionSettings = (new ConnectionSettings)
        ->setConnectTimeout(3)
        ->setUseTls(false);

    $mqtt->connect($connectionSettings);
    echo "✅ Connected to Mosquitto\n";

    // Publish test message
    $mqtt->publish('test/topic', 'Test from PHP', 0);
    echo "✅ Published test message\n";

    $mqtt->disconnect();
    echo "✅ Disconnected cleanly\n";

    echo "\n✅ Mosquitto test passed!\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}