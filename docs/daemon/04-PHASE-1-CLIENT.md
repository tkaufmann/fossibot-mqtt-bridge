# 04 - Phase 1: AsyncCloudClient Implementation

**Phase:** 1 - Async Cloud Client
**Effort:** ~4 hours
**Prerequisites:** Phase 0 complete
**Deliverables:** Working async MQTT client for Fossibot Cloud with event-based MQTT

---

## 🎯 Phase Goals

1. Implement AsyncCloudClient with Pawl WebSocket + custom MQTT packet handling
2. Integrate existing 3-stage authentication (reuse Connection class)
3. Event emitter for message/connect/disconnect/error
4. Event-based (not polling) MQTT protocol implementation
5. Non-blocking subscribe and publish operations
6. Test with real Fossibot Cloud API

---

## 📋 Architecture Recap

```
AsyncCloudClient
  ├─ Pawl\Connector (WebSocket)
  ├─ Custom MQTT Packet Handlers (event-based, no polling)
  │   ├─ handleMqttData() - Stream event handler
  │   ├─ processMqttPacket() - Packet parser
  │   ├─ buildConnectPacket() - CONNECT builder
  │   └─ subscribe() / publish() - Protocol methods
  ├─ Connection (3-stage auth, reused from existing code)
  └─ EventEmitter (evenement/evenement)
       ├─> 'connect' event
       ├─> 'message' event (topic, payload)
       ├─> 'disconnect' event
       └─> 'error' event (Exception)
```

---

## 📋 Step-by-Step Implementation

### Step 1.1: Basic AsyncCloudClient Structure (90 min)

**File:** `src/Bridge/AsyncCloudClient.php`

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use Fossibot\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ratchet\Client\Connector as WebSocketConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Async MQTT client for Fossibot Cloud connection.
 *
 * Connects to Fossibot Cloud via MQTT over WebSocket using ReactPHP.
 * Emits events for messages, connection status, and errors.
 * One instance per Fossibot account.
 *
 * This client implements event-based MQTT communication without polling,
 * integrating the WebSocket stream directly with MQTT packet handlers.
 *
 * Events:
 * - 'connect' => function()
 * - 'message' => function(string $topic, string $payload)
 * - 'disconnect' => function()
 * - 'error' => function(\Exception $e)
 */
class AsyncCloudClient extends EventEmitter
{
    private string $email;
    private string $password;
    private LoopInterface $loop;
    private LoggerInterface $logger;

    private ?Connection $connection = null;
    private ?WebSocket $websocket = null;
    private bool $connected = false;

    private array $devices = [];
    private array $subscriptions = [];

    // MQTT protocol state
    private string $mqttBuffer = '';
    private int $mqttPacketId = 1;
    private array $pendingSubscriptions = [];
    private array $activeSubscriptions = [];

