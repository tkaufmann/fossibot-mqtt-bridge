# 02 - MQTT Topics & Message Formats

**Document:** Complete MQTT protocol specification
**Audience:** Developers, integration engineers
**Reading Time:** ~10 minutes

---

## 📡 Topic Structure Overview

The bridge translates between two MQTT topic namespaces:

| Side | Namespace | Format | Example |
|------|-----------|--------|---------|
| **Fossibot Cloud** | Device-centric | `{mac}/...` | `7C2C67AB5F0E/device/response/client/04` |
| **Local Broker** | Service-centric | `fossibot/...` | `fossibot/7C2C67AB5F0E/state` |

---

## 🌐 Fossibot Cloud Topics (Proprietary)

These topics are used between the bridge and Fossibot Cloud.

### Device → Cloud (Bridge Subscribes)

**Pattern:** `{mac}/device/response/+`

| Topic | Purpose | Payload Format | Frequency |
|-------|---------|----------------|-----------|
| `{mac}/device/response/client/04` | State updates (outputs) | Binary Modbus | Every 30s or on change |
| `{mac}/device/response/client/data` | Settings updates | Binary Modbus | On request |
| `{mac}/device/response/state` | (Not currently used) | Unknown | N/A |

**Example:**
```
Topic: 7C2C67AB5F0E/device/response/client/04
Payload: 0x11030000a2... (162 bytes Modbus)
```

### Cloud → Device (Bridge Publishes)

**Pattern:** `{mac}/client/request/data`

| Topic | Purpose | Payload Format | QoS |
|-------|---------|----------------|-----|
| `{mac}/client/request/data` | All commands | Binary Modbus | 1 |

**Example:**
```
Topic: 7C2C67AB5F0E/client/request/data
Payload: 0x11060029000140d9 (USB On command)
```

---

## 🏠 Local Broker Topics (Standard MQTT)

These topics are used between the bridge and smarthome clients.

### Bridge → Clients (Bridge Publishes)

#### 1. Device State

**Topic:** `fossibot/{mac}/state`
**Retained:** Yes (clients can read last state immediately)
**QoS:** 1 (at least once delivery)
**Update Frequency:** Every 30s or on device change

**Payload:** JSON object with current device state

```json
{
    "soc": 85.5,
    "usbOutput": true,
    "acOutput": false,
    "dcOutput": false,
    "ledOutput": true,
    "maxChargingCurrent": 12,
    "dischargeLowerLimit": 10.0,
    "acChargingUpperLimit": 90.0,
    "timestamp": "2025-09-30T12:34:56Z"
}
```

**Field Descriptions:**

| Field | Type | Unit | Range | Description |
|-------|------|------|-------|-------------|
| `soc` | float | % | 0.0 - 100.0 | State of Charge (battery percentage) |
| `usbOutput` | bool | - | true/false | USB ports power state |
| `acOutput` | bool | - | true/false | AC outlets power state |
| `dcOutput` | bool | - | true/false | DC ports power state |
| `ledOutput` | bool | - | true/false | LED lights power state |
| `maxChargingCurrent` | int | Amperes | 1 - 20 | Maximum charging current setting |
| `dischargeLowerLimit` | float | % | 0.0 - 100.0 | Discharge cutoff percentage |
| `acChargingUpperLimit` | float | % | 0.0 - 100.0 | AC charging stop percentage |
| `timestamp` | string | ISO8601 | - | Last update time (UTC) |

---

#### 2. Device Availability

**Topic:** `fossibot/{mac}/availability`
**Retained:** Yes
**QoS:** 1

**Payload:** Simple string (not JSON)

```
online
```

or

```
offline
```

**When Published:**
- `online` - When bridge successfully connects to device
- `offline` - When bridge detects device disconnect or bridge shuts down

**Use Case:** Clients can check if a device is currently reachable

---

#### 3. Bridge Status

**Topic:** `fossibot/bridge/status`
**Retained:** Yes
**QoS:** 1
**Update Frequency:** Every 60s

**Payload:** JSON object with bridge status

```json
{
    "status": "online",
    "version": "2.0.0",
    "uptime_seconds": 3600,
    "accounts": [
        {
            "email": "user@example.com",
            "connected": true,
            "device_count": 2
        }
    ],
    "devices": [
        {
            "id": "7C2C67AB5F0E",
            "name": "F2400 Living Room",
            "model": "F2400",
            "cloudConnected": true,
            "lastSeen": "2025-09-30T12:34:56Z"
        }
    ],
    "timestamp": "2025-09-30T12:34:56Z"
}
```

