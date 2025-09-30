<?php
// ABOUTME: Test suite bootstrap - loads autoloader and test utilities

declare(strict_types=1);

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load .env for credentials
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Test helpers
function getTestEmail(): string
{
    $email = getenv('FOSSIBOT_EMAIL');
    if (empty($email)) {
        throw new \RuntimeException('FOSSIBOT_EMAIL not set in .env');
    }
    return $email;
}

function getTestPassword(): string
{
    $password = getenv('FOSSIBOT_PASSWORD');
    if (empty($password)) {
        throw new \RuntimeException('FOSSIBOT_PASSWORD not set in .env');
    }
    return $password;
}

function createTestLogger(): \Psr\Log\LoggerInterface
{
    $logger = new \Monolog\Logger('test');
    $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::DEBUG));
    return $logger;
}