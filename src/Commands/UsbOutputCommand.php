<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * USB output control command (Register 24).
 *
 * Controls the USB output ports on Fossibot devices. Provides factory methods
 * for enabling/disabling USB outputs with immediate MQTT response feedback.
 * Uses register 24 with CommandResponseType::IMMEDIATE for real-time control.
 */
class UsbOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_USB_OUTPUT = 24;

    /**
     * Create command to enable USB output.
     *
     * @return self Command to turn on USB output
     */
    public static function enable(): self
    {
        return new self(self::REGISTER_USB_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    /**
     * Create command to disable USB output.
     *
     * @return self Command to turn off USB output
     */
    public static function disable(): self
    {
        return new self(self::REGISTER_USB_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}
