<?php
// ABOUTME: IP-Symcon-Integrationsscript für Fossibot MQTT Bridge

/**
 * Fossibot Device Module für IP-Symcon
 *
 * Integriert Fossibot-Geräte via MQTT.
 * Benötigt: MQTT Client Modul in IP-Symcon
 */

// Konfiguration
$mqttClientId = 12345; // Deine MQTT Client Instanz-ID
$deviceMac = '7C2C67AB5F0E'; // WICHTIG: Ersetze mit deiner Geräte-MAC

// Subscribe zu Device State Updates
MQTT_Subscribe($mqttClientId, "fossibot/$deviceMac/state");

// Erstelle Variablen für Device State
$batteryVarId = CreateVariable('Battery', 2, '%', $mqttClientId); // Float
$usbOutputVarId = CreateVariable('USB Output', 0, '', $mqttClientId); // Boolean
$acOutputVarId = CreateVariable('AC Output', 0, '', $mqttClientId); // Boolean

/**
 * Message Handler - wird bei eingehenden MQTT-Nachrichten aufgerufen
 */
function HandleMQTTMessage($topic, $payload) {
    global $deviceMac, $batteryVarId, $usbOutputVarId, $acOutputVarId;

    if ($topic === "fossibot/$deviceMac/state") {
        $state = json_decode($payload, true);

        // Update Variablen
        SetValue($batteryVarId, $state['soc']);
        SetValue($usbOutputVarId, $state['usbOutput']);
        SetValue($acOutputVarId, $state['acOutput']);
    }
}

/**
 * Action Handler - wird bei Button-Klicks aufgerufen
 */
function TurnUSBOn() {
    global $mqttClientId, $deviceMac;

    $command = json_encode(['action' => 'usb_on']);
    MQTT_Publish($mqttClientId, "fossibot/$deviceMac/command", $command);
}

function TurnUSBOff() {
    global $mqttClientId, $deviceMac;

    $command = json_encode(['action' => 'usb_off']);
    MQTT_Publish($mqttClientId, "fossibot/$deviceMac/command", $command);
}

function SetChargingCurrent(int $amperes) {
    global $mqttClientId, $deviceMac;

    if ($amperes < 1 || $amperes > 20) {
        echo "Error: Amperes must be 1-20\n";
        return;
    }

    $command = json_encode([
        'action' => 'set_charging_current',
        'amperes' => $amperes
    ]);

    MQTT_Publish($mqttClientId, "fossibot/$deviceMac/command", $command);
}

/**
 * Helper: Variable erstellen wenn nicht vorhanden
 */
function CreateVariable(string $name, int $type, string $profile, int $parentId): int {
    $varId = @IPS_GetObjectIDByIdent($name, $parentId);

    if ($varId === false) {
        $varId = IPS_CreateVariable($type);
        IPS_SetParent($varId, $parentId);
        IPS_SetName($varId, $name);
        IPS_SetIdent($varId, $name);
        IPS_SetVariableCustomProfile($varId, $profile);
    }

    return $varId;
}