    public function __construct(
        string $email,
        string $password,
        LoopInterface $loop,
        ?LoggerInterface $logger = null
    ) {
        $this->email = $email;
        $this->password = $password;
        $this->loop = $loop;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Connect to Fossibot Cloud (async).
     *
     * @return PromiseInterface Resolves when connected
     */
    public function connect(): PromiseInterface
    {
        $this->logger->info('AsyncCloudClient connecting', [
            'email' => $this->email
        ]);

        // Phase 1: Authenticate (synchronous, uses existing Connection)
        return $this->authenticate()
            ->then(function() {
                // Phase 2: Connect WebSocket
                return $this->connectWebSocket();
            })
            ->then(function() {
                // Phase 3: Setup MQTT over WebSocket (event-based)
                return $this->setupMqtt();
            })
            ->then(function() {
                // Phase 4: Discover and subscribe to devices
                return $this->discoverDevices();
            })
            ->then(function() {
                $this->connected = true;
                $this->emit('connect');
                $this->logger->info('AsyncCloudClient connected successfully');
            })
            ->otherwise(function(\Exception $e) {
                $this->logger->error('AsyncCloudClient connect failed', [
                    'error' => $e->getMessage()
                ]);
                $this->emit('error', [$e]);
                throw $e;
            });
    }

    /**
     * Disconnect from cloud (async).
     */
    public function disconnect(): PromiseInterface
    {
        $this->logger->info('AsyncCloudClient disconnecting');

        $this->connected = false;

        // Send MQTT DISCONNECT packet
        if ($this->websocket !== null) {
            $disconnectPacket = "\xe0\x00"; // DISCONNECT packet
            $this->websocket->send($disconnectPacket);
            $this->websocket->close();
        }

        $this->emit('disconnect');

        return \React\Promise\resolve();
    }

    /**
     * Check if client is connected.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get discovered devices.
     *
     * @return \Fossibot\Device\Device[]
     */
    public function getDevices(): array
    {
        return $this->devices;
    }

    /**
     * Subscribe to MQTT topic.
     */
    public function subscribe(string $topic): void
    {
        if (!$this->connected || $this->websocket === null) {
            throw new \RuntimeException('Cannot subscribe: not connected');
        }

        $packetId = $this->getNextPacketId();

        // Build MQTT SUBSCRIBE packet
        $payload = pack('n', $packetId); // Packet identifier
        $payload .= pack('n', strlen($topic)) . $topic; // Topic filter
        $payload .= chr(0); // QoS 0

        $packet = chr(0x82) . $this->encodeLength(strlen($payload)) . $payload;

        $this->pendingSubscriptions[$packetId] = $topic;
        $this->websocket->send($packet);

        $this->logger->debug('Subscribed to topic', ['topic' => $topic, 'packet_id' => $packetId]);
    }

    /**
     * Publish to MQTT topic.
     */
    public function publish(string $topic, string $payload, int $qos = 1): void
    {
        if (!$this->connected || $this->websocket === null) {
            throw new \RuntimeException('Cannot publish: not connected');
        }

        $packetId = $this->getNextPacketId();

        // Build MQTT PUBLISH packet (QoS 1)
        $flags = 0x30 | ($qos << 1); // PUBLISH with QoS
        $variableHeader = pack('n', strlen($topic)) . $topic;

        if ($qos > 0) {
            $variableHeader .= pack('n', $packetId); // Add packet ID for QoS > 0
        }

        $packet = chr($flags) . $this->encodeLength(strlen($variableHeader) + strlen($payload)) . $variableHeader . $payload;

        $this->websocket->send($packet);

        $this->logger->debug('Published to topic', [
            'topic' => $topic,
            'payload_length' => strlen($payload),
            'packet_id' => $packetId,
            'qos' => $qos
        ]);
    }

    // --- Private Methods ---

    private function authenticate(): PromiseInterface
    {
        // Reuse existing Connection class for 3-stage auth
        $this->connection = new Connection(
            $this->email,
            $this->password,
            $this->logger
        );

        try {
            // This is synchronous but fast (~1-2 seconds)
            $this->connection->connect();
            return \React\Promise\resolve();
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }
    }

    private function connectWebSocket(): PromiseInterface
    {
        $wsConnector = new WebSocketConnector($this->loop);

        // Get MQTT broker URL from Connection
        $mqttUrl = 'wss://mqtt.sydpower.com:8083/mqtt';

        $this->logger->debug('Connecting WebSocket', ['url' => $mqttUrl]);

        return $wsConnector($mqttUrl)->then(
            function(WebSocket $conn) {
                $this->websocket = $conn;
                $this->logger->debug('WebSocket connected');
                return $conn;
            }
        );
    }

    private function setupMqtt(): PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();

        // Get MQTT credentials
        $mqttToken = $this->connection->getMqttToken();
        $username = $mqttToken['username'] ?? '';
        $password = $mqttToken['password'] ?? '';
        $clientId = 'fossibot_async_' . uniqid();

        // Register event handlers on WebSocket stream
        $this->websocket->on('message', function($message) {
            $this->handleMqttData($message->getPayload());
        });

        $this->websocket->on('close', function() {
            $this->logger->warning('WebSocket closed');
            $this->connected = false;
            $this->emit('disconnect');
        });

        $this->websocket->on('error', function(\Exception $e) {
            $this->logger->error('WebSocket error', ['error' => $e->getMessage()]);
            $this->emit('error', [$e]);
        });

        // Build and send MQTT CONNECT packet
        $connectPacket = $this->buildConnectPacket($clientId, $username, $password);
        $this->websocket->send($connectPacket);

        $this->logger->debug('Sent MQTT CONNECT packet');

        // Wait for CONNACK (handled in handleMqttData)
        // We'll resolve the promise when CONNACK is received
        $this->once('mqtt_connack', function($returnCode) use ($deferred) {
            if ($returnCode === 0) {
                $this->logger->info('MQTT connection accepted');
                $deferred->resolve();
            } else {
                $error = new \RuntimeException("MQTT connection refused: code $returnCode");
                $this->logger->error('MQTT connection refused', ['code' => $returnCode]);
                $deferred->reject($error);
            }
        });

        return $deferred->promise();
    }

