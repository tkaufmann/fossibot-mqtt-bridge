<?php

// ABOUTME: Test suite bootstrap - loads autoloader and test utilities

declare(strict_types=1);

use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load .env for credentials
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Test helpers
function getTestEmail(): string
{
    $email = getenv('FOSSIBOT_EMAIL');
    if (empty($email)) {
        throw new RuntimeException('FOSSIBOT_EMAIL not set in .env');
    }
    return $email;
}

function getTestPassword(): string
{
    $password = getenv('FOSSIBOT_PASSWORD');
    if (empty($password)) {
        throw new RuntimeException('FOSSIBOT_PASSWORD not set in .env');
    }
    return $password;
}

function createTestLogger(): LoggerInterface
{
    $logger = new Logger('test');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
    return $logger;
}
