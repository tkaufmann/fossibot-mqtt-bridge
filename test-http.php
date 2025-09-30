<?php
/**
 * ABOUTME: Minimal isolation test for React\Http\Browser
 * Tests if async HTTP requests work at all in this environment
 */

require 'vendor/autoload.php';

use React\Http\Browser;
use React\EventLoop\Loop;

$loop = Loop::get();
$browser = new Browser($loop);

echo "Running simple HTTP GET test to https://www.example.com...\n";

$promise = $browser->get('https://www.example.com');

$promise->then(
    function (Psr\Http\Message\ResponseInterface $response) {
        echo "✅ SUCCESS! Received status code: " . $response->getStatusCode() . "\n";
        exit(0);
    },
    function (Exception $error) {
        echo "❌ FAILED! Error: " . $error->getMessage() . "\n";
        exit(1);
    }
);

// Fügen wir einen manuellen Timeout hinzu, um sicherzugehen.
$loop->addTimer(10, function() {
    echo "❌ TIMEOUT! The promise never resolved after 10 seconds.\n";
    exit(1);
});

$loop->run();
