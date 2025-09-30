# 00 - Overview: Problem & Solution

**Document:** Overview & Context
**Audience:** New developers, project managers
**Reading Time:** ~10 minutes

---

## 📖 What is Fossibot?

Fossibot manufactures portable power station devices (e.g., F2400 with 2048Wh capacity). These devices can be controlled via a proprietary cloud solution:

- **Cloud API:** `api.bspapp.com` (3-stage auth: Anonymous → Login → MQTT Token)
- **MQTT Broker:** `mqtt.sydpower.com:8083` (MQTT over WebSocket)
- **Protocol:** Modbus-like binary commands with CRC-16 checksums
- **Device ID:** MAC address without colons (e.g., `7C2C67AB5F0E`)

---

## 🎯 The Problem

**Requirement:** Integrate Fossibot devices into smarthome systems (IP-Symcon, Home Assistant, Node-RED, etc.)

**Challenge:** Fossibot uses a proprietary MQTT protocol:
- Binary Modbus payloads (not human-readable JSON)
- Device-specific topics (`{mac}/device/response/client/04`)
- MQTT over WebSocket (not standard MQTT port 1883)
- No standard MQTT client can communicate directly

**Additional Challenges:**
- **Bidirectional Communication:** Need to both send commands AND receive state updates
- **Multiple Accounts:** Users may have devices on different Fossibot accounts
- **Always Online:** State updates come asynchronously, polling is inefficient

---

## 💡 The Solution: MQTT Bridge Daemon

A **protocol bridge** that translates between Fossibot Cloud and standard MQTT:

```
┌──────────────┐                  ┌──────────────┐                  ┌──────────────┐
│ Fossibot     │ MQTT/WebSocket   │  Bridge      │  Standard MQTT   │  Smarthome   │
│ Cloud        │ ←──────────────→ │  Daemon      │ ←──────────────→ │  Clients     │
│              │  Binary Modbus   │              │  JSON Messages   │ (IP-Symcon,  │
│              │                  │              │                  │  Home Asst.) │
└──────────────┘                  └──────────────┘                  └──────────────┘
```

### Key Features

- ✅ **Bidirectional:** Daemon holds persistent connections, receives state updates immediately
- ✅ **Protocol Translation:** Binary Modbus ↔ Human-readable JSON
- ✅ **Topic Normalization:** `{mac}/device/response/...` → `fossibot/{mac}/state`
- ✅ **Multi-Account:** One daemon manages multiple Fossibot accounts simultaneously
- ✅ **Standard MQTT:** Any MQTT client can control devices (no proprietary library needed)
- ✅ **Event-Driven:** ReactPHP event loop for efficient, non-blocking operation

---

## 🏗️ Architecture Principles

### 1. **Asynchronous Event Loop (ReactPHP)**

**Why not synchronous?**
- PHP is single-threaded
- Blocking I/O would prevent simultaneous send/receive
- Multiple cloud connections require concurrent operation

**Solution:** ReactPHP event loop
- Non-blocking I/O for all network operations
- Event-driven callbacks for messages, connections, errors
- One process handles all accounts efficiently

### 2. **Multi-Account Support**

**Design:**
- Single daemon process
- Array of cloud clients (one per account in `config.json`)
- Shared local MQTT broker client
- Centralized state management for all devices

**Benefits:**
- Resource efficient (one process, one broker connection)
- Simplified deployment (no need to manage multiple daemon instances)
- Cross-account device overview possible

### 3. **Standard MQTT Interface**

**Why Mosquitto as middleware?**
- Standard MQTT protocol (widely supported)
- Existing tooling (mosquitto_sub/pub, MQTT Explorer)
- Easy integration with all smarthome systems
- Retained messages for current state
- Last Will & Testament for availability

---

## 📊 Technology Stack

### Core Technologies

| Component | Technology | Purpose |
|-----------|-----------|---------|
| **Event Loop** | `react/event-loop` | Non-blocking I/O, event-driven architecture |
| **WebSocket Transport** | `ratchet/pawl` | Async WebSocket client for Fossibot Cloud |
| **MQTT Protocol** | `php-mqtt/client` | MQTT v3/v5 for Cloud & Broker communication |
| **Local Broker** | Mosquitto | Standard MQTT message routing |
| **Process Management** | systemd | Daemon lifecycle (start/stop/restart) |
| **Logging** | Monolog | Structured logging with rotation |

### Why These Choices?

**ReactPHP over Amp/Swoole:**
- Most mature PHP async ecosystem
- Large community, well-documented
- Native event loop without PHP extensions

**php-mqtt/client over react/mqtt:**
- Actively maintained (react/mqtt is stagnant)
- Supports MQTT v5
- Framework-agnostic (works with any event loop)

**Pawl for WebSocket:**
- ReactPHP-native
- Async by design
- Used with php-mqtt/client for MQTT-over-WebSocket

**systemd over custom daemonization:**
- Industry standard on Linux
- Automatic restart on crash
- Logging integration (journald)
- No need to implement PID management

---

## 🎛️ How It Works

### Message Flow: Cloud → Clients

