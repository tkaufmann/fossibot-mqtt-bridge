<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use InvalidArgumentException;

/**
 * Command to set sleep timeout (Register 68).
 *
 * Controls how long the device waits before entering sleep mode.
 *
 * ⚠️ CRITICAL: This register must NEVER be set to 0 as it will brick the device!
 * Valid values are: 5, 10, 30, 480 minutes only.
 */
class SleepTimeCommand extends WriteRegisterCommand
{
    private const REGISTER = 68;
    private const VALID_VALUES = [5, 10, 30, 480]; // minutes - NEVER 0!

    /**
     * Create Sleep Time command.
     *
     * @param int $minutes Timeout in minutes (5, 10, 30, or 480)
     * @throws InvalidArgumentException If timeout value is invalid or 0
     */
    public function __construct(private readonly int $minutes)
    {
        // CRITICAL: Reject 0 immediately to prevent device bricking
        if ($minutes === 0) {
            throw new InvalidArgumentException(
                "CRITICAL: Sleep time cannot be 0 - this will brick the device! " .
                "Valid values: " . implode(', ', self::VALID_VALUES) . " minutes"
            );
        }

        if (!in_array($minutes, self::VALID_VALUES, true)) {
            throw new InvalidArgumentException(
                "Sleep time must be one of: " . implode(', ', self::VALID_VALUES) . " minutes. Got: {$minutes}"
            );
        }

        parent::__construct(
            self::REGISTER,
            $minutes,
            CommandResponseType::IMMEDIATE
        );
    }

    public function getDescription(): string
    {
        return "Set sleep timeout to {$this->minutes} minutes";
    }
}
