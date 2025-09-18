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

function testStage1And2And3(): void {
    global $logger;

    $logger->info('=== Stage 1, 2 & 3 Test: Anonymous Auth + User Login + MQTT Token ===');

    try {
        $connection = new Connection(
            Config::getEmail(),
            Config::getPassword(),
            $logger
        );

        $logger->info('Created connection instance');
        $logger->info('Email: ' . Config::getEmail());

        $connection->connect();

        if ($connection->hasAnonymousToken()) {
            $logger->info('✅ Stage 1 SUCCESS: Anonymous token acquired');
        } else {
            $logger->error('❌ Stage 1 FAILED: No anonymous token');
        }

        if ($connection->hasLoginToken()) {
            $logger->info('✅ Stage 2 SUCCESS: Login token acquired');
        } else {
            $logger->error('❌ Stage 2 FAILED: No login token');
        }

        if ($connection->hasMqttToken()) {
            $logger->info('✅ Stage 3 SUCCESS: MQTT token acquired');
        } else {
            $logger->error('❌ Stage 3 FAILED: No MQTT token');
        }

        if ($connection->isConnected()) {
            $logger->info('✅ FULL CONNECTION: All stages completed');
        } else {
            $logger->info('⚠️  PARTIAL CONNECTION: More stages needed');
        }

        $logger->info('Current Auth State: ' . $connection->getAuthState()->name);

    } catch (Exception $e) {
        $logger->error('❌ CONNECTION EXCEPTION: ' . $e->getMessage());
        $logger->debug('Stack trace: ' . $e->getTraceAsString());
    }
}

function main(): void {
    global $logger;

    $logger->info('🚀 Starting Fossibot API Connection Tests');
    $logger->info('API Endpoint: ' . Config::getApiEndpoint());
    $logger->info('MQTT Host: ' . Config::getMqttHost());

    // Test Stage 1, 2 & 3
    testStage1And2And3();

    $logger->info('📋 Test Summary:');
    $logger->info('- Stage 1 (Anonymous Auth): ✅ Implemented');
    $logger->info('- Stage 2 (User Login): ✅ Implemented');
    $logger->info('- Stage 3 (MQTT Token): ✅ Implemented');
    $logger->info('- Stage 4 (Device Discovery): TODO');
}

main();