```
1. Fossibot Device sends state update
   ↓
2. Fossibot Cloud forwards via MQTT/WebSocket
   Topic: 7C2C67AB5F0E/device/response/client/04
   Payload: <binary Modbus registers>
   ↓
3. Bridge receives on AsyncCloudClient (ReactPHP event)
   ↓
4. PayloadTransformer parses Modbus → DeviceState object
   ↓
5. TopicTranslator maps topic → fossibot/7C2C67AB5F0E/state
   ↓
6. Bridge publishes JSON to Mosquitto (retained, QoS 1)
   ↓
7. All subscribed clients receive JSON state update
```

### Message Flow: Clients → Cloud

```
1. Smarthome client publishes command
   Topic: fossibot/7C2C67AB5F0E/command
   Payload: {"action": "usb_on"}
   ↓
2. Bridge receives via Mosquitto subscription
   ↓
3. PayloadTransformer converts JSON → Command object
   ↓
4. Command.toModbus() generates binary Modbus payload
   ↓
5. TopicTranslator maps to cloud topic
   Topic: 7C2C67AB5F0E/client/request/data
   ↓
6. Bridge publishes to Fossibot Cloud via WebSocket
   ↓
7. Device executes command (e.g., USB port turns on)
```

---

## 📈 Benefits Over Direct Integration

| Aspect | Direct Integration | Bridge Architecture |
|--------|-------------------|---------------------|
| **Client Complexity** | Each client needs custom Fossibot library | Standard MQTT client library |
| **Authentication** | Each client must handle 3-stage auth | Bridge handles auth centrally |
| **State Sync** | Polling or websocket per client | Mosquitto retained messages |
| **Multi-Account** | Complex client-side logic | Bridge manages transparently |
| **Debugging** | Binary Modbus payloads | Human-readable JSON |
| **Reconnect Logic** | Implement in every client | Bridge handles robustly |

---

## 🚀 What Gets Built

### New Components

1. **AsyncCloudClient** (`src/Bridge/AsyncCloudClient.php`)
   - Async connection to Fossibot Cloud (Pawl + php-mqtt/client)
   - Event emitter (on('message'), on('disconnect'))
   - Handles 3-stage authentication flow

2. **MqttBridge** (`src/Bridge/MqttBridge.php`)
   - Main orchestrator with ReactPHP event loop
   - Manages array of AsyncCloudClient instances (multi-account)
   - Routes messages between cloud and broker
   - State management and reconnect logic

3. **CLI Entry Point** (`daemon/fossibot-bridge.php`)
   - Loads config.json
   - Initializes bridge
   - Signal handling (SIGTERM/SIGINT)
   - Runs as foreground process (systemd manages daemon)

### Existing Components (Reused)

- ✅ `Connection.php` - 3-stage auth logic (works as-is)
- ✅ `Commands/*` - All command classes (UsbOn, AcOff, etc.)
- ✅ `Device/Device.php` - Device value object
- ✅ `Device/DeviceState.php` - State value object
- ✅ `Device/DeviceStateManager.php` - State registry

### Removed Components

- ❌ `Queue/QueueManager.php` - Mosquitto handles queuing
- ❌ `Queue/ConnectionQueue.php` - Not needed with async
- ❌ `Contracts/CommandExecutor.php` - Bridge pattern replaces
- ❌ `Device/DeviceFacade.php` - Clients use MQTT directly
- ❌ `Contracts/ResponseListener.php` - Event system replaces
- ❌ `Parsing/ModbusResponseParser.php` - Integrated into bridge

---

## ⏱️ Project Timeline

| Phase | Description | Effort |
|-------|-------------|--------|
| **Phase 0** | Setup ReactPHP, config, cleanup | 3h |
| **Phase 1** | AsyncCloudClient implementation | 5h |
| **Phase 2** | MqttBridge multi-account | 6h |
| **Phase 3** | Reconnect & error handling | 4h |
| **Phase 4** | CLI & systemd integration | 3h |
| **Phase 5** | Documentation & examples | 2h |
| **TOTAL** | | **~23h** |

**Estimated calendar time:** 3-4 work days (including testing and learning curve)

---

## 🎓 Prerequisites

**Required Knowledge:**
- PHP 8.4+ (especially async concepts helpful but not required)
- MQTT basics (topics, QoS, retained messages)
- Basic Linux/systemd (for deployment)

**Required Tools:**
- Composer
- Mosquitto broker
- Git
- PHP-FPM or CLI

**Helpful but Optional:**
- ReactPHP experience (will learn during implementation)
- MQTT Explorer (for debugging)
- Docker (for containerized deployment)

---

## 📚 Next Steps

1. **Understand Architecture:** Read [01-ARCHITECTURE.md](01-ARCHITECTURE.md)
2. **Learn MQTT Structure:** Read [02-TOPICS-MESSAGES.md](02-TOPICS-MESSAGES.md)
3. **Start Implementation:** Begin with [03-PHASE-0-SETUP.md](03-PHASE-0-SETUP.md)

---

**Ready to dive deeper?** → [01-ARCHITECTURE.md](01-ARCHITECTURE.md)