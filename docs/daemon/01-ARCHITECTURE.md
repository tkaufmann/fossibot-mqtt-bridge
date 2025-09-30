# 01 - Architecture: ReactPHP + Multi-Account Design

**Document:** Detailed Architecture
**Audience:** Developers implementing the bridge
**Reading Time:** ~15 minutes

---

## 🏗️ High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    Fossibot Cloud Accounts                      │
│                                                                 │
│  Account 1           Account 2           Account 3             │
│  user1@ex.com        user2@ex.com        user3@ex.com          │
│  (2 devices)         (1 device)          (3 devices)           │
└────────┬────────────────┬────────────────────┬─────────────────┘
         │                │                    │
         │ MQTT/WS        │ MQTT/WS            │ MQTT/WS
         │                │                    │
┌────────▼────────────────▼────────────────────▼─────────────────┐
│              Fossibot MQTT Bridge Daemon                       │
│              (Single Process, ReactPHP Event Loop)             │
│                                                                │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │  Account Manager                                         │ │
│  │  ───────────────                                         │ │
│  │  • Loads config.json with multiple accounts             │ │
│  │  • Creates AsyncCloudClient per account                 │ │
│  │  • Manages authentication lifecycle                     │ │
│  └──────────────────────────────────────────────────────────┘ │
│                                                                │
│  ┌─────────────┐   ┌─────────────┐   ┌─────────────┐        │
│  │AsyncCloud   │   │AsyncCloud   │   │AsyncCloud   │        │
│  │Client #1    │   │Client #2    │   │Client #3    │        │
│  │             │   │             │   │             │        │
│  │Event: msg   │   │Event: msg   │   │Event: msg   │        │
│  │Event: disc  │   │Event: disc  │   │Event: disc  │        │
│  └─────┬───────┘   └─────┬───────┘   └─────┬───────┘        │
│        │                  │                  │                │
│        └──────────────────┴──────────────────┘                │
│                           │                                   │
│  ┌────────────────────────▼────────────────────────────────┐ │
│  │          MqttBridge Event Loop (ReactPHP)              │ │
│  │          ─────────────────────────────                 │ │
│  │  • Handles all client events                           │ │
│  │  • Routes messages (Cloud ↔ Broker)                    │ │
│  │  • Topic translation                                   │ │
│  │  • Payload transformation (Modbus ↔ JSON)              │ │
│  │  • State management                                    │ │
│  └────────────────────────┬───────────────────────────────┘ │
│                           │                                   │
│  ┌────────────────────────▼───────────────────────────────┐  │
│  │  Standard MQTT Client (php-mqtt/client)               │  │
│  │  • Connect: tcp://localhost:1883                       │  │
│  │  • Publish: fossibot/{mac}/state (retained)            │  │
│  │  • Subscribe: fossibot/{mac}/command                   │  │
│  └────────────────────────────────────────────────────────┘  │
│                                                                │
└────────────────────────┬───────────────────────────────────────┘
                         │
                         │ Standard MQTT (localhost:1883)
                         │
┌────────────────────────▼───────────────────────────────────────┐
│                  Mosquitto Broker                              │
│                  localhost:1883                                │
│                  ────────────────                              │
│  • Message Routing                                             │
│  • Client Management                                           │
│  • Retained Messages (current state)                           │
│  • QoS Handling                                                │
└────────────────────────┬───────────────────────────────────────┘
                         │
                         │ Standard MQTT
                         │
        ┌────────────────┼────────────────┐
        │                │                │
┌───────▼──────┐  ┌──────▼──────┐  ┌─────▼────────┐
│  IP-Symcon   │  │ Home        │  │  Node-RED    │
│  MQTT Client │  │ Assistant   │  │  Automation  │
└──────────────┘  └─────────────┘  └──────────────┘
```

---

## 🧩 Component Breakdown

### 1. AsyncCloudClient

**Purpose:** Async MQTT client for Fossibot Cloud connection (one per account)

**Technology Stack:**
- `ratchet/pawl` - WebSocket transport
- `php-mqtt/client` - MQTT protocol layer
- `react/promise` - Promise-based async operations

**Responsibilities:**
- Connect to `mqtt.sydpower.com:8083` via WebSocket
- Perform 3-stage authentication (reuses existing `Connection` class)
- Subscribe to device topics (`{mac}/device/response/+`)
- Emit events: `message`, `connect`, `disconnect`, `error`
- Handle reconnection with exponential backoff

**Key Methods:**
```php
class AsyncCloudClient extends EventEmitter
{
    public function __construct(
        string $email,
        string $password,
        LoopInterface $loop,
        LoggerInterface $logger
    );

