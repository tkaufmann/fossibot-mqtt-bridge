<?php

declare(strict_types=1);

// ABOUTME: MODBUS register type classification for FossibotLocalControl
// Distinguishes between Input Registers (FC 04) and Holding Registers (FC 03)

/**
 * Modbus register type classification.
 *
 * Distinguishes between Input Registers (FC 04) and Holding Registers (FC 03)
 * to enable correct register mapping in hybrid read strategy.
 */
class RegisterType
{
    /**
     * Input Registers (Function Code 04) - Read-only sensor/realtime data.
     * Contains: Power values, SOC, temperatures, output states.
     * Updated spontaneously by device every ~3 minutes.
     */
    public const INPUT = 'input';

    /**
     * Holding Registers (Function Code 03) - Configuration/settings data.
     * Contains: maxChargingCurrent, discharge limits, timeouts.
     * Read on-demand or after write operations.
     */
    public const HOLDING = 'holding';
}
