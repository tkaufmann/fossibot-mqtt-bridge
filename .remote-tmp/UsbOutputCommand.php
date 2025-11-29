<?php

// ABOUTME: USB output control command (Register 24).

declare(strict_types=1);

require_once __DIR__ . '/WriteRegisterCommand.php';
require_once __DIR__ . '/CommandResponseType.php';

class UsbOutputCommand extends WriteRegisterCommand
{
    const REGISTER_USB_OUTPUT = 24;

    public static function enable()
    {
        return new self(self::REGISTER_USB_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable()
    {
        return new self(self::REGISTER_USB_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}