    public function connect(): PromiseInterface;
    public function subscribe(string $topic): PromiseInterface;
    public function publish(string $topic, string $payload): PromiseInterface;
    public function disconnect(): PromiseInterface;

    // Events emitted:
    // - 'connect' => function()
    // - 'message' => function(string $topic, string $payload)
    // - 'disconnect' => function()
    // - 'error' => function(\Exception $e)
}
```

**Internal Architecture:**
```
AsyncCloudClient
  ├─ Pawl\Connector (WebSocket handshake)
  │    └─> WebSocket connection established
  │
  ├─ php-mqtt\Client (MQTT over WebSocket)
  │    ├─> CONNECT packet with auth token
  │    ├─> SUBSCRIBE packets for device topics
  │    ├─> PUBLISH packets for commands
  │    └─> Message handling callbacks
  │
  ├─ Connection (reused from existing codebase)
  │    └─> 3-stage auth (s1_generateRequest, s2_parseResponse, etc.)
  │
  └─ EventEmitter (evenement/evenement)
       └─> Emit events to MqttBridge
```

---

### 2. MqttBridge

**Purpose:** Central orchestrator managing all cloud clients and local broker

**Responsibilities:**
- Initialize ReactPHP event loop
- Load `config.json` and create `AsyncCloudClient` per account
- Register event handlers for all cloud clients
- Route messages between cloud clients and broker
- Manage `DeviceStateManager` for all devices
- Handle graceful shutdown (SIGTERM/SIGINT)

**Architecture:**
```php
class MqttBridge
{
    private LoopInterface $loop;
    private array $cloudClients = [];        // email => AsyncCloudClient
    private MqttClient $brokerClient;        // Single broker connection
    private DeviceStateManager $stateManager;
    private TopicTranslator $topicTranslator;
    private PayloadTransformer $payloadTransformer;
    private LoggerInterface $logger;

    public function __construct(
        array $config,              // From config.json
        LoggerInterface $logger
    );

    public function run(): void;    // Starts event loop (blocking)
    public function shutdown(): void; // Graceful shutdown

    // Internal event handlers
    private function handleCloudMessage(
        string $accountEmail,
        string $topic,
        string $payload
    ): void;

    private function handleBrokerCommand(
        string $topic,
        string $payload
    ): void;

    private function handleCloudDisconnect(string $accountEmail): void;
    private function reconnectCloud(string $accountEmail): void;
}
```

**Event Flow:**
```
┌─────────────────────────────────────────────┐
│         ReactPHP Event Loop                 │
│         (while $loop->run())                │
└─────────────────────────────────────────────┘
         │
         ├─> Timer: Periodic tasks (heartbeat, status publish)
         │
         ├─> Cloud Client #1 Events
         │     ├─> on('message') → handleCloudMessage()
         │     ├─> on('disconnect') → handleCloudDisconnect()
         │     └─> on('error') → log error
         │
         ├─> Cloud Client #2 Events
         │     └─> (same as above)
         │
         ├─> Cloud Client #N Events
         │     └─> (same as above)
         │
         └─> Broker Client Events
               ├─> on('message') → handleBrokerCommand()
               └─> on('error') → log error
```

---

### 3. Topic Translator

**Purpose:** Map between Fossibot Cloud topics and standard MQTT topics

**Design:** Stateless utility class (no event loop dependency)

```php
class TopicTranslator
{
    /**
     * Cloud → Broker
     * "7C2C67AB5F0E/device/response/client/04"
     * → "fossibot/7C2C67AB5F0E/state"
     */
    public function cloudToBroker(string $cloudTopic): string;

    /**
     * Broker → Cloud
     * "fossibot/7C2C67AB5F0E/command"
     * → "7C2C67AB5F0E/client/request/data"
     */
    public function brokerToCloud(string $brokerTopic): string;

    public function extractMacFromCloudTopic(string $topic): ?string;
    public function extractMacFromBrokerTopic(string $topic): ?string;
}
```

**Mapping Table:**

| Cloud Topic | Broker Topic | Direction |
|-------------|--------------|-----------|
| `{mac}/device/response/client/04` | `fossibot/{mac}/state` | Cloud → Broker |
| `{mac}/device/response/client/data` | `fossibot/{mac}/state` | Cloud → Broker |
| `fossibot/{mac}/command` | `{mac}/client/request/data` | Broker → Cloud |

---

### 4. Payload Transformer

**Purpose:** Transform between binary Modbus and JSON formats

**Design:** Stateless utility class

```php
class PayloadTransformer
{
    /**
     * Parse binary Modbus response to register array
     */
    public function parseModbusPayload(string $binaryPayload): array;

