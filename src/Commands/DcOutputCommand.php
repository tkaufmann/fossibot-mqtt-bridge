<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * DC output control command (Register 25).
 *
 * Controls the DC output ports on Fossibot devices. Provides factory methods
 * for enabling/disabling DC outputs with immediate MQTT response feedback.
 * Uses register 25 with CommandResponseType::IMMEDIATE for real-time control.
 */
class DcOutputCommand extends WriteRegisterCommand
{
    private const REGISTER_DC_OUTPUT = 25;

    /**
     * Create command to enable DC output.
     *
     * @return self Command to turn on DC output
     */
    public static function enable(): self
    {
        return new self(self::REGISTER_DC_OUTPUT, 1, CommandResponseType::IMMEDIATE);
    }

    /**
     * Create command to disable DC output.
     *
     * @return self Command to turn off DC output
     */
    public static function disable(): self
    {
        return new self(self::REGISTER_DC_OUTPUT, 0, CommandResponseType::IMMEDIATE);
    }
}