# 08 - Phase 5: Documentation & Examples

**Phase:** 5 - Documentation
**Effort:** ~2 hours
**Prerequisites:** Phase 4 complete (Daemon deployable)
**Deliverables:** User documentation, integration examples, README updates

---

## 🎯 Phase Goals

1. Update main README with daemon usage
2. Create integration examples (Home Assistant, Node-RED, IP-Symcon)
3. Document MQTT API for external developers
4. Add troubleshooting guide
5. Create quick start guide

---

## 📋 Step-by-Step Implementation

### Step 5.1: Update Main README (30 min)

**Update:** `README.md`

Add daemon section after existing content:

```markdown
## MQTT Bridge Daemon

The Fossibot MQTT Bridge provides a production-ready daemon that bridges Fossibot Cloud to a local MQTT broker, enabling seamless integration with home automation systems.

### Features

- 🔄 **Multi-Account Support** - Connect multiple Fossibot accounts simultaneously
- ⚡ **ReactPHP Event Loop** - Non-blocking async I/O for concurrent operations
- 🔌 **Standard MQTT** - Clean JSON payloads on standard MQTT topics
- 🛡️ **Auto-Reconnect** - Smart three-tier reconnection strategy
- 📊 **Status Monitoring** - Real-time bridge and device status
- 🔐 **Security Hardening** - systemd sandboxing and minimal privileges
- 📝 **Structured Logging** - Monolog with rotating file handler

### Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Copy config template
cp config/example.json config/config.json

# 3. Edit credentials
nano config/config.json

# 4. Start daemon
php daemon/fossibot-bridge.php --config config/config.json
```

### systemd Installation

```bash
# Install as system service
cd daemon
sudo ./install-systemd.sh

# Edit config
sudo nano /etc/fossibot/config.json

# Enable and start
sudo systemctl enable fossibot-bridge
sudo systemctl start fossibot-bridge

# Check status
sudo systemctl status fossibot-bridge
```

### MQTT Topics

#### Device State (Bridge → Clients)
```
Topic: fossibot/{mac}/state
Payload: {"soc": 85.5, "inputWatts": 450, "outputWatts": 120, "usbOutput": true, ...}
QoS: 1, Retained: Yes
```

#### Device Commands (Clients → Bridge)
```
Topic: fossibot/{mac}/command
Payload: {"action": "usb_on"}
QoS: 1
```

#### Bridge Status
```
Topic: fossibot/bridge/status
Payload: {"status": "online", "version": "2.0.0", ...}
QoS: 1, Retained: Yes
```

### Documentation

Complete documentation in `docs/daemon/`:

- **[00-OVERVIEW.md](docs/daemon/00-OVERVIEW.md)** - Architecture overview
- **[01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md)** - Technical design
- **[02-TOPICS-MESSAGES.md](docs/daemon/02-TOPICS-MESSAGES.md)** - MQTT protocol
- **[DEPLOYMENT.md](daemon/DEPLOYMENT.md)** - Production deployment guide

### Integration Examples

See `examples/` directory for:
- Home Assistant YAML configuration
- Node-RED flows
- IP-Symcon PHP scripts
- Python MQTT client examples

### Development

Implementation phases (for developers):
1. **[Phase 0](docs/daemon/03-PHASE-0-SETUP.md)** - Setup & dependencies
2. **[Phase 1](docs/daemon/04-PHASE-1-CLIENT.md)** - AsyncCloudClient
3. **[Phase 2](docs/daemon/05-PHASE-2-BRIDGE.md)** - MqttBridge
4. **[Phase 3](docs/daemon/06-PHASE-3-RECONNECT.md)** - Reconnect logic
5. **[Phase 4](docs/daemon/07-PHASE-4-CLI.md)** - CLI & systemd

Testing guide: **[09-TESTING.md](docs/daemon/09-TESTING.md)**
```

**Commit:**
```bash
git add README.md
git commit -m "docs: Add MQTT bridge daemon section to README"
```

**Deliverable:** ✅ README updated

---

### Step 5.2: Create Integration Examples (45 min)

**Create directory:**
```bash
mkdir -p examples
```

**File:** `examples/homeassistant.yaml`

```yaml
# ABOUTME: Home Assistant MQTT integration for Fossibot devices