    /**
     * Convert register array to DeviceState object
     */
    public function registersToState(array $registers): DeviceState;

    /**
     * Convert DeviceState to JSON
     */
    public function stateToJson(DeviceState $state): string;

    /**
     * Parse JSON command to Command object
     */
    public function jsonToCommand(string $json): Command;

    /**
     * Convert Command to Modbus binary
     */
    public function commandToModbus(Command $command): string;
}
```

**Transformation Flow:**

**Cloud → Broker (State Update):**
```
Binary Modbus (162 bytes)
  ↓ parseModbusPayload()
Register Array [56 => 855, 41 => 64, ...]
  ↓ registersToState()
DeviceState {soc: 85.5, usbOutput: true, ...}
  ↓ stateToJson()
JSON String {"soc": 85.5, "usbOutput": true, ...}
```

**Broker → Cloud (Command):**
```
JSON String {"action": "usb_on"}
  ↓ jsonToCommand()
UsbOutputCommand(true)
  ↓ commandToModbus()
Binary Modbus [0x11, 0x06, 0x00, 0x29, ...]
```

---

## 🔄 Multi-Account Architecture

### Account Registration Flow

```php
// In MqttBridge constructor:

foreach ($config['accounts'] as $accountConfig) {
    $email = $accountConfig['email'];
    $password = $accountConfig['password'];

    // Create async client for this account
    $client = new AsyncCloudClient($email, $password, $this->loop, $this->logger);

    // Register event handlers
    $client->on('message', function($topic, $payload) use ($email) {
        $this->handleCloudMessage($email, $topic, $payload);
    });

    $client->on('disconnect', function() use ($email) {
        $this->handleCloudDisconnect($email);
    });

    // Store client
    $this->cloudClients[$email] = $client;

    // Initiate connection (returns Promise)
    $client->connect()->then(function() use ($email, $client) {
        $this->logger->info("Account connected", ['email' => $email]);

        // Discover devices for this account
        return $this->discoverDevices($client);

    })->then(function($devices) use ($email, $client) {
        // Subscribe to device topics
        foreach ($devices as $device) {
            $mac = $device->getMqttId();
            $client->subscribe("$mac/device/response/+");
        }
    });
}
```

### Device Identification

**Challenge:** Multiple accounts may have devices with overlapping MACs (unlikely but possible)

**Solution:** MAC addresses are globally unique (hardware-based), no collision handling needed

**State Management:**
```php
// DeviceStateManager is account-agnostic
// MAC address is sufficient as unique identifier

$this->stateManager->updateDeviceState($mac, $registers);
$state = $this->stateManager->getDeviceState($mac);
```

---

## 🔌 Connection Management

### Initial Connection Sequence

```
1. Bridge starts
   ↓
2. Load config.json
   ↓
3. For each account:
     ├─> Create AsyncCloudClient
     ├─> Authenticate (3-stage auth flow)
     ├─> Connect MQTT WebSocket
     ├─> Discover devices via Connection::getDevices()
     ├─> Subscribe to device topics
     └─> Publish availability: online
   ↓
4. Connect to local Mosquitto
   ↓
5. Subscribe to fossibot/+/command
   ↓
6. Publish bridge status
   ↓
7. Enter event loop (ReactPHP $loop->run())
```

### Reconnection Strategy

**Trigger:** Cloud client emits `disconnect` event

**Process:**
```
1. Detect disconnect
   ↓
2. Log disconnect reason
   ↓
3. Attempt simple MQTT reconnect (existing token)
   ↓
   ├─ Success? → Resume normal operation
   │
   └─ Auth Error? (401/403/CONNACK:5)
        ↓
        4. Full re-authentication
           ├─> Stage 1: Anonymous token
           ├─> Stage 2: Login
           ├─> Stage 3: MQTT token
           └─> Stage 4: Reconnect MQTT
        ↓
        5. Re-subscribe to device topics
        ↓
        6. Resume normal operation

   (If error persists)
   ↓
7. Exponential backoff
   ├─> Wait 5s
   ├─> Wait 15s
   ├─> Wait 30s
   ├─> Wait 60s (max)
   └─> Retry from step 3
