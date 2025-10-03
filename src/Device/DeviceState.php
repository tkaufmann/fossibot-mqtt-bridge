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
    public int $inputWatts = 0;                 // Total Input Power (Register 6)
    public int $outputWatts = 0;                // Total Output Power (Register 39)
    public int $dcInputWatts = 0;               // DC Input Power (Register 4)

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
    public DateTime $lastOutputUpdate;  // Track when output states were last updated
    public DateTime $lastSocUpdate;     // Track when SoC was last updated

    // Source tracking for /client/04 updates (spontaneous vs. command-triggered)
    public bool $lastUpdateWasSpontaneous = false;  // Was last /client/04 update spontaneous?
    public ?string $lastUpdateSource = null;         // Source: 'spontaneous', 'command', or null

    public function __construct()
    {
        $this->lastFullUpdate = new DateTime('1970-01-01'); // "never updated"
        $this->lastOutputUpdate = new DateTime('1970-01-01'); // "never updated"
        $this->lastSocUpdate = new DateTime('1970-01-01'); // "never updated"
    }

    /**
     * Update state from F2400 register array.
     *
     * @param array $registers Modbus registers (index => value)
     * @param string|null $sourceTopic MQTT topic that triggered this update
     * @param bool $wasCommandTriggered Was this update triggered by a command we sent?
     */
    public function updateFromRegisters(array $registers, ?string $sourceTopic = null, bool $wasCommandTriggered = false): void
    {
        // Determine if this is an immediate response topic (/client/04)
        $isImmediateResponse = $sourceTopic !== null && str_contains($sourceTopic, '/client/04');
        $isPollingData = $sourceTopic !== null && str_contains($sourceTopic, '/client/data');
        $now = new DateTime();

        // Battery (Register 56 = SoC)
        // ONLY update from /client/04 - /client/data has cached/stale values
        if (isset($registers[56]) && $isImmediateResponse) {
            $this->soc = round($registers[56] / 1000 * 100, 1); // Convert from thousandths to percentage
            $this->lastSocUpdate = $now;

            // Track if this was spontaneous or command-triggered
            $this->lastUpdateWasSpontaneous = !$wasCommandTriggered;
            $this->lastUpdateSource = $wasCommandTriggered ? 'command' : 'spontaneous';
        }

        // Power values (assumed live in all topics based on testing)
        if (isset($registers[4])) {
            $this->dcInputWatts = $registers[4]; // DC Input Power
        }
        if (isset($registers[6])) {
            $this->inputWatts = $registers[6]; // Total Input Power
        }
        if (isset($registers[39])) {
            $this->outputWatts = $registers[39]; // Total Output Power
        }

        // Output States (Register 41 bitfield)
        // ONLY update from /client/04 - /client/data has cached/stale values
        if (isset($registers[41]) && $isImmediateResponse) {
            $bitfield = $registers[41];
            // Use bit-masks from hardware testing (not single bits!)
            // USB and DC share Bit 7
            $this->usbOutput = ($bitfield & 640) !== 0;    // 0x280, Bits 7, 9
            $this->dcOutput = ($bitfield & 1152) !== 0;    // 0x480, Bits 7, 10
            $this->acOutput = ($bitfield & 2052) !== 0;    // 0x804, Bits 2, 11
            $this->ledOutput = ($bitfield & 4096) !== 0;   // 0x1000, Bit 12
            $this->lastOutputUpdate = $now;
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