<?php
/**
 * ABOUTME: Test if Browser creation before loop->run() causes issues
 */

require 'vendor/autoload.php';

use React\Http\Browser;
use React\EventLoop\Loop;
use React\Socket\Connector;
use React\Dns\Resolver\Factory as DnsFactory;

echo "Test: Browser creation BEFORE vs AFTER loop->run()\n\n";

// Get loop
$loop = Loop::get();

echo "1. Creating Browser BEFORE loop->run()...\n";

// Create DNS resolver
$dnsFactory = new DnsFactory();
$dns = $dnsFactory->createCached('8.8.8.8', $loop);

// Create TLS context
$context = [
    'tls' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'cafile' => __DIR__ . '/config/cacert.pem'
    ]
];

// Create connector
$connector = new Connector($context + [
    'dns' => $dns,
    'timeout' => 15.0,
]);

// Create Browser
$browser = new Browser($connector, $loop);

echo "2. Browser created successfully\n";
echo "3. Creating Promise...\n";

// Create promise
$promise = $browser->post(
    'https://api.next.bspapp.com/client',
    ['Content-Type' => 'application/json'],
    json_encode(['method' => 'test'])
);

echo "4. Promise created: " . get_class($promise) . "\n";

// Register handlers
$promise->then(
    function($response) {
        echo "✅ SUCCESS: " . $response->getStatusCode() . "\n";
        exit(0);
    },
    function($error) {
        echo "❌ ERROR: " . $error->getMessage() . "\n";
        exit(1);
    }
);

echo "5. Handlers registered\n";
echo "6. Starting loop->run()...\n\n";

// Add timeout
$loop->addTimer(10, function() {
    echo "❌ TIMEOUT after 10 seconds\n";
    exit(1);
});

// NOW start the loop
$loop->run();

echo "7. Loop finished\n";
