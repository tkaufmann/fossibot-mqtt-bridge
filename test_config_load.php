<?php
require 'vendor/autoload.php';

$configPath = 'config/example.json';

echo "Testing config loading...\n";
echo "Config file: $configPath\n\n";

if (!file_exists($configPath)) {
    echo "❌ Config file not found\n";
    exit(1);
}

$json = file_get_contents($configPath);
$config = json_decode($json, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Invalid JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "✅ JSON valid\n";

// Validate structure
$errors = [];

if (empty($config['accounts'])) {
    $errors[] = "Missing 'accounts' array";
}

foreach ($config['accounts'] as $i => $account) {
    if (empty($account['email'])) {
        $errors[] = "Account $i missing email";
    }
    if (empty($account['password'])) {
        $errors[] = "Account $i missing password";
    }
}

if (empty($config['mosquitto']['host'])) {
    $errors[] = "Missing mosquitto.host";
}

if (!empty($errors)) {
    echo "❌ Validation errors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    exit(1);
}

echo "✅ Config structure valid\n";
echo "\nAccounts configured: " . count($config['accounts']) . "\n";
echo "Mosquitto broker: {$config['mosquitto']['host']}:{$config['mosquitto']['port']}\n";
echo "Log level: {$config['daemon']['log_level']}\n";

echo "\n✅ Config test passed!\n";