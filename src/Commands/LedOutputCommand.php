<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * LED output control command (Register 27).
 *
 * Controls the LED lighting output on Fossibot devices. Provides factory methods
 * for enabling/disabling LED outputs with immediate MQTT response feedback.
 * Uses register 27 with CommandResponseType::IMMEDIATE for real-time control.
 */
class LedOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_LED = 27;

    /**
     * Create command to enable LED output.
     *
     * @return self Command to turn on LED output
     */
    public static function enable(): self
    {
        return new self(self::REGISTER_LED, 1, CommandResponseType::IMMEDIATE);
    }

    /**
     * Create command to disable LED output.
     *
     * @return self Command to turn off LED output
     */
    public static function disable(): self
    {
        return new self(self::REGISTER_LED, 0, CommandResponseType::IMMEDIATE);
    }
}
