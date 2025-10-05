<?php

declare(strict_types=1);

/**
 * ABOUTME: Provides configuration values for Fossibot API integration.
 * Loads environment variables from .env file using vlucas/phpdotenv.
 */

namespace Fossibot;

use Dotenv\Dotenv;

final class Config
{
    private static bool $initialized = false;

    private static function ensureInitialized(): void
    {
        if (!self::$initialized) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__));
            $dotenv->safeLoad();
            self::$initialized = true;
        }
    }

    public static function getApiEndpoint(): string
    {
        self::ensureInitialized();
        return "https://api.next.bspapp.com/client";
    }

    public static function getClientSecret(): string
    {
        self::ensureInitialized();
        return "5rCEdl/nx7IgViBe4QYRiQ==";
    }

    public static function getSpaceId(): string
    {
        self::ensureInitialized();
        return "mp-6c382a98-49b8-40ba-b761-645d83e8ee74";
    }

    public static function getMqttHost(): string
    {
        self::ensureInitialized();
        return $_ENV['FOSSIBOT_DEV_MODE'] ?? false ? "dev.mqtt.sydpower.com" : "mqtt.sydpower.com";
    }

    public static function getMqttPort(): int
    {
        return 8083;
    }

    public static function getMqttPassword(): string
    {
        return "helloyou";
    }

    public static function getMqttWebsocketPath(): string
    {
        return "/mqtt";
    }

    public static function getEmail(): string
    {
        self::ensureInitialized();
        return $_ENV['FOSSIBOT_EMAIL'] ?? throw new \RuntimeException('FOSSIBOT_EMAIL not set in .env');
    }

    public static function getPassword(): string
    {
        self::ensureInitialized();
        return $_ENV['FOSSIBOT_PASSWORD'] ?? throw new \RuntimeException('FOSSIBOT_PASSWORD not set in .env');
    }
}