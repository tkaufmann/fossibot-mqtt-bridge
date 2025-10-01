<?php
/**
 * ABOUTME: Test if parameter passing keeps object alive for async operations
 */

require 'vendor/autoload.php';

use React\Http\Browser;
use React\EventLoop\Loop;
use React\Socket\Connector;
use React\Dns\Resolver\Factory as DnsFactory;

class TestClass
{
    private ?\React\Http\Browser $browser = null;
    private $loop;

    public function __construct($loop) {
        $this->loop = $loop;
    }

    public function test1_parameterPassing() {
        echo "Test 1: Browser as PARAMETER (like our current code)\n";

        $this->browser = $this->createBrowser();

        return $this->makeRequest($this->browser);
    }

    public function test2_directProperty() {
        echo "Test 2: Browser as PROPERTY access (no parameter)\n";

        $this->browser = $this->createBrowser();

        return $this->makeRequestDirect();
    }

    private function makeRequest(Browser $browser) {
        // $browser is a LOCAL PARAMETER here!
        echo "  - makeRequest() called with parameter\n";

        return $browser->post(
            'https://api.next.bspapp.com/client',
            ['Content-Type' => 'application/json'],
            json_encode(['method' => 'test'])
        );
    }

    private function makeRequestDirect() {
        // Uses $this->browser directly - NO local parameter!
        echo "  - makeRequestDirect() called without parameter\n";

        return $this->browser->post(
            'https://api.next.bspapp.com/client',
            ['Content-Type' => 'application/json'],
            json_encode(['method' => 'test'])
        );
    }

    private function createBrowser() {
        $dnsFactory = new DnsFactory();
        $dns = $dnsFactory->createCached('8.8.8.8', $this->loop);

        $context = [
            'tls' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => __DIR__ . '/config/cacert.pem'
            ]
        ];

        $connector = new Connector($context + [
            'dns' => $dns,
            'timeout' => 15.0,
        ]);

        return new Browser($connector, $this->loop);
    }
}

$loop = Loop::get();
$test = new TestClass($loop);

// Test 1: Parameter Passing
$promise1 = $test->test1_parameterPassing();
$promise1->then(
    function($r) { echo "✅ Test 1 SUCCESS: " . $r->getStatusCode() . "\n"; },
    function($e) { echo "✅ Test 1 ERROR: " . $e->getMessage() . "\n"; }
);

// Wait a bit
$loop->addTimer(2, function() use ($test, $loop) {
    echo "\n";

    // Test 2: Direct Property
    $promise2 = $test->test2_directProperty();
    $promise2->then(
        function($r) { echo "✅ Test 2 SUCCESS: " . $r->getStatusCode() . "\n"; },
        function($e) { echo "✅ Test 2 ERROR: " . $e->getMessage() . "\n"; }
    );
});

$loop->addTimer(15, function() {
    echo "❌ TIMEOUT\n";
    exit(1);
});

$loop->run();