**Field Descriptions:**

| Field | Type | Description |
|-------|------|-------------|
| `status` | string | Bridge status: "online", "starting", "error" |
| `version` | string | Bridge software version |
| `uptime_seconds` | int | Seconds since bridge started |
| `accounts` | array | List of configured accounts |
| `accounts[].email` | string | Account email (partially masked for privacy) |
| `accounts[].connected` | bool | Cloud connection status for this account |
| `accounts[].device_count` | int | Number of devices on this account |
| `devices` | array | All discovered devices across all accounts |
| `devices[].id` | string | Device MAC address (unique identifier) |
| `devices[].name` | string | Human-readable device name |
| `devices[].model` | string | Device model (F2400, F3000, etc.) |
| `devices[].cloudConnected` | bool | Device reachability |
| `devices[].lastSeen` | string | Last successful communication (ISO8601) |
| `timestamp` | string | Status message timestamp (ISO8601) |

---

### Clients → Bridge (Bridge Subscribes)

#### 1. Device Commands

**Topic Pattern:** `fossibot/{mac}/command`
**QoS:** 1 (ensure command delivery)

**Payload:** JSON object with action and optional parameters

**Supported Actions:**

##### Output Control (Immediate Response)

```json
{"action": "usb_on"}
{"action": "usb_off"}
{"action": "ac_on"}
{"action": "ac_off"}
{"action": "dc_on"}
{"action": "dc_off"}
{"action": "led_on"}
{"action": "led_off"}
```

##### Settings (Delayed Response)

```json
{"action": "set_charging_current", "amperes": 12}
{"action": "set_discharge_limit", "percentage": 10.0}
{"action": "set_ac_charging_limit", "percentage": 90.0}
```

##### State Query

```json
{"action": "read_settings"}
```

**Parameter Validation:**

| Action | Parameter | Type | Range | Required |
|--------|-----------|------|-------|----------|
| `set_charging_current` | `amperes` | int | 1 - 20 | Yes |
| `set_discharge_limit` | `percentage` | float | 0.0 - 100.0 | Yes |
| `set_ac_charging_limit` | `percentage` | float | 0.0 - 100.0 | Yes |

**Error Handling:**

If invalid command received, bridge logs error but does NOT publish error message back (fire-and-forget model).

---

## 🔄 Message Flow Examples

### Example 1: Client Turns USB On

```
1. Client publishes:
   Topic: fossibot/7C2C67AB5F0E/command
   Payload: {"action": "usb_on"}
   ↓
2. Bridge receives via Mosquitto subscription
   ↓
3. Bridge transforms:
   JSON → UsbOutputCommand(true)
   Command → Binary Modbus: 0x11060029000140d9
   ↓
4. Bridge publishes to cloud:
   Topic: 7C2C67AB5F0E/client/request/data
   Payload: 0x11060029000140d9
   ↓
5. Device executes command (USB ports turn on)
   ↓
6. Device sends state update (~1-2 seconds later):
   Topic: 7C2C67AB5F0E/device/response/client/04
   Payload: <binary Modbus with usbOutput=true>
   ↓
7. Bridge receives and transforms:
   Binary → Registers → DeviceState → JSON
   ↓
8. Bridge publishes to broker (retained):
   Topic: fossibot/7C2C67AB5F0E/state
   Payload: {"soc": 85.5, "usbOutput": true, ...}
   ↓
9. All subscribed clients receive updated state
```

**Latency:** ~1-3 seconds from command to state confirmation

---

### Example 2: Device Sends Periodic State Update

```
1. Device autonomously publishes state (every 30s):
   Topic: 7C2C67AB5F0E/device/response/client/04
   Payload: <binary Modbus>
   ↓
2. Bridge AsyncCloudClient emits 'message' event
   ↓
3. Bridge handleCloudMessage():
   - Parse Modbus payload
   - Update DeviceStateManager
   - Transform to JSON
   ↓
4. Bridge publishes to broker (retained):
   Topic: fossibot/7C2C67AB5F0E/state
   Payload: {"soc": 84.2, ...}  (SoC decreased)
   ↓
5. All clients receive update
```

**Frequency:** Every 30 seconds (cloud-controlled)

---

## 🧪 Testing MQTT Topics

### Command Line Tools

