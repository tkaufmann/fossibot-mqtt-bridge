<?php

// ABOUTME: LED output control command (Register 27).

declare(strict_types=1);

require_once __DIR__ . '/WriteRegisterCommand.php';
require_once __DIR__ . '/CommandResponseType.php';

class LedOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_LED_OUTPUT = 27;

    public static function enable(): self
    {
        return new self(self::REGISTER_LED_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable(): self
    {
        return new self(self::REGISTER_LED_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}
