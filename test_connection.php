<?php

declare(strict_types=1);

/**
 * ABOUTME: Test script for Fossibot API connection testing.
 * Tests each authentication stage progressively.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Connection;
use Fossibot\Config;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Setup logging
$logger = new Logger('fossibot-test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

function testStage1(): void {
    global $logger;

    $logger->info('=== Stage 1 Test: Anonymous Authorization ===');

    try {
        $connection = new Connection(
            Config::getEmail(),
            Config::getPassword(),
            $logger
        );

        $logger->info('Created connection instance');
        $logger->info('Email: ' . Config::getEmail());

        $connection->connect();

        if ($connection->isConnected()) {
            $logger->info('✅ Stage 1 SUCCESS: Anonymous token acquired');
        } else {
            $logger->error('❌ Stage 1 FAILED: Connection not established');
        }

    } catch (Exception $e) {
        $logger->error('❌ Stage 1 EXCEPTION: ' . $e->getMessage());
        $logger->debug('Stack trace: ' . $e->getTraceAsString());
    }
}

function main(): void {
    global $logger;

    $logger->info('🚀 Starting Fossibot API Connection Tests');
    $logger->info('API Endpoint: ' . Config::getApiEndpoint());
    $logger->info('MQTT Host: ' . Config::getMqttHost());

    // Test Stage 1
    testStage1();

    $logger->info('📋 Test Summary:');
    $logger->info('- Stage 1 (Anonymous Auth): Implemented');
    $logger->info('- Stage 2 (Login): TODO');
    $logger->info('- Stage 3 (MQTT Token): TODO');
    $logger->info('- Stage 4 (Device Discovery): TODO');
}

main();