    private function discoverDevices(): PromiseInterface
    {
        try {
            $this->devices = $this->connection->getDevices();

            $this->logger->info('Devices discovered', [
                'count' => count($this->devices)
            ]);

            // Subscribe to device topics
            foreach ($this->devices as $device) {
                $mac = $device->getMqttId();
                $this->subscribe("$mac/device/response/+");
            }

            return \React\Promise\resolve();
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }
    }

    /**
     * Handle incoming MQTT data from WebSocket (event-driven).
     */
    private function handleMqttData(string $data): void
    {
        $this->mqttBuffer .= $data;

        while (strlen($this->mqttBuffer) > 0) {
            // Parse MQTT packet type
            $byte = ord($this->mqttBuffer[0]);
            $packetType = ($byte >> 4) & 0x0F;

            // Decode remaining length
            $lengthData = $this->decodeLength(substr($this->mqttBuffer, 1));
            if ($lengthData === null) {
                // Not enough data yet, wait for more
                return;
            }

            [$remainingLength, $lengthBytes] = $lengthData;
            $totalPacketLength = 1 + $lengthBytes + $remainingLength;

            if (strlen($this->mqttBuffer) < $totalPacketLength) {
                // Packet incomplete, wait for more data
                return;
            }

            // Extract complete packet
            $packet = substr($this->mqttBuffer, 0, $totalPacketLength);
            $this->mqttBuffer = substr($this->mqttBuffer, $totalPacketLength);

            // Process packet
            $this->processMqttPacket($packetType, $packet);
        }
    }

    private function processMqttPacket(int $packetType, string $packet): void
    {
        switch ($packetType) {
            case 2: // CONNACK
                $returnCode = ord($packet[3]);
                $this->emit('mqtt_connack', [$returnCode]);
                break;

            case 3: // PUBLISH
                $this->handlePublishPacket($packet);
                break;

            case 4: // PUBACK
                $packetId = unpack('n', substr($packet, 2, 2))[1];
                $this->logger->debug('Received PUBACK', ['packet_id' => $packetId]);
                break;

            case 9: // SUBACK
                $packetId = unpack('n', substr($packet, 2, 2))[1];
                if (isset($this->pendingSubscriptions[$packetId])) {
                    $topic = $this->pendingSubscriptions[$packetId];
                    $this->activeSubscriptions[] = $topic;
                    unset($this->pendingSubscriptions[$packetId]);
                    $this->logger->debug('Subscription confirmed', ['topic' => $topic]);
                }
                break;

            case 13: // PINGRESP
                $this->logger->debug('Received PINGRESP');
                break;

            default:
                $this->logger->warning('Unknown MQTT packet type', ['type' => $packetType]);
        }
    }

    private function handlePublishPacket(string $packet): void
    {
        $pos = 1;

        // Skip remaining length
        while (ord($packet[$pos]) & 0x80) {
            $pos++;
        }
        $pos++;

        // Extract topic
        $topicLength = unpack('n', substr($packet, $pos, 2))[1];
        $pos += 2;
        $topic = substr($packet, $pos, $topicLength);
        $pos += $topicLength;

        // Extract QoS from flags
        $flags = ord($packet[0]);
        $qos = ($flags >> 1) & 0x03;

        // Skip packet ID if QoS > 0
        if ($qos > 0) {
            $pos += 2;
        }

        // Extract payload
        $payload = substr($packet, $pos);

        $this->logger->debug('Message received', [
            'topic' => $topic,
            'payload_length' => strlen($payload)
        ]);

        $this->emit('message', [$topic, $payload]);
    }