mqtt:
  # Battery & Power Sensors
  sensor:
    - name: "Fossibot Battery"
      unique_id: "fossibot_7c2c67ab5f0e_battery"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.soc }}"
      unit_of_measurement: "%"
      device_class: battery
      icon: mdi:battery

    - name: "Fossibot Input Power"
      unique_id: "fossibot_7c2c67ab5f0e_input_power"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.inputWatts }}"
      unit_of_measurement: "W"
      device_class: power
      icon: mdi:solar-power

    - name: "Fossibot Output Power"
      unique_id: "fossibot_7c2c67ab5f0e_output_power"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.outputWatts }}"
      unit_of_measurement: "W"
      device_class: power
      icon: mdi:power-plug

    - name: "Fossibot Max Charging Current"
      unique_id: "fossibot_7c2c67ab5f0e_max_charging_current"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.maxChargingCurrent }}"
      unit_of_measurement: "A"
      device_class: current
      icon: mdi:current-ac

  # Availability Sensor
  binary_sensor:
    - name: "Fossibot Available"
      unique_id: "fossibot_7c2c67ab5f0e_availability"
      state_topic: "fossibot/7C2C67AB5F0E/availability"
      payload_on: "online"
      payload_off: "offline"
      device_class: connectivity

  # Output Switches
  switch:
    - name: "Fossibot USB Output"
      unique_id: "fossibot_7c2c67ab5f0e_usb"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      payload_on: '{"action":"usb_on"}'
      payload_off: '{"action":"usb_off"}'
      value_template: "{{ 'ON' if value_json.usbOutput else 'OFF' }}"
      optimistic: false
      icon: mdi:usb

    - name: "Fossibot AC Output"
      unique_id: "fossibot_7c2c67ab5f0e_ac"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      payload_on: '{"action":"ac_on"}'
      payload_off: '{"action":"ac_off"}'
      value_template: "{{ 'ON' if value_json.acOutput else 'OFF' }}"
      optimistic: false
      icon: mdi:power-socket-eu

    - name: "Fossibot DC Output"
      unique_id: "fossibot_7c2c67ab5f0e_dc"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      payload_on: '{"action":"dc_on"}'
      payload_off: '{"action":"dc_off"}'
      value_template: "{{ 'ON' if value_json.dcOutput else 'OFF' }}"
      optimistic: false
      icon: mdi:power-plug

    - name: "Fossibot LED Output"
      unique_id: "fossibot_7c2c67ab5f0e_led"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      payload_on: '{"action":"led_on"}'
      payload_off: '{"action":"led_off"}'
      value_template: "{{ 'ON' if value_json.ledOutput else 'OFF' }}"
      optimistic: false
      icon: mdi:lightbulb

  # Settings Number Inputs
  number:
    - name: "Fossibot Max Charging Current"
      unique_id: "fossibot_7c2c67ab5f0e_set_charging_current"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      command_template: '{"action":"set_charging_current","amperes":{{ value | int }}}'
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.maxChargingCurrent }}"
      min: 1
      max: 20
      step: 1
      unit_of_measurement: "A"
      icon: mdi:current-ac

    - name: "Fossibot Discharge Lower Limit"
      unique_id: "fossibot_7c2c67ab5f0e_discharge_limit"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      command_template: '{"action":"set_discharge_limit","percentage":{{ value | float }}}'
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.dischargeLowerLimit }}"
      min: 0
      max: 100
      step: 1
      unit_of_measurement: "%"
      icon: mdi:battery-arrow-down

    - name: "Fossibot AC Charging Upper Limit"
      unique_id: "fossibot_7c2c67ab5f0e_ac_charging_limit"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      command_template: '{"action":"set_ac_charging_limit","percentage":{{ value | float }}}'
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.acChargingUpperLimit }}"
      min: 0
      max: 100
      step: 1
      unit_of_measurement: "%"
      icon: mdi:battery-arrow-up

