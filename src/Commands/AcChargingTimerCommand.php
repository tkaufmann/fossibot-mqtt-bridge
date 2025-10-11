<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use InvalidArgumentException;

/**
 * Command to set AC charging timer (Register 63).
 *
 * Controls how long AC charging will run before automatically stopping.
 * This is a countdown timer that starts immediately when set.
 */
class AcChargingTimerCommand extends WriteRegisterCommand
{
    private const REGISTER = 63;
    private const MIN_VALUE = 0;    // 0 = disabled
    private const MAX_VALUE = 1439; // 23h 59min

    /**
     * Create AC Charging Timer command.
     *
     * @param int $minutes Duration in minutes (0-1439, where 0 disables the timer)
     * @throws InvalidArgumentException If value is outside valid range
     */
    public function __construct(private readonly int $minutes)
    {
        if ($minutes < self::MIN_VALUE || $minutes > self::MAX_VALUE) {
            throw new InvalidArgumentException(
                "AC charging timer must be " . self::MIN_VALUE . "-" . self::MAX_VALUE .
                " minutes (0-23:59). Got: {$minutes}"
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
        if ($this->minutes === 0) {
            return "Disable AC charging timer";
        }

        $hours = intdiv($this->minutes, 60);
        $mins = $this->minutes % 60;
        return "Set AC charging timer to {$hours}h {$mins}min";
    }
}
