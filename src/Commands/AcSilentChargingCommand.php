<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * Command to enable/disable AC Silent Charging mode (Register 57).
 *
 * This command controls whether the AC charging operates in silent mode,
 * reducing fan noise during charging.
 */
class AcSilentChargingCommand extends WriteRegisterCommand
{
    private const REGISTER = 57;

    /**
     * Create AC Silent Charging command.
     *
     * @param bool $enabled True to enable silent charging, false to disable
     */
    public function __construct(private readonly bool $enabled)
    {
        parent::__construct(
            self::REGISTER,
            $enabled ? 1 : 0,
            CommandResponseType::IMMEDIATE
        );
    }

    public function getDescription(): string
    {
        $mode = $this->enabled ? 'enabled' : 'disabled';
        return "Set AC Silent Charging to {$mode}";
    }
}