    private function buildConnectPacket(string $clientId, string $username, string $password): string
    {
        // MQTT 3.1.1 CONNECT packet
        $protocolName = 'MQTT';
        $protocolLevel = 4; // MQTT 3.1.1
        $connectFlags = 0xC2; // Clean session + username + password
        $keepAlive = 60;

        $variableHeader = pack('n', strlen($protocolName)) . $protocolName;
        $variableHeader .= chr($protocolLevel);
        $variableHeader .= chr($connectFlags);
        $variableHeader .= pack('n', $keepAlive);

        $payload = pack('n', strlen($clientId)) . $clientId;
        $payload .= pack('n', strlen($username)) . $username;
        $payload .= pack('n', strlen($password)) . $password;

        $remainingLength = strlen($variableHeader) + strlen($payload);

        return chr(0x10) . $this->encodeLength($remainingLength) . $variableHeader . $payload;
    }

    private function encodeLength(int $length): string
    {
        $encoded = '';
        do {
            $byte = $length % 128;
            $length = (int)($length / 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $encoded .= chr($byte);
        } while ($length > 0);

        return $encoded;
    }

    private function decodeLength(string $data): ?array
    {
        $multiplier = 1;
        $value = 0;
        $index = 0;

        do {
            if (!isset($data[$index])) {
                return null; // Not enough data
            }

            $byte = ord($data[$index]);
            $value += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
            $index++;

            if ($multiplier > 128 * 128 * 128) {
                throw new \RuntimeException('Malformed remaining length');
            }
        } while (($byte & 0x80) !== 0);

        return [$value, $index];
    }

    private function getNextPacketId(): int
    {
        $id = $this->mqttPacketId++;
        if ($this->mqttPacketId > 65535) {
            $this->mqttPacketId = 1;
        }
        return $id;
    }
}
```

**Important Notes:**

1. **No External Dependencies**: This implementation uses NO external MQTT library. All MQTT packet handling is custom-built for ReactPHP integration.

2. **Event-Based Design**: The client reacts to WebSocket `message` events - no polling, no periodic timers.

3. **MQTT Keep-Alive**: The CONNECT packet sets `keepAlive = 60` seconds. To properly maintain the connection, add PINGREQ handling in Phase 2 (MqttBridge will handle this).

4. **Buffer Management**: `mqttBuffer` handles fragmented WebSocket frames - MQTT packets can arrive split across multiple WebSocket messages.

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php
git commit -m "feat(bridge): Implement event-based AsyncCloudClient with custom MQTT"
```

**Deliverable:** ✅ AsyncCloudClient with event-based MQTT complete

---

### Step 1.2: Test AsyncCloudClient Connection (45 min)

**Test script:** `test_async_cloud_client.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$email = getenv('FOSSIBOT_EMAIL');
$password = getenv('FOSSIBOT_PASSWORD');

if (empty($email) || empty($password)) {
    echo "❌ Error: Set FOSSIBOT_EMAIL and FOSSIBOT_PASSWORD\n";
    exit(1);
}

echo "Testing AsyncCloudClient...\n";
echo "Email: $email\n\n";

$loop = Loop::get();

$client = new AsyncCloudClient($email, $password, $loop, $logger);

// Register event handlers
$client->on('connect', function() use ($client) {
    echo "\n✅ EVENT: Connected!\n";
    echo "Devices discovered: " . count($client->getDevices()) . "\n";

    foreach ($client->getDevices() as $device) {
        echo "  - {$device->getDeviceName()} ({$device->getMqttId()})\n";
    }
});

$client->on('message', function($topic, $payload) {
    echo "\n✅ EVENT: Message received\n";
    echo "  Topic: $topic\n";
    echo "  Payload: " . strlen($payload) . " bytes\n";
    echo "  Hex: " . substr(bin2hex($payload), 0, 40) . "...\n";
});

$client->on('disconnect', function() {
    echo "\n⚠️  EVENT: Disconnected\n";
});

$client->on('error', function($error) {
    echo "\n❌ EVENT: Error - " . $error->getMessage() . "\n";
});

// Connect (returns promise)
$client->connect()->then(
    function() use ($loop) {
        echo "\n✅ Connection promise resolved\n";
        echo "Waiting for messages (30 seconds)...\n\n";

        // Stop after 30 seconds
        $loop->addTimer(30, function() use ($loop) {
            echo "\n⏱️  Test timeout reached\n";
            $loop->stop();
        });
    },
    function($error) use ($loop) {
        echo "\n❌ Connection promise rejected: " . $error->getMessage() . "\n";
        $loop->stop();
    }
);

echo "Starting event loop...\n";
$loop->run();

echo "\n✅ Test completed!\n";
```

**Run:**
```bash
php test_async_cloud_client.php
```

**Expected Output:**
```
Testing AsyncCloudClient...
Email: user@example.com

Starting event loop...
[DEBUG] AsyncCloudClient connecting...
[DEBUG] Authenticating...
[DEBUG] WebSocket connected
[DEBUG] MQTT setup complete
[INFO] Devices discovered: 2

✅ EVENT: Connected!
Devices discovered: 2
  - F2400 Living Room (7C2C67AB5F0E)
  - F3000 Garage (8D3D78BC6F1F)

✅ Connection promise resolved
Waiting for messages (30 seconds)...

✅ EVENT: Message received
  Topic: 7C2C67AB5F0E/device/response/client/04
  Payload: 162 bytes
  Hex: 11030000a20000000000000000000000000000...

✅ EVENT: Message received
  Topic: 7C2C67AB5F0E/device/response/client/04
  Payload: 162 bytes
  Hex: 11030000a20000000000000000000000000000...

⏱️  Test timeout reached

✅ Test completed!
```

**Commit:**
```bash
git add test_async_cloud_client.php
git commit -m "test: Add AsyncCloudClient connection test"
```

**Deliverable:** ✅ AsyncCloudClient connects and receives messages

---

### Step 1.3: Test Publishing Commands (30 min)

**Test script:** `test_async_publish.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use Fossibot\Commands\UsbOutputCommand;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$email = getenv('FOSSIBOT_EMAIL');
$password = getenv('FOSSIBOT_PASSWORD');

echo "Testing AsyncCloudClient publish...\n\n";

$loop = Loop::get();
$client = new AsyncCloudClient($email, $password, $loop, $logger);

$messageCount = 0;

$client->on('connect', function() use ($client, $loop, &$messageCount) {
    echo "✅ Connected\n";

    $devices = $client->getDevices();
    if (empty($devices)) {
        echo "❌ No devices found\n";
        $loop->stop();
        return;
    }

    $device = $devices[0];
    $mac = $device->getMqttId();

    echo "Testing device: {$device->getDeviceName()} ($mac)\n\n";

    // Send USB On command
    $command = new UsbOutputCommand(true);
    $modbusBytes = $command->getModbusBytes();
    $payload = '';
    foreach ($modbusBytes as $byte) {
        $payload .= chr($byte);
    }

    echo "Sending USB On command...\n";
    $client->publish("$mac/client/request/data", $payload);

    // Wait for state update
    $loop->addTimer(5, function() use ($loop) {
        echo "\n⏱️  Waiting for response...\n";
    });

    // Stop after 15 seconds
    $loop->addTimer(15, function() use ($loop) {
        echo "\n✅ Test completed\n";
        $loop->stop();
    });
});

$client->on('message', function($topic, $payload) use (&$messageCount) {
    $messageCount++;
    echo "\n📨 Message #$messageCount received\n";
    echo "  Topic: $topic\n";

    // Try to parse state (simplified)
    if (strlen($payload) >= 10) {
        $hex = bin2hex($payload);
        echo "  Payload (first 40 chars): $hex...\n";
    }
});

$client->connect()->then(
    function() {
        echo "Connection established\n";
    },
    function($error) use ($loop) {
        echo "❌ Connection failed: " . $error->getMessage() . "\n";
        $loop->stop();
    }
);

$loop->run();

echo "\nMessages received: $messageCount\n";
echo "✅ Publish test completed!\n";
```

**Run:**
```bash
php test_async_publish.php
```

**Expected:**
```
Testing AsyncCloudClient publish...

✅ Connected
Testing device: F2400 Living Room (7C2C67AB5F0E)

Sending USB On command...

📨 Message #1 received
  Topic: 7C2C67AB5F0E/device/response/client/04
  Payload (first 40 chars): 11030000a2...

⏱️  Waiting for response...

✅ Test completed

Messages received: 2
✅ Publish test completed!
```

**Verify:** Check device physically - USB ports should turn on!

**Commit:**
```bash
git add test_async_publish.php
git commit -m "test: Add AsyncCloudClient publish command test"
```

**Deliverable:** ✅ AsyncCloudClient can publish commands

---

### Step 1.4: Add Reconnect Handler (30 min)

**Update:** `src/Bridge/AsyncCloudClient.php`

Add reconnect capability:

```php
/**
 * Attempt reconnection (called by MqttBridge).
 *
 * @return PromiseInterface
 */
public function reconnect(): PromiseInterface
{
    $this->logger->info('Attempting reconnect');

    // Disconnect cleanly first
    if ($this->connected) {
        $this->disconnect();
    }

    // Wait 1 second before reconnecting
    $deferred = new \React\Promise\Deferred();

    $this->loop->addTimer(1.0, function() use ($deferred) {
        $this->connect()->then(
            function() use ($deferred) {
                $deferred->resolve();
            },
            function($error) use ($deferred) {
                $deferred->reject($error);
            }
        );
    });

    return $deferred->promise();
}
```

**Test script:** `test_async_reconnect.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$email = getenv('FOSSIBOT_EMAIL');
$password = getenv('FOSSIBOT_PASSWORD');

echo "Testing AsyncCloudClient reconnect...\n\n";

$loop = Loop::get();
$client = new AsyncCloudClient($email, $password, $loop, $logger);

$client->on('connect', function() use ($client, $loop) {
    echo "✅ Connected\n";

    // Force disconnect after 5 seconds
    $loop->addTimer(5, function() use ($client, $loop) {
        echo "\n⚠️  Forcing disconnect...\n";
        $client->disconnect();

        // Try to reconnect after 2 seconds
        $loop->addTimer(2, function() use ($client) {
            echo "\n🔄 Attempting reconnect...\n";
            $client->reconnect()->then(
                function() {
                    echo "✅ Reconnect successful!\n";
                },
                function($error) {
                    echo "❌ Reconnect failed: " . $error->getMessage() . "\n";
                }
            );
        });
    });

    // Stop test after 20 seconds
    $loop->addTimer(20, function() use ($loop) {
        echo "\n⏱️  Test timeout\n";
        $loop->stop();
    });
});

$client->on('disconnect', function() {
    echo "⚠️  Disconnected\n";
});

$client->connect();
$loop->run();

echo "\n✅ Reconnect test completed!\n";
```

**Run:**
```bash
php test_async_reconnect.php
```

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php test_async_reconnect.php
git commit -m "feat(bridge): Add reconnect capability to AsyncCloudClient"
```

**Deliverable:** ✅ Reconnect functionality implemented

---

## ✅ Phase 1 Completion Checklist

- [ ] AsyncCloudClient class implemented (Step 1.1)
- [ ] Event-based MQTT packet handling (no polling)
- [ ] Custom MQTT protocol implementation (CONNECT, PUBLISH, SUBSCRIBE, CONNACK, SUBACK, PUBACK)
- [ ] EventEmitter integration working (connect, message, disconnect, error)
- [ ] 3-stage auth integration (reuses Connection class)
- [ ] WebSocket connection via Pawl
- [ ] Subscribe functionality tested (Step 1.2)
- [ ] Publish functionality tested (Step 1.3)
- [ ] Reconnect capability implemented (Step 1.4)
- [ ] All test scripts pass against real Fossibot Cloud
- [ ] Code committed with clear messages

---

## 🎯 Success Criteria

**Phase 1 is complete when:**

1. `test_async_cloud_client.php` connects and receives messages
2. `test_async_publish.php` successfully sends commands (device responds)
3. Event emitter fires all events correctly
4. No polling - pure event-based MQTT communication
5. No memory leaks during 30+ second runs
6. Code is clean and well-documented

---

## 🐛 Troubleshooting

**Problem:** WebSocket connection fails

**Solution:** Verify Fossibot Cloud is reachable
```bash
curl -I https://mqtt.sydpower.com
```

---

**Problem:** MQTT authentication fails (CONNACK return code != 0)

**Solution:** Check token expiry in Connection class. Re-authenticate if needed.

---

**Problem:** Messages not received

**Solution:**
1. Check WebSocket event handlers are registered (`$websocket->on('message', ...)`)
2. Verify subscriptions received SUBACK confirmation (check logs)
3. Enable DEBUG logging to see MQTT packet types

---

**Problem:** High CPU usage

**Solution:** This should NOT happen with event-based design. If it does:
1. Check for accidental polling loops
2. Verify `handleMqttData()` is only called on WebSocket events
3. No `addPeriodicTimer()` should exist in AsyncCloudClient

---

## 📚 Next Steps

**Phase 1 complete!** → [05-PHASE-2-BRIDGE.md](05-PHASE-2-BRIDGE.md)

Begin implementing MqttBridge with multi-account support.