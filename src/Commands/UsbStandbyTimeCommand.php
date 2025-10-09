<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use InvalidArgumentException;

/**
 * Command to set USB standby timeout (Register 59).
 *
 * Controls how long the USB output stays on without load before
 * automatically shutting down.
 */
class UsbStandbyTimeCommand extends WriteRegisterCommand
{
    private const REGISTER = 59;
    private const VALID_VALUES = [0, 3, 5, 10, 30]; // minutes

    /**
     * Create USB Standby Time command.
     *
     * @param int $minutes Timeout in minutes (0, 3, 5, 10, or 30)
     * @throws InvalidArgumentException If timeout value is invalid
     */
    public function __construct(private readonly int $minutes)
    {
        if (!in_array($minutes, self::VALID_VALUES, true)) {
            throw new InvalidArgumentException(
                "USB standby time must be one of: " . implode(', ', self::VALID_VALUES) . " minutes. Got: {$minutes}"
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
            ? "Disable USB standby timeout"
            : "Set USB standby timeout to {$this->minutes} minutes";
    }
}