**Monitor all device states:**
```bash
mosquitto_sub -h localhost -t 'fossibot/+/state' -v
```

**Monitor specific device:**
```bash
mosquitto_sub -h localhost -t 'fossibot/7C2C67AB5F0E/state'
```

**Monitor bridge status:**
```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status'
```

**Send command:**
```bash
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'
```

**Check availability:**
```bash
mosquitto_sub -h localhost -t 'fossibot/+/availability' -v
```

---

### MQTT Explorer

**Recommended tool:** [MQTT Explorer](http://mqtt-explorer.com/)

**Configuration:**
- Protocol: mqtt://
- Host: localhost
- Port: 1883
- Username: (none, unless configured)
- Password: (none, unless configured)

**View retained messages:**
All `fossibot/*/state` and availability topics show last known state immediately upon connection.

---

## 📋 Topic Naming Conventions

### Why `fossibot/` prefix?

- **Namespace isolation:** Prevents conflicts with other MQTT services
- **Multi-tenancy:** Easy to add other services (e.g., `zigbee/`, `zwave/`)
- **Wildcard subscriptions:** Clients can subscribe to `fossibot/#` for all Fossibot messages

### Why MAC address in topic?

- **Uniqueness:** MAC addresses are globally unique hardware identifiers
- **Stability:** Never changes (unlike device names which users can change)
- **Discovery:** Clients can discover all devices via `fossibot/+/state` wildcard

### Alternative considered (device name):

❌ `fossibot/living-room-powerstation/state`

**Problems:**
- Not unique (user might have two devices with same name)
- Changes when user renames device
- Spaces/special characters require URL encoding

---

## 🔐 Security Considerations

### Local Broker Security

**Current:** No authentication (localhost only)

**Production Recommendations:**
1. **Enable Mosquitto ACL:**
   ```
   # /etc/mosquitto/acl
   # Bridge has full access
   user bridge_user
   topic readwrite fossibot/#

   # Clients can only read state
   user smarthome_client
   topic read fossibot/+/state
   topic read fossibot/+/availability
   topic read fossibot/bridge/status
   topic write fossibot/+/command
   ```

2. **TLS encryption (if remote access needed):**
   ```
   # /etc/mosquitto/mosquitto.conf
   listener 8883
   cafile /etc/mosquitto/certs/ca.crt
   certfile /etc/mosquitto/certs/server.crt
   keyfile /etc/mosquitto/certs/server.key
   ```

---

## 🎯 Client Integration Examples

### IP-Symcon

```php
// Subscribe to state updates
$clientId = IPS_GetInstanceIDByIdent('MQTTClient', 0);
MQTT_Subscribe($clientId, 'fossibot/+/state');

// Read current state
$state = json_decode(MQTT_GetBuffer($clientId, 'fossibot/7C2C67AB5F0E/state'), true);
echo "Battery: " . $state['soc'] . "%\n";

// Send command
MQTT_Publish($clientId, 'fossibot/7C2C67AB5F0E/command', '{"action":"usb_on"}');
```

### Home Assistant (YAML)

```yaml
mqtt:
  sensor:
    - name: "Fossibot Battery"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.soc }}"
      unit_of_measurement: "%"
      device_class: battery

  binary_sensor:
    - name: "Fossibot Available"
      state_topic: "fossibot/7C2C67AB5F0E/availability"
      payload_on: "online"
      payload_off: "offline"

  switch:
    - name: "Fossibot USB Output"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      payload_on: '{"action":"usb_on"}'
      payload_off: '{"action":"usb_off"}'
      value_template: "{{ 'ON' if value_json.usbOutput else 'OFF' }}"
      optimistic: false
```

### Node-RED

```json
[
    {
        "id": "mqtt-in",
        "type": "mqtt in",
        "topic": "fossibot/+/state",
        "qos": "1",
        "broker": "local-broker"
    },
    {
        "id": "parse-json",
        "type": "json"
    },
    {
        "id": "mqtt-out",
        "type": "mqtt out",
        "topic": "fossibot/7C2C67AB5F0E/command",
        "qos": "1",
        "broker": "local-broker"
    }
]
```

---

## 📚 Next Steps

- **Understand Testing:** Read [09-TESTING.md](09-TESTING.md)
- **Start Implementation:** Begin with [03-PHASE-0-SETUP.md](03-PHASE-0-SETUP.md)

---

**Ready to implement?** → [03-PHASE-0-SETUP.md](03-PHASE-0-SETUP.md)