# Optional: Automation Example
automation:
  - alias: "Fossibot - Turn on AC when battery high"
    trigger:
      - platform: numeric_state
        entity_id: sensor.fossibot_battery
        above: 90
    action:
      - service: switch.turn_on
        target:
          entity_id: switch.fossibot_ac_output
```

**File:** `examples/nodered.json`

```json
[
  {
    "id": "mqtt-in-state",
    "type": "mqtt in",
    "name": "Fossibot State",
    "topic": "fossibot/+/state",
    "qos": "1",
    "broker": "local-broker",
    "outputs": 1,
    "x": 120,
    "y": 100,
    "wires": [["parse-state"]]
  },
  {
    "id": "parse-state",
    "type": "json",
    "name": "Parse State",
    "x": 310,
    "y": 100,
    "wires": [["extract-soc", "debug-state"]]
  },
  {
    "id": "extract-soc",
    "type": "function",
    "name": "Extract SoC",
    "func": "msg.payload = {\n    mac: msg.topic.split('/')[1],\n    soc: msg.payload.soc,\n    timestamp: msg.payload.timestamp\n};\nreturn msg;",
    "x": 490,
    "y": 100,
    "wires": [["soc-gauge"]]
  },
  {
    "id": "debug-state",
    "type": "debug",
    "name": "Debug State",
    "x": 490,
    "y": 160,
    "wires": []
  },
  {
    "id": "soc-gauge",
    "type": "ui_gauge",
    "name": "Battery %",
    "group": "fossibot-dashboard",
    "min": 0,
    "max": 100,
    "unit": "%",
    "x": 670,
    "y": 100,
    "wires": []
  },
  {
    "id": "inject-usb-on",
    "type": "inject",
    "name": "USB On",
    "topic": "fossibot/7C2C67AB5F0E/command",
    "payload": "{\"action\":\"usb_on\"}",
    "payloadType": "str",
    "repeat": "",
    "once": false,
    "x": 120,
    "y": 260,
    "wires": [["mqtt-out-command"]]
  },
  {
    "id": "inject-usb-off",
    "type": "inject",
    "name": "USB Off",
    "topic": "fossibot/7C2C67AB5F0E/command",
    "payload": "{\"action\":\"usb_off\"}",
    "payloadType": "str",
    "repeat": "",
    "once": false,
    "x": 120,
    "y": 300,
    "wires": [["mqtt-out-command"]]
  },
  {
    "id": "mqtt-out-command",
    "type": "mqtt out",
    "name": "Send Command",
    "topic": "",
    "qos": "1",
    "broker": "local-broker",
    "x": 330,
    "y": 280,
    "wires": []
  },
  {
    "id": "local-broker",
    "type": "mqtt-broker",
    "name": "Local Mosquitto",
    "broker": "localhost",
    "port": "1883",
    "clientid": "nodered-fossibot"
  }
]
```

**File:** `examples/ipsymcon.php`

```php
<?php
// ABOUTME: IP-Symcon integration script for Fossibot MQTT Bridge

/**
 * Fossibot Device Module for IP-Symcon
 *
 * This module integrates Fossibot powerstation devices via MQTT.
 * Requires: MQTT Client module in IP-Symcon
 */

// Configuration
$mqttClientId = 12345; // Your MQTT Client instance ID
$deviceMac = '7C2C67AB5F0E'; // Your device MAC address

// Subscribe to device state updates
MQTT_Subscribe($mqttClientId, "fossibot/$deviceMac/state");

// Create variables for device state
$batteryVarId = CreateVariable('Battery', 2, '%', $mqttClientId); // Float
$usbOutputVarId = CreateVariable('USB Output', 0, '', $mqttClientId); // Boolean
$acOutputVarId = CreateVariable('AC Output', 0, '', $mqttClientId); // Boolean

/**
 * Message handler - called when MQTT message arrives
 */
function HandleMQTTMessage($topic, $payload) {
    global $deviceMac, $batteryVarId, $usbOutputVarId, $acOutputVarId;

    if ($topic === "fossibot/$deviceMac/state") {
        $state = json_decode($payload, true);

        // Update variables
        SetValue($batteryVarId, $state['soc']);
        SetValue($usbOutputVarId, $state['usbOutput']);
        SetValue($acOutputVarId, $state['acOutput']);
    }
}

/**
 * Action handler - called when user clicks button
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
 * Helper: Create variable if not exists
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
```

**File:** `examples/python_client.py`

```python
#!/usr/bin/env python3
"""
ABOUTME: Python MQTT client example for Fossibot Bridge

Simple Python script demonstrating device control and monitoring.
Requires: paho-mqtt (pip install paho-mqtt)
"""

import json
import time
import paho.mqtt.client as mqtt

MQTT_BROKER = "localhost"
MQTT_PORT = 1883
DEVICE_MAC = "7C2C67AB5F0E"

# Callbacks
def on_connect(client, userdata, flags, rc):
    print(f"Connected with result code {rc}")

    # Subscribe to device state
    client.subscribe(f"fossibot/{DEVICE_MAC}/state")
    client.subscribe(f"fossibot/{DEVICE_MAC}/availability")
    client.subscribe("fossibot/bridge/status")

    print("Subscribed to topics")

def on_message(client, userdata, msg):
    topic = msg.topic

    if topic.endswith("/state"):
        state = json.loads(msg.payload)
        print(f"\n📊 Device State Update:")
        print(f"  Battery: {state['soc']}%")
        print(f"  USB: {'ON' if state['usbOutput'] else 'OFF'}")
        print(f"  AC: {'ON' if state['acOutput'] else 'OFF'}")
        print(f"  Time: {state['timestamp']}")

    elif topic.endswith("/availability"):
        status = msg.payload.decode()
        print(f"\n🔌 Device: {status}")

    elif topic == "fossibot/bridge/status":
        status = json.loads(msg.payload)
        print(f"\n🌉 Bridge: {status['status']} (v{status['version']})")

# Create client
client = mqtt.Client("fossibot_python_client")
client.on_connect = on_connect
client.on_message = on_message

# Connect
print(f"Connecting to {MQTT_BROKER}:{MQTT_PORT}...")
client.connect(MQTT_BROKER, MQTT_PORT, 60)

# Start loop in background
client.loop_start()

# Example commands
def turn_usb_on():
    command = json.dumps({"action": "usb_on"})
    client.publish(f"fossibot/{DEVICE_MAC}/command", command, qos=1)
    print("✅ Sent: USB ON")

def turn_usb_off():
    command = json.dumps({"action": "usb_off"})
    client.publish(f"fossibot/{DEVICE_MAC}/command", command, qos=1)
    print("✅ Sent: USB OFF")

def set_charging_current(amperes: int):
    if not 1 <= amperes <= 20:
        print("❌ Error: Amperes must be 1-20")
        return

    command = json.dumps({
        "action": "set_charging_current",
        "amperes": amperes
    })
    client.publish(f"fossibot/{DEVICE_MAC}/command", command, qos=1)
    print(f"✅ Sent: Set charging current to {amperes}A")

# Interactive menu
print("\n" + "="*50)
print("Fossibot Device Control - Python Example")
print("="*50)

try:
    while True:
        print("\nCommands:")
        print("  1 - Turn USB ON")
        print("  2 - Turn USB OFF")
        print("  3 - Set charging current")
        print("  q - Quit")

        choice = input("\nEnter command: ").strip()

        if choice == "1":
            turn_usb_on()
        elif choice == "2":
            turn_usb_off()
        elif choice == "3":
            amperes = int(input("Enter amperes (1-20): "))
            set_charging_current(amperes)
        elif choice.lower() == "q":
            break
        else:
            print("Invalid command")

except KeyboardInterrupt:
    print("\n\nShutting down...")

finally:
    client.loop_stop()
    client.disconnect()
    print("Disconnected")
```

**Make Python script executable:**
```bash
chmod +x examples/python_client.py
```

**File:** `examples/README.md`

```markdown
# Integration Examples

This directory contains integration examples for various home automation platforms.

## Available Examples

### Home Assistant
**File:** `homeassistant.yaml`

Complete MQTT configuration for Home Assistant including:
- Battery sensor with device class
- Output switches (USB, AC, DC, LED)
- Settings controls (charging current, limits)
- Availability binary sensor
- Example automation

**Installation:**
1. Copy content to `configuration.yaml` under `mqtt:` section
2. Replace MAC address `7C2C67AB5F0E` with your device MAC
3. Restart Home Assistant
4. Find entities under "Fossibot" device

### Node-RED
**File:** `nodered.json`

Node-RED flow with:
- MQTT state subscriber with JSON parser
- SoC gauge widget
- Command inject buttons
- Debug output

**Installation:**
1. Open Node-RED
2. Menu → Import → Clipboard
3. Paste content of `nodered.json`
4. Configure MQTT broker node (localhost:1883)
5. Replace MAC address if needed
6. Deploy

### IP-Symcon
**File:** `ipsymcon.php`

IP-Symcon module with:
- MQTT message handler
- Variable creation for state
- Action functions for commands
- Helper functions

**Installation:**
1. Install MQTT Client module in IP-Symcon
2. Create new script module
3. Copy content of `ipsymcon.php`
4. Update `$mqttClientId` and `$deviceMac`
5. Save and run

### Python Client
**File:** `python_client.py`

Standalone Python MQTT client with:
- Device state monitoring
- Interactive command menu
- Example functions for all commands

**Installation:**
```bash
pip install paho-mqtt
python examples/python_client.py
```

## Finding Your Device MAC

```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v
```

Look for `devices[].id` field in the JSON response.

## Testing Examples

All examples assume:
- Mosquitto running on localhost:1883
- Fossibot Bridge daemon running
- At least one device connected

Verify bridge is running:
```bash
systemctl status fossibot-bridge
```

Monitor MQTT traffic:
```bash
mosquitto_sub -h localhost -t 'fossibot/#' -v
```
```

**Commit:**
```bash
git add examples/
git commit -m "docs: Add integration examples for HA, Node-RED, IP-Symcon, Python"
```

**Deliverable:** ✅ Integration examples created

---

### Step 5.3: Create Quick Start Guide (20 min)

**File:** `QUICKSTART.md`

```markdown
# Quick Start Guide

Get your Fossibot MQTT Bridge running in 5 minutes.

---

## Prerequisites

- Ubuntu/Debian Linux server (or Raspberry Pi)
- PHP 8.1+ with cli, mbstring, xml, curl extensions
- Mosquitto MQTT broker
- Fossibot account credentials

---

## Installation (5 Minutes)

### 1. Install System Dependencies

```bash
sudo apt update
sudo apt install php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl composer mosquitto -y
sudo systemctl enable mosquitto
sudo systemctl start mosquitto
```

### 2. Clone Repository

```bash
git clone https://github.com/youruser/fossibot-php2.git
cd fossibot-php2
```

### 3. Install PHP Dependencies

```bash
composer install --no-dev
```

### 4. Configure

```bash
cp config/example.json config/config.json
nano config/config.json
```

**Minimal config:**
```json
{
  "accounts": [
    {
      "email": "YOUR_EMAIL@example.com",
      "password": "YOUR_PASSWORD",
      "enabled": true
    }
  ],
  "mosquitto": {
    "host": "localhost",
    "port": 1883,
    "username": null,
    "password": null,
    "client_id": "fossibot_bridge"
  },
  "daemon": {
    "log_file": "logs/bridge.log",
    "log_level": "info"
  },
  "bridge": {
    "status_publish_interval": 60,
    "reconnect_delay_min": 5,
    "reconnect_delay_max": 60
  }
}
```

**Replace:** `YOUR_EMAIL@example.com` and `YOUR_PASSWORD`

### 5. Validate Config

```bash
php daemon/fossibot-bridge.php --config config/config.json --validate
```

Expected: `✅ Config valid`

### 6. Start Bridge

```bash
php daemon/fossibot-bridge.php --config config/config.json
```

Expected output:
```
Starting bridge (press Ctrl+C to stop)...
═══════════════════════════════════════

[2025-09-30 12:00:00] fossibot_bridge.INFO: Fossibot MQTT Bridge starting
[2025-09-30 12:00:01] fossibot_bridge.INFO: Connected to local MQTT broker
[2025-09-30 12:00:02] fossibot_bridge.INFO: Cloud client connected {"email":"your@email.com"}
[2025-09-30 12:00:03] fossibot_bridge.INFO: Discovered 1 devices
```

---

## Verification

### Check Device Discovery

Open another terminal:

```bash
mosquitto_sub -h localhost -t 'fossibot/+/state' -v
```

You should see device state messages within 30 seconds:
```json
fossibot/7C2C67AB5F0E/state {"soc":85.5,"usbOutput":true,...}
```

### Test Command

```bash
# Replace MAC with your device MAC from above
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'
```

Your device USB output should turn on!

---

## Install as System Service (Optional)

For production use, install as systemd service:

```bash
cd daemon
sudo ./install-systemd.sh

# Edit config
sudo nano /etc/fossibot/config.json

# Start service
sudo systemctl enable fossibot-bridge
sudo systemctl start fossibot-bridge

# Check status
sudo systemctl status fossibot-bridge
```

---

## Integration

Now connect your smart home platform:

- **Home Assistant:** Copy `examples/homeassistant.yaml` to your config
- **Node-RED:** Import `examples/nodered.json`
- **Python:** Run `python examples/python_client.py`

See `examples/README.md` for detailed integration guides.

---

## Troubleshooting

### Bridge won't start

**Check config syntax:**
```bash
php daemon/fossibot-bridge.php --config config/config.json --validate
```

**Check Mosquitto:**
```bash
sudo systemctl status mosquitto
```

### No devices discovered

**Check credentials:**
- Verify email/password are correct
- Test login on Fossibot mobile app or web interface

**Check logs:**
```bash
tail -f logs/bridge.log
```

Look for authentication errors (401/403).

### Device not responding to commands

**Check MQTT traffic:**
```bash
mosquitto_sub -h localhost -t 'fossibot/#' -v
```

You should see both state messages and command echoes.

**Verify device MAC:**
Check bridge status message for correct MAC address:
```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v
```

---

## Next Steps

- Read full documentation: `docs/daemon/`
- Deploy to production: `daemon/DEPLOYMENT.md`
- Explore MQTT API: `docs/daemon/02-TOPICS-MESSAGES.md`
- Join discussions: GitHub Issues

---

**Need help?** Open an issue on GitHub with:
- Output of `--validate` command
- Log excerpt showing error
- Your OS and PHP version
```

**Commit:**
```bash
git add QUICKSTART.md
git commit -m "docs: Add quick start guide"
```

**Deliverable:** ✅ Quick start guide

---

### Step 5.4: Create Troubleshooting Guide (25 min)

**File:** `docs/daemon/TROUBLESHOOTING.md`

```markdown
# Troubleshooting Guide

Common issues and solutions for Fossibot MQTT Bridge.

---

## Bridge Startup Issues

### Config validation fails

**Error:** `❌ Config validation failed`

**Causes:**
- Invalid JSON syntax
- Missing required fields
- Wrong data types

**Solution:**
```bash
# Validate config
php daemon/fossibot-bridge.php --config config/config.json --validate

# Check JSON syntax
cat config/config.json | json_pp
```

Common mistakes:
- Trailing comma in JSON
- Unquoted strings
- Missing closing braces

---

### Permission denied errors

**Error:** `Failed to read config file: Permission denied`

**Solution:**
```bash
# Check file permissions
ls -la config/config.json

# Fix permissions
chmod 600 config/config.json

# For systemd service:
sudo chown fossibot:fossibot /etc/fossibot/config.json
sudo chmod 600 /etc/fossibot/config.json
```

---

### Cannot connect to Mosquitto

**Error:** `Failed to connect to local broker: Connection refused`

**Causes:**
- Mosquitto not running
- Wrong host/port in config
- Firewall blocking connection

**Solution:**
```bash
# Check Mosquitto status
systemctl status mosquitto

# Start Mosquitto
sudo systemctl start mosquitto

# Test connection
mosquitto_pub -h localhost -t 'test' -m 'test'

# Check port is listening
sudo netstat -tlnp | grep 1883
```

---

## Authentication Issues

### Login fails (401 Unauthorized)

**Error:** `Stage 2 authentication failed: 401`

**Causes:**
- Wrong email/password
- Account locked
- Typo in credentials

**Solution:**
1. Test credentials on Fossibot mobile app
2. Check for extra spaces in config.json
3. Ensure password special characters are properly escaped
4. Try password reset on Fossibot website

---

### MQTT auth fails (CONNACK code 5)

**Error:** `MQTT authentication failed, code 5`

**Causes:**
- MQTT token expired
- Token parsing failed
- Clock skew on system

**Solution:**
```bash
# Check system time
date

# Sync time if needed
sudo ntpdate pool.ntp.org

# Check logs for token expiry
tail -f logs/bridge.log | grep token

# Force reconnect (bridge will auto re-auth)
sudo systemctl restart fossibot-bridge
```

---

## Connection Issues

### WebSocket connection drops frequently

**Symptoms:**
- Frequent disconnects in logs
- Bridge keeps reconnecting
- Exponential backoff delays

**Causes:**
- Network instability
- Firewall/NAT timeout
- ISP blocking WebSocket

**Solution:**
```bash
# Check reconnect patterns in logs
grep "reconnect" logs/bridge.log

# Test network stability
ping -c 100 mqtt.sydpower.com

# Check MTU size (WebSocket frames may be fragmented)
ip link show | grep mtu

# Try different network if persistent
```

---

### Bridge loses connection after hours

**Symptoms:**
- Works initially, stops after 3-6 hours
- Token expiry messages in logs

**Causes:**
- MQTT token expired (~3 days validity)
- Keep-alive timeout
- Memory leak

**Solution:**
```bash
# Check token expiry tracking
grep "token" logs/bridge.log

# Verify reconnect logic is working
tail -f logs/bridge.log

# Check memory usage
systemctl status fossibot-bridge | grep Memory

# If memory leak suspected:
sudo systemctl restart fossibot-bridge
```

---

## Device Discovery Issues

### No devices found

**Error:** `Discovered 0 devices`

**Causes:**
- Account has no devices
- Devices offline
- API response format changed

**Solution:**
```bash
# Check account on mobile app
# Verify devices are online

# Enable debug logging
# Edit config: "log_level": "debug"
sudo systemctl restart fossibot-bridge

# Check Stage 4 device discovery
grep "Stage 4" logs/bridge.log
```

---

### Devices appear offline

**Symptoms:**
- Device shows in bridge status
- No state messages published
- Availability shows "offline"

**Causes:**
- Device actually offline (check mobile app)
- Wrong MAC address subscription
- Device not sending messages

**Solution:**
```bash
# Monitor cloud topics directly
mosquitto_sub -h localhost -t '#' -v | grep 7C2C67AB5F0E

# Check device last seen timestamp
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v

# Power cycle device
```

---

## Command Issues

### Commands not working

**Symptoms:**
- Publish command, nothing happens
- No error messages
- Device state doesn't change

**Causes:**
- Wrong topic format
- Invalid JSON payload
- Wrong device MAC address
- Device busy/offline

**Solution:**
```bash
# Verify topic format
mosquitto_sub -h localhost -t 'fossibot/+/command' -v

# Test command manually
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'

# Watch for command translation in logs (debug level)
tail -f logs/bridge.log | grep command

# Verify device is online
mosquitto_sub -h localhost -t 'fossibot/7C2C67AB5F0E/availability'
```

---

### Settings commands delayed

**Symptoms:**
- Output commands work instantly
- Settings commands take 5-10 seconds
- Sometimes don't apply

**Explanation:** This is expected behavior. Settings commands use `CommandResponseType::DELAYED` and require device to send settings refresh.

**Workaround:**
```bash
# After settings command, send read_settings
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"set_charging_current","amperes":15}'

# Wait 2 seconds
sleep 2

# Request settings refresh
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"read_settings"}'
```

---

## Performance Issues

### High CPU usage

**Normal:** 1-5% CPU per account
**High:** >20% CPU

**Causes:**
- Event loop blocking on I/O
- Too frequent status publishing
- Log level set to debug

**Solution:**
```bash
# Check CPU usage
top -p $(pgrep -f fossibot-bridge)

# Reduce status interval (config.json)
"status_publish_interval": 120  # was 60

# Change log level to "info" or "warning"
"log_level": "info"

# Check for blocking operations in logs
grep "blocked" logs/bridge.log
```

---

### High memory usage

**Normal:** 30-80MB per account
**High:** >200MB

**Causes:**
- Memory leak
- Large message backlog
- Too many log handlers

**Solution:**
```bash
# Monitor memory over time
watch -n 60 'systemctl status fossibot-bridge | grep Memory'

# Enable memory limit in systemd
sudo systemctl edit fossibot-bridge
# Add: MemoryMax=256M

# Restart service
sudo systemctl restart fossibot-bridge

# Report memory leak with logs
```

---

## systemd Service Issues

### Service fails to start

**Error:** `systemd[1]: fossibot-bridge.service: Failed`

**Solution:**
```bash
# Check service status
sudo systemctl status fossibot-bridge -l

# View full logs
sudo journalctl -u fossibot-bridge -n 100

# Test manually
sudo -u fossibot php /opt/fossibot-bridge/daemon/fossibot-bridge.php \
  --config /etc/fossibot/config.json
```

---

### Service keeps restarting

**Symptoms:**
- `Restart=always` causing restart loop
- Bridge crashes immediately

**Solution:**
```bash
# Check crash reason
sudo journalctl -u fossibot-bridge -n 200

# Disable auto-restart temporarily
sudo systemctl edit fossibot-bridge
# Add: Restart=no

# Test manual start
sudo systemctl start fossibot-bridge
sudo systemctl status fossibot-bridge
```

---

## Logging Issues

### Log file not created

**Error:** `Failed to open log file`

**Solution:**
```bash
# Create log directory
sudo mkdir -p /var/log/fossibot
sudo chown fossibot:fossibot /var/log/fossibot
sudo chmod 755 /var/log/fossibot

# For systemd: Add to service file
ReadWritePaths=/var/log/fossibot
```

---

### Logs too verbose

**Problem:** Log file grows to GB size

**Solution:**
```bash
# Change log level
# Edit config: "log_level": "warning"

# Setup logrotate
sudo nano /etc/logrotate.d/fossibot

# Add:
/var/log/fossibot/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
}

# Test rotation
sudo logrotate -f /etc/logrotate.d/fossibot
```

---

## Getting Help

If issue persists:

1. **Enable debug logging:**
   ```json
   "log_level": "debug"
   ```

2. **Collect logs:**
   ```bash
   tail -n 500 logs/bridge.log > debug.log
   ```

3. **Check versions:**
   ```bash
   php --version
   composer show | grep react
   mosquitto -h | head -n 1
   ```

4. **Open GitHub issue** with:
   - Debug log excerpt
   - Config (remove passwords!)
   - PHP version
   - OS version
   - Steps to reproduce

---

**Still stuck?** Search existing issues or open a new one on GitHub.
```

**Commit:**
```bash
git add docs/daemon/TROUBLESHOOTING.md
git commit -m "docs: Add comprehensive troubleshooting guide"
```

**Deliverable:** ✅ Troubleshooting guide

---

## ✅ Phase 5 Completion Checklist

- [ ] README.md updated with daemon section
- [ ] Integration examples created (HA, Node-RED, IP-Symcon, Python)
- [ ] Quick start guide written
- [ ] Troubleshooting guide comprehensive
- [ ] Examples README with installation instructions
- [ ] All example scripts tested for syntax
- [ ] All commits made with proper messages

---

## 🎯 Success Criteria

**Phase 5 is complete when:**

1. README.md includes daemon overview with links
2. At least 4 integration examples exist (HA, Node-RED, IPS, Python)
3. QUICKSTART.md provides 5-minute setup path
4. TROUBLESHOOTING.md covers common issues
5. Examples have clear installation instructions
6. All documentation uses consistent formatting
7. Code examples use correct syntax

---

## 📚 Next Steps

**Phase 5 complete!** → [09-TESTING.md](09-TESTING.md)

Document comprehensive testing strategy and test suite.