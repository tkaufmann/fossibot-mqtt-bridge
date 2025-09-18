<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * LED output control command (Register 27).
 */
class LedOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_LED = 27;

    public static function enable(): self
    {
        return new self(self::REGISTER_LED, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable(): self
    {
        return new self(self::REGISTER_LED, 0, CommandResponseType::IMMEDIATE);
    }
}