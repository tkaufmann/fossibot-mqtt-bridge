<?php
// ABOUTME: Tests complete daemon lifecycle (validation, startup, shutdown)
require 'vendor/autoload.php';

echo "=== Daemon Lifecycle Test ===\n\n";

// Test 1: Config validation
echo "Test 1: Config validation\n";
echo "-------------------------\n";

$output = shell_exec('php daemon/fossibot-bridge.php --config config/example.json --validate 2>&1');
echo $output;

if (str_contains($output, '✅ Config valid')) {
    echo "✅ Config validation works\n\n";
} else {
    echo "❌ Config validation failed\n\n";
    exit(1);
}

// Test 2: Invalid config detection
echo "Test 2: Invalid config detection\n";
echo "---------------------------------\n";

$invalidConfig = [
    'accounts' => [],  // Empty accounts (invalid)
    'mosquitto' => ['host' => 'localhost'],
];

file_put_contents('/tmp/invalid_config.json', json_encode($invalidConfig));
$output = shell_exec('php daemon/fossibot-bridge.php --config /tmp/invalid_config.json --validate 2>&1');

if (str_contains($output, '❌ Config validation failed')) {
    echo "✅ Invalid config detection works\n\n";
} else {
    echo "❌ Should have failed validation\n\n";
    exit(1);
}

unlink('/tmp/invalid_config.json');

// Test 3: Help output
echo "Test 3: Help output\n";
echo "-------------------\n";

$output = shell_exec('php daemon/fossibot-bridge.php --help 2>&1');

if (str_contains($output, 'Usage:') && str_contains($output, '--config')) {
    echo "✅ Help output correct\n\n";
} else {
    echo "❌ Help output incorrect\n\n";
    exit(1);
}

// Test 4: Version output
echo "Test 4: Version output\n";
echo "----------------------\n";

$output = shell_exec('php daemon/fossibot-bridge.php --version 2>&1');

if (str_contains($output, 'v2.0.0')) {
    echo "✅ Version output correct\n\n";
} else {
    echo "❌ Version output incorrect\n\n";
    exit(1);
}

// Test 5: Daemon start (with timeout)
echo "Test 5: Daemon startup\n";
echo "----------------------\n";
echo "Starting daemon for 10 seconds...\n";

// Note: Requires valid credentials in config
if (!file_exists('config/config.json')) {
    echo "⚠️  Skipping (config/config.json not found)\n";
    echo "   Create config/config.json from example to test daemon startup\n\n";
} else {
    // Start daemon in background
    $process = proc_open(
        'php daemon/fossibot-bridge.php --config config/config.json 2>&1',
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ],
        $pipes
    );

    if (is_resource($process)) {
        // Give daemon 5 seconds to start
        sleep(5);

        // Check if still running
        $status = proc_get_status($process);
        if ($status['running']) {
            echo "✅ Daemon started successfully\n";

            // Send SIGTERM to trigger graceful shutdown
            proc_terminate($process, SIGTERM);

            // Wait for graceful shutdown (max 5 seconds)
            $shutdownStart = time();
            while ($status['running'] && (time() - $shutdownStart) < 5) {
                sleep(1);
                $status = proc_get_status($process);
            }

            if (!$status['running']) {
                echo "✅ Graceful shutdown successful\n\n";
            } else {
                echo "⚠️  Daemon didn't stop gracefully, killing\n\n";
                proc_terminate($process, SIGKILL);
            }
        } else {
            $output = stream_get_contents($pipes[1]);
            echo "❌ Daemon failed to start:\n";
            echo $output . "\n";
            exit(1);
        }

        // Cleanup
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    } else {
        echo "❌ Failed to start process\n\n";
        exit(1);
    }
}

echo "✅ All daemon lifecycle tests passed!\n";
