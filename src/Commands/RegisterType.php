<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * Modbus register type classification.
 *
 * Distinguishes between Input Registers (FC 04) and Holding Registers (FC 03)
 * to enable correct register mapping in hybrid read strategy.
 */
enum RegisterType: string
{
    /**
     * Input Registers (Function Code 04) - Read-only sensor/realtime data.
     * Contains: Power values, SOC, temperatures, output states.
     * Updated spontaneously by device every ~3 minutes.
     */
    case INPUT = 'input';

    /**
     * Holding Registers (Function Code 03) - Configuration/settings data.
     * Contains: maxChargingCurrent, discharge limits, timeouts.
     * Read on-demand or after write operations.
     */
    case HOLDING = 'holding';
}