```

**Implementation:**
```php
private function reconnectCloud(string $accountEmail): void
{
    $client = $this->cloudClients[$accountEmail];
    $retryDelay = 5; // seconds

    $attemptReconnect = function() use ($client, $accountEmail, &$retryDelay) {
        $client->connect()->then(
            function() use ($accountEmail) {
                $this->logger->info("Reconnected successfully", ['email' => $accountEmail]);
                $retryDelay = 5; // Reset backoff
            },
            function($error) use ($accountEmail, &$retryDelay, &$attemptReconnect) {
                $this->logger->error("Reconnect failed", [
                    'email' => $accountEmail,
                    'error' => $error->getMessage()
                ]);

                // Exponential backoff (max 60s)
                $retryDelay = min($retryDelay * 2, 60);

                $this->loop->addTimer($retryDelay, $attemptReconnect);
            }
        );
    };

    $this->loop->addTimer($retryDelay, $attemptReconnect);
}
```

---

## 🎭 Event-Driven vs. Blocking Code

### Synchronous Components (No Changes Needed)

These classes work synchronously with the event loop:

- `Connection` - Auth logic (called once during connect)
- `Commands/*` - Command classes (instantaneous object creation)
- `Device`, `DeviceState` - Value objects (pure data)
- `DeviceStateManager` - In-memory state (array operations)
- `TopicTranslator`, `PayloadTransformer` - Stateless utilities

**Example:** Synchronous call in async context
```php
// Inside async event handler:
$client->on('message', function($topic, $payload) {
    // This is SYNC code, runs immediately:
    $registers = $this->payloadTransformer->parseModbusPayload($payload);
    $state = $this->payloadTransformer->registersToState($registers);
    $json = $this->payloadTransformer->stateToJson($state);

    // This is ASYNC (returns immediately, publishes in background):
    $this->brokerClient->publish("fossibot/{$mac}/state", $json);
});
```

### Asynchronous Components (New/Modified)

- `AsyncCloudClient` - All network I/O is async
- `MqttBridge` - Event loop coordination
- Broker client (`php-mqtt/client`) - Async by default

---

## 📦 Dependency Injection

**Design:** Constructor injection for all dependencies

```php
// Example: AsyncCloudClient
public function __construct(
    string $email,
    string $password,
    LoopInterface $loop,              // ReactPHP event loop
    LoggerInterface $logger           // Monolog
) {
    $this->email = $email;
    $this->password = $password;
    $this->loop = $loop;
    $this->logger = $logger;
}

// Example: MqttBridge
public function __construct(
    array $config,                    // Parsed config.json
    LoggerInterface $logger
) {
    $this->loop = Loop::get();        // Global ReactPHP loop
    $this->logger = $logger;

    // Create dependencies
    $this->stateManager = new DeviceStateManager();
    $this->topicTranslator = new TopicTranslator();
    $this->payloadTransformer = new PayloadTransformer();

    // Initialize accounts
    foreach ($config['accounts'] as $account) {
        // Create AsyncCloudClient...
    }
}
```

---

## 🧪 Testability

### Unit Testable (No Async)

- `TopicTranslator` - Pure functions
- `PayloadTransformer` - Pure functions
- `DeviceStateManager` - In-memory state
- All `Command` classes - Already tested

### Integration Testable (With ReactPHP)

- `AsyncCloudClient` - Requires running event loop
- `MqttBridge` - Full system integration

**Testing Strategy:** See [09-TESTING.md](09-TESTING.md)

---

## 🚀 Performance Characteristics

### Resource Usage (Estimated)

**Single Account (2 devices):**
- Memory: ~10 MB
- CPU: < 1% (idle), 5-10% (active message processing)
- Network: ~1 KB/s (periodic heartbeats)

**Multi-Account (3 accounts, 6 devices):**
- Memory: ~15 MB
- CPU: < 2% (idle), 10-15% (active)
- Network: ~2 KB/s

**Bottlenecks:**
- Not CPU (event loop is efficient)
- Not memory (state is small)
- Potentially network latency (Fossibot Cloud response time)

---

## 📚 Next Steps

- **Understand Message Formats:** Read [02-TOPICS-MESSAGES.md](02-TOPICS-MESSAGES.md)
- **Start Implementation:** Begin with [03-PHASE-0-SETUP.md](03-PHASE-0-SETUP.md)

---

**Ready for implementation details?** → [03-PHASE-0-SETUP.md](03-PHASE-0-SETUP.md)