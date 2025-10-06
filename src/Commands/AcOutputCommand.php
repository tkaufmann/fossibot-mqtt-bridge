<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * AC output control command (Register 26).
 *
 * Controls the AC inverter output on Fossibot devices. Provides factory methods
 * for enabling/disabling AC outputs with immediate MQTT response feedback.
 * Uses register 26 with CommandResponseType::IMMEDIATE for real-time control.
 */
class AcOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_AC_OUTPUT = 26;

    /**
     * Create command to enable AC output.
     *
     * @return self Command to turn on AC output
     */
    public static function enable(): self
    {
        return new self(self::REGISTER_AC_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    /**
     * Create command to disable AC output.
     *
     * @return self Command to turn off AC output
     */
    public static function disable(): self
    {
        return new self(self::REGISTER_AC_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}
