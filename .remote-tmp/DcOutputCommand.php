<?php

// ABOUTME: DC output control command (Register 25).

declare(strict_types=1);

require_once __DIR__ . '/WriteRegisterCommand.php';
require_once __DIR__ . '/CommandResponseType.php';

class DcOutputCommand extends WriteRegisterCommand
{
    const REGISTER_DC_OUTPUT = 25;

    public static function enable()
    {
        return new self(self::REGISTER_DC_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable()
    {
        return new self(self::REGISTER_DC_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}
