<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * USB output control command (Register 24).
 */
class UsbOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_USB_OUTPUT = 24;

    public static function enable(): self
    {
        return new self(self::REGISTER_USB_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    public static function disable(): self
    {
        return new self(self::REGISTER_USB_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}