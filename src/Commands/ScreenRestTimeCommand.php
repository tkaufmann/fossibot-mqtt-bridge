<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use InvalidArgumentException;

/**
 * Command to set screen rest timeout (Register 62).
 *
 * Controls how long the device screen stays on before automatically
 * turning off to save power.
 */
class ScreenRestTimeCommand extends WriteRegisterCommand
{
    private const REGISTER = 62;
    private const VALID_VALUES = [0, 180, 300, 600, 1800]; // seconds

    /**
     * Create Screen Rest Time command.
     *
     * @param int $seconds Timeout in seconds (0, 180, 300, 600, or 1800)
     * @throws InvalidArgumentException If timeout value is invalid
     */
    public function __construct(private readonly int $seconds)
    {
        if (!in_array($seconds, self::VALID_VALUES, true)) {
            throw new InvalidArgumentException(
                "Screen rest time must be one of: " . implode(', ', self::VALID_VALUES) . " seconds. Got: {$seconds}"
            );
        }

        parent::__construct(
            self::REGISTER,
            $seconds,
            CommandResponseType::IMMEDIATE
        );
    }

    public function getDescription(): string
    {
        return $this->seconds === 0
            ? "Disable screen timeout (always on)"
            : "Set screen timeout to {$this->seconds} seconds";
    }
}
