<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * AC output control command (Register 26).
 */
class AcOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_AC_OUTPUT = 26;

    public static function enable(): self
    {
        return new self(self::REGISTER_AC_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable(): self
    {
        return new self(self::REGISTER_AC_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}