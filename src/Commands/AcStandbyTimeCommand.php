<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use InvalidArgumentException;

/**
 * Command to set AC standby timeout (Register 60).
 *
 * Controls how long the AC output stays on without load before
 * automatically shutting down.
 */
class AcStandbyTimeCommand extends WriteRegisterCommand
{
    private const REGISTER = 60;
    private const VALID_VALUES = [0, 480, 960, 1440]; // minutes

    /**
     * Create AC Standby Time command.
     *
     * @param int $minutes Timeout in minutes (0, 480, 960, or 1440)
     * @throws InvalidArgumentException If timeout value is invalid
     */
    public function __construct(private readonly int $minutes)
    {
        if (!in_array($minutes, self::VALID_VALUES, true)) {
            throw new InvalidArgumentException(
                "AC standby time must be one of: " . implode(', ', self::VALID_VALUES) . " minutes. Got: {$minutes}"
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
        return $this->minutes === 0
            ? "Disable AC standby timeout"
            : "Set AC standby timeout to {$this->minutes} minutes";
    }
}
