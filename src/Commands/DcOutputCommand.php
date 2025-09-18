<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * DC output control command (Register 25).
 */
class DcOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_DC_OUTPUT = 25;

    public static function enable(): self
    {
        return new self(self::REGISTER_DC_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable(): self
    {
        return new self(self::REGISTER_DC_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}