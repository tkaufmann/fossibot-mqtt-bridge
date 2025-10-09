<?php

declare(strict_types=1);

namespace Fossibot\Device;

use DateTime;
use Fossibot\Commands\RegisterType;

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

    // Settings (from Registers 20, 57, 59-62, 66-68)
    public int $maxChargingCurrent = 0;         // Register 20: 1-20 Amperes
    public float $dischargeLowerLimit = 0.0;    // Register 66: 0-100%
    public float $acChargingUpperLimit = 100.0; // Register 67: 0-100%
    public bool $acSilentCharging = false;      // Register 57: AC Silent Charging enabled/disabled
    public int $usbStandbyTime = 0;             // Register 59: 0,3,5,10,30 minutes
    public int $acStandbyTime = 0;              // Register 60: 0,480,960,1440 minutes
    public int $dcStandbyTime = 0;              // Register 61: 0,480,960,1440 minutes
    public int $screenRestTime = 0;             // Register 62: 0,180,300,600,1800 seconds
    public int $sleepTime = 5;                  // Register 68: 5,10,30,480 minutes (NEVER 0!)

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
     * Hybrid strategy: INPUT registers contain realtime data (power, SOC),
     * HOLDING registers contain settings (limits, timeouts).
     *
     * @param array $registers Modbus registers (index => value)
     * @param RegisterType $registerType Type of registers (INPUT or HOLDING)
     * @param string|null $sourceTopic MQTT topic that triggered this update
     * @param bool $wasCommandTriggered Was this update triggered by a command we sent?
     */
    public function updateFromRegisters(
        array $registers,
        RegisterType $registerType,
        ?string $sourceTopic = null,
        bool $wasCommandTriggered = false
    ): void {
        $isImmediateResponse = $sourceTopic !== null && str_contains($sourceTopic, '/client/04');
        $isPollingData = $sourceTopic !== null && str_contains($sourceTopic, '/client/data');
        $now = new DateTime();

        // INPUT registers (FC 04): Realtime sensor data
        if ($registerType === RegisterType::INPUT) {
            // Battery (Register 56 = SoC)
            if (isset($registers[56])) {
                $this->soc = round($registers[56] / 1000 * 100, 1);
                $this->lastSocUpdate = $now;
            }

            // Power values (Registers 4, 6, 39)
            if (isset($registers[4])) {
                $this->dcInputWatts = $registers[4];
            }
            if (isset($registers[6])) {
                $this->inputWatts = $registers[6];
            }
            if (isset($registers[39])) {
                $this->outputWatts = $registers[39];
            }
        }

        // HOLDING registers (FC 03): Settings/Configuration
        if ($registerType === RegisterType::HOLDING) {
            if (isset($registers[20])) {
                $this->maxChargingCurrent = $registers[20];
            }
            if (isset($registers[57])) {
                $this->acSilentCharging = $registers[57] === 1;
            }
            if (isset($registers[59])) {
                $this->usbStandbyTime = $registers[59];
            }
            if (isset($registers[60])) {
                $this->acStandbyTime = $registers[60];
            }
            if (isset($registers[61])) {
                $this->dcStandbyTime = $registers[61];
            }
            if (isset($registers[62])) {
                $this->screenRestTime = $registers[62];
            }
            if (isset($registers[66])) {
                $this->dischargeLowerLimit = $registers[66] / 10.0;
            }
            if (isset($registers[67])) {
                $this->acChargingUpperLimit = $registers[67] / 10.0;
            }
            if (isset($registers[68])) {
                $this->sleepTime = $registers[68];
            }
        }

        // Output States (Register 41 bitfield) - REQUIRES PRIORITY HANDLING
        // Only available in INPUT registers
        // Per ARCHITECTURE.md, /client/04 is high-priority, /client/data is low-priority
        // to prevent overwriting fresh command responses with stale polling data.
        if ($registerType === RegisterType::INPUT && isset($registers[41])) {
            $shouldUpdateOutputs = false;
            if ($isImmediateResponse) {
                // /client/04 is high-priority, always update
                $shouldUpdateOutputs = true;
                $this->lastOutputUpdate = $now;

                // Track if this was spontaneous or command-triggered
                $this->lastUpdateWasSpontaneous = !$wasCommandTriggered;
                $this->lastUpdateSource = $wasCommandTriggered ? 'command' : 'spontaneous';
            } elseif ($isPollingData) {
                // /client/data is low-priority, only update if no recent high-priority message
                $timeSinceLastOutputUpdate = $now->getTimestamp() - $this->lastOutputUpdate->getTimestamp();
                if ($timeSinceLastOutputUpdate > 35) { // 35s > 30s polling interval
                    $shouldUpdateOutputs = true;
                }
            }

            if ($shouldUpdateOutputs) {
                $bitfield = $registers[41];
                // Hardware-verified bit mapping (Oct 2025):
                $this->usbOutput = ($bitfield & (1 << 9)) !== 0;   // Bit 9
                $this->dcOutput = ($bitfield & (1 << 10)) !== 0;  // Bit 10
                $this->acOutput = ($bitfield & 2052) !== 0;       // 0x804 = Bits 2, 11
                $this->ledOutput = ($bitfield & 4096) !== 0;      // 0x1000 = Bit 12
            }
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
