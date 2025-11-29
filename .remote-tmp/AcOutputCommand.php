<?php

// ABOUTME: AC output control command (Register 26).

declare(strict_types=1);

require_once __DIR__ . '/WriteRegisterCommand.php';
require_once __DIR__ . '/CommandResponseType.php';

class AcOutputCommand extends WriteRegisterCommand
{
    const REGISTER_AC_OUTPUT = 26;

    public static function enable()
    {
        return new self(self::REGISTER_AC_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable()
    {
        return new self(self::REGISTER_AC_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}
