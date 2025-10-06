<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use InvalidArgumentException;

/**
 * Command to set maximum charging current on F2400 device.
 *
 * Controls the maximum current the device will draw when charging
 * from AC or DC input sources. Valid range: 1-20 Amperes.
 * Register 20 in device memory.
 */
class MaxChargingCurrentCommand extends WriteRegisterCommand
{
    private const TARGET_REGISTER = 20;
    private const MIN_CURRENT = 1;
    private const MAX_CURRENT = 20;

    /**
     * Create command to set maximum charging current.
     *
     * @param int $currentAmperes Maximum charging current in Amperes (1-20)
     * @throws \InvalidArgumentException If current is outside valid range
     */
    public function __construct(private readonly int $currentAmperes)
    {
        if ($currentAmperes < self::MIN_CURRENT || $currentAmperes > self::MAX_CURRENT) {
            throw new InvalidArgumentException(
                "Charging current must be between " . self::MIN_CURRENT . " and " . self::MAX_CURRENT . " Amperes. Got: {$currentAmperes}"
            );
        }

        parent::__construct(self::TARGET_REGISTER, $currentAmperes, CommandResponseType::DELAYED);
    }

    /**
     * Create command for specific current value.
     *
     * @param int $amperes Current in Amperes (1-20)
     * @return self Command instance
     */
    public static function setCurrent(int $amperes): self
    {
        return new self($amperes);
    }

    /**
     * Get the current value being set.
     *
     * @return int Current in Amperes
     */
    public function getCurrentAmperes(): int
    {
        return $this->currentAmperes;
    }

    public function getResponseType(): CommandResponseType
    {
        // Settings commands have delayed/no immediate response according to SYSTEM.md
        // Response appears in client/data topic during periodic updates or explicit reads
        return CommandResponseType::DELAYED;
    }

    public function getDescription(): string
    {
        return "Set maximum charging current to {$this->currentAmperes}A";
    }
}
