<?php
/**
 * ABOUTME: Test React\Http\Browser against Fossibot API endpoint
 * Isolates if the problem is with the specific API or Browser setup
 */

require 'vendor/autoload.php';

use React\Http\Browser;
use React\EventLoop\Loop;
use React\Socket\Connector;
use React\Dns\Resolver\Factory as DnsFactory;

$loop = Loop::get();

// Configure DNS resolver (same as AsyncCloudClient)
$dnsFactory = new DnsFactory();
$dns = $dnsFactory->createCached('8.8.8.8', $loop);

// TLS context with CA bundle (same as AsyncCloudClient)
$context = [
    'tls' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'cafile' => __DIR__ . '/config/cacert.pem'
    ]
];

// Socket connector with DNS + TLS (same as AsyncCloudClient)
$connector = new Connector($context + [
    'dns' => $dns,
    'timeout' => 15.0,
]);

$browser = new Browser($connector, $loop);

echo "Testing React\\Http\\Browser against Fossibot API...\n";
echo "URL: https://api.next.bspapp.com/client\n";

// Simple test request (similar to Stage 1)
$testData = [
    'method' => 'test',
    'timestamp' => (int)(microtime(true) * 1000),
];

$promise = $browser->post(
    'https://api.next.bspapp.com/client',
    [
        'Content-Type' => 'application/json',
        'x-serverless-sign' => 'test',
    ],
    json_encode($testData)
);

$promise->then(
    function (Psr\Http\Message\ResponseInterface $response) {
        echo "✅ SUCCESS! Received status code: " . $response->getStatusCode() . "\n";
        echo "Response body: " . substr($response->getBody(), 0, 200) . "...\n";
        exit(0);
    },
    function (Exception $error) {
        echo "❌ FAILED! Error: " . $error->getMessage() . "\n";
        echo "Error class: " . get_class($error) . "\n";
        exit(1);
    }
);

// Manual timeout
$loop->addTimer(20, function() {
    echo "❌ TIMEOUT! The promise never resolved after 20 seconds.\n";
    echo "This means the TLS handshake or HTTP request is hanging.\n";
    exit(1);
});

$loop->run();
