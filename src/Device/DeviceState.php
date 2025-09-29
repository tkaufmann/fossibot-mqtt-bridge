<?php
declare(strict_types=1);

namespace Fossibot\Device;

use DateTime;

/**
 * Represents the current state of a Fossibot device.
 * Contains all readable properties from MQTT register responses.
 */
class DeviceState
{
    // Battery & Power
    public float $soc = 0.0;                    // State of Charge (%)

    // Output States (from Register 41 bitfield)
    public bool $usbOutput = false;             // USB ports on/off
    public bool $acOutput = false;              // AC outlets on/off
    public bool $dcOutput = false;              // DC ports on/off
    public bool $ledOutput = false;             // LED lights on/off

    // Settings (from Registers 20, 66, 67)
    public int $maxChargingCurrent = 0;         // 1-20 Amperes
    public float $dischargeLowerLimit = 0.0;    // 0-100%
    public float $acChargingUpperLimit = 100.0; // 0-100%

    // Metadata
    public DateTime $lastFullUpdate;

    public function __construct()
    {
        $this->lastFullUpdate = new DateTime('1970-01-01'); // "never updated"
    }

    /**
     * Update state from F2400 register array.
     *
     * @param array $registers Modbus registers (index => value)
     */
    public function updateFromRegisters(array $registers): void
    {
        // Battery (Register 56 = SoC, not 5 as in TODO - SYSTEM.md shows 56)
        if (isset($registers[56])) {
            $this->soc = round($registers[56] / 1000 * 100, 1); // Convert from thousandths to percentage
        }

        // Output States (Register 41 bitfield)
        if (isset($registers[41])) {
            $bitfield = $registers[41];
            // Based on SYSTEM.md: USB=Bit 6, DC=Bit 5, AC=Bit 4, LED=Bit 3
            $this->usbOutput = ($bitfield & (1 << 6)) !== 0;
            $this->dcOutput = ($bitfield & (1 << 5)) !== 0;
            $this->acOutput = ($bitfield & (1 << 4)) !== 0;
            $this->ledOutput = ($bitfield & (1 << 3)) !== 0;
        }

        // Settings
        if (isset($registers[20])) {
            $this->maxChargingCurrent = $registers[20];
        }
        if (isset($registers[66])) {
            $this->dischargeLowerLimit = $registers[66] / 10.0; // Tenths to percentage
        }
        if (isset($registers[67])) {
            $this->acChargingUpperLimit = $registers[67] / 10.0; // Tenths to percentage
        }

        $this->lastFullUpdate = new DateTime();
    }

    /**
     * Check if state data is fresh (not older than threshold).
     */
    public function isFresh(int $maxAgeSeconds = 300): bool
    {
        $age = time() - $this->lastFullUpdate->getTimestamp();
        return $age <= $maxAgeSeconds;
    }
}