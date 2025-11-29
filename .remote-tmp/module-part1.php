<?php

declare(strict_types=1);

/**
 * FossibotLocalControl - Lokale MQTT-Steuerung für Fossibot F2400
 *
 * Ersetzt cloud-basiertes FossibotControl Modul durch direkten MODBUS-over-MQTT Zugriff.
 * Parst MODBUS-Payloads von Variable #11522 und steuert Gerät lokal.
 */
class FossibotLocalControl extends IPSModule
{
    // Hardcoded values (Phase 8: make configurable!)
    private const MQTT_VARIABLE_ID = 11522;
    private const DEVICE_MAC = "7C2C67AB5F0E";
    private const MQTT_SERVER_INSTANCE_ID = 53258;  // TODO: Actual MQTT Server ID!

    private const REQUEST_TOPIC = "7C2C67AB5F0E/client/request/data";
    private const RESPONSE_TOPIC = "7C2C67AB5F0E/device/response/client/data";

    // Phase 4: Cached queue instance (persistent across calls)
    private static $queueInstance = null;

    public function Create()
    {
        parent::Create();

        IPS_LogMessage('FBLC', 'Create() called - creating variables');

        // Phase 3: Register variables with camelCase idents (matching fossibot-bridge)
        $this->RegisterVariables();

        // Phase 4: Register command queue timer (200ms interval)
        $this->RegisterTimer('QueueTimer', 0, 'FBLC_ProcessQueue($_IPS["TARGET"]);');

        IPS_LogMessage('FBLC', 'All variables registered');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        IPS_LogMessage('FBLC', 'ApplyChanges() - registering for Variable #' . self::MQTT_VARIABLE_ID);

        // Register for MQTT input variable updates (Variable #11522)
        $this->RegisterMessage(self::MQTT_VARIABLE_ID, VM_UPDATE);


        // Set status
        $this->SetStatus(102); // OK

        IPS_LogMessage('FBLC', 'ApplyChanges() finished');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    private function RegisterVariables()
    {
        // === Real-Time Status Variables ===
        // Using camelCase idents matching fossibot-bridge output

        // Battery & Power
        $this->RegisterVariableFloat('soc', 'Ladezustand', '~Battery.100', 10);
        $this->RegisterVariableInteger('inputWatts', 'Eingangsleistung', '~Watt.3680', 20);
        $this->RegisterVariableInteger('outputWatts', 'Ausgangsleistung', '~Watt.3680', 30);
        $this->RegisterVariableInteger('dcInputWatts', 'DC Eingangsleistung', '~Watt.3680', 40);

        // Output States (Boolean switches)
        $this->RegisterVariableBoolean('usbOutput', 'USB Ausgang', '~Switch', 50);
        $this->RegisterVariableBoolean('acOutput', 'AC Ausgang', '~Switch', 60);
        $this->RegisterVariableBoolean('dcOutput', 'DC Ausgang', '~Switch', 70);
        $this->RegisterVariableBoolean('ledOutput', 'LED', '~Switch', 80);

        // === Settings Variables ===

        $this->RegisterVariableInteger('maxChargingCurrent', 'Max. Ladestrom (A)', '', 100);
        $this->RegisterVariableFloat('dischargeLowerLimit', 'Entlade-Untergrenze', '~Battery.100', 110);
        $this->RegisterVariableFloat('acChargingUpperLimit', 'AC Lade-Obergrenze', '~Battery.100', 120);
        $this->RegisterVariableBoolean('acSilentCharging', 'AC Leise-Laden', '~Switch', 130);
        $this->RegisterVariableInteger('usbStandbyTime', 'USB Standby-Zeit (min)', '', 140);
        $this->RegisterVariableInteger('acStandbyTime', 'AC Standby-Zeit (min)', '', 150);
        $this->RegisterVariableInteger('dcStandbyTime', 'DC Standby-Zeit (min)', '', 160);
        $this->RegisterVariableInteger('screenRestTime', 'Screen Rest Time (s)', '', 170);
        $this->RegisterVariableInteger('sleepTime', 'Sleep Time (min)', '', 180);
        $this->RegisterVariableInteger('acChargingTimer', 'AC Charging Timer (min)', '', 190);

        IPS_LogMessage('FBLC', 'All variables registered successfully');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE && $SenderID == self::MQTT_VARIABLE_ID) {
            // Variable #11522 updated → new MODBUS payload
            $binaryPayload = GetValue(self::MQTT_VARIABLE_ID);
            $this->ProcessModbusPayload($binaryPayload);
        }
    }

    /**
     * Phase 4: Queue timer callback - processes next command in queue.
     * Called by IP-Symcon timer every 200ms when queue is active.
     */
    public function ProcessQueue()
    {
        $queue = $this->getQueue();

        // Process next command
        $hasMore = $queue->processNext();

        if ($hasMore) {
            // More commands in queue → keep timer running
            $this->SetTimerInterval('QueueTimer', 200);
        } else {
            // Queue empty → stop timer
            $this->SetTimerInterval('QueueTimer', 0);
        }
    }
