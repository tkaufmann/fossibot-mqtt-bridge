# 04 - Phase 1: AsyncCloudClient Implementation

**Phase:** 1 - Async Cloud Client
**Effort:** ~5 hours
**Prerequisites:** Phase 0 complete
**Deliverables:** Working async MQTT client for Fossibot Cloud with event emitter

---

## 🎯 Phase Goals

1. Implement AsyncCloudClient with Pawl WebSocket + php-mqtt/client
2. Integrate existing 3-stage authentication (reuse Connection class)
3. Event emitter for message/connect/disconnect/error
4. Non-blocking subscribe and publish operations
5. Test with real Fossibot Cloud API

---

## 📋 Architecture Recap

```
AsyncCloudClient
  ├─ Pawl\Connector (WebSocket)
  ├─ php-mqtt\Client (MQTT protocol over WebSocket stream)
  ├─ Connection (3-stage auth, reused from existing code)
  └─ EventEmitter (evenement/evenement)
       ├─> 'connect' event
       ├─> 'message' event (topic, payload)
       ├─> 'disconnect' event
       └─> 'error' event (Exception)
```

---

## 📋 Step-by-Step Implementation

### Step 1.1: Basic AsyncCloudClient Structure (60 min)

**File:** `src/Bridge/AsyncCloudClient.php`

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use Fossibot\Connection;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
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
    private ?MqttClient $mqttClient = null;
    private bool $connected = false;

    private array $devices = [];
    private array $subscriptions = [];

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
                // Phase 3: Setup MQTT over WebSocket
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

        if ($this->mqttClient !== null) {
            $this->mqttClient->disconnect();
        }

        if ($this->websocket !== null) {
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
        if (!$this->connected || $this->mqttClient === null) {
            throw new \RuntimeException('Cannot subscribe: not connected');
        }

        $this->mqttClient->subscribe($topic, function($topic, $payload) {
            $this->handleMessage($topic, $payload);
        }, 0);

        $this->subscriptions[] = $topic;

        $this->logger->debug('Subscribed to topic', ['topic' => $topic]);
    }

    /**
     * Publish to MQTT topic.
     */
    public function publish(string $topic, string $payload): void
    {
        if (!$this->connected || $this->mqttClient === null) {
            throw new \RuntimeException('Cannot publish: not connected');
        }

        $this->mqttClient->publish($topic, $payload, 1);

        $this->logger->debug('Published to topic', [
            'topic' => $topic,
            'payload_length' => strlen($payload)
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
        // Create MQTT client over WebSocket stream
        $mqttToken = $this->connection->getMqttToken();

        $this->mqttClient = new MqttClient(
            'mqtt.sydpower.com',
            8083,
            'fossibot_async_' . uniqid(),
            MqttClient::MQTT_3_1_1
        );

        $connectionSettings = (new ConnectionSettings)
            ->setUsername($mqttToken['username'] ?? '')
            ->setPassword($mqttToken['password'] ?? '')
            ->setUseTls(true)
            ->setConnectTimeout(10);

        try {
            // Note: php-mqtt/client v2+ has async support
            // For now, connect is blocking but quick
            $this->mqttClient->connect($connectionSettings);

            // Setup message loop (non-blocking)
            $this->startMessageLoop();

            return \React\Promise\resolve();
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }
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

    private function startMessageLoop(): void
    {
        // Poll for messages every 100ms (non-blocking)
        $this->loop->addPeriodicTimer(0.1, function() {
            if ($this->mqttClient === null || !$this->connected) {
                return;
            }

            try {
                // Process pending messages (non-blocking)
                $this->mqttClient->loop(true, true);
            } catch (\Exception $e) {
                $this->logger->error('MQTT loop error', [
                    'error' => $e->getMessage()
                ]);
                $this->emit('error', [$e]);
            }
        });
    }

    private function handleMessage(string $topic, string $payload): void
    {
        $this->logger->debug('Message received', [
            'topic' => $topic,
            'payload_length' => strlen($payload)
        ]);

        $this->emit('message', [$topic, $payload]);
    }
}
```

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php
git commit -m "feat(bridge): Implement AsyncCloudClient base structure"
```

**Deliverable:** ✅ AsyncCloudClient skeleton complete

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
$client->on('connect', function() {
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

### Step 1.4: Refine MQTT Client Integration (45 min)

**Issue:** `php-mqtt/client` may not fully support async streams yet

**Solution:** Use adapter pattern to wrap synchronous MQTT calls

**Update:** `src/Bridge/AsyncCloudClient.php`

Add method to handle async MQTT:

```php
private function setupMqtt(): PromiseInterface
{
    $mqttToken = $this->connection->getMqttToken();

    // Create MQTT client (note: will use WebSocket stream)
    $this->mqttClient = new MqttClient(
        'mqtt.sydpower.com',
        8083,
        'fossibot_async_' . uniqid(),
        MqttClient::MQTT_3_1_1,
        $this->websocket->getStream() // Pass WebSocket stream
    );

    $connectionSettings = (new ConnectionSettings)
        ->setUsername($mqttToken['username'] ?? '')
        ->setPassword($mqttToken['password'] ?? '')
        ->setKeepAliveInterval(60)
        ->setUseTls(false); // TLS already handled by WebSocket

    try {
        $this->mqttClient->connect($connectionSettings, true);
        $this->startMessageLoop();
        return \React\Promise\resolve();
    } catch (\Exception $e) {
        return \React\Promise\reject($e);
    }
}
```

**Note:** If `php-mqtt/client` doesn't support custom streams, we may need to implement basic MQTT packet handling manually or use alternative library. Test and adapt as needed.

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php
git commit -m "refactor(bridge): Refine MQTT client WebSocket integration"
```

**Deliverable:** ✅ MQTT integration refined

---

### Step 1.5: Add Reconnect Handler Stub (30 min)

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

- [ ] AsyncCloudClient class implemented
- [ ] EventEmitter integration working (connect, message, disconnect, error)
- [ ] 3-stage auth integration (reuses Connection class)
- [ ] WebSocket connection via Pawl
- [ ] MQTT protocol via php-mqtt/client
- [ ] Subscribe functionality tested
- [ ] Publish functionality tested
- [ ] Reconnect capability implemented
- [ ] All test scripts pass against real Fossibot Cloud
- [ ] Code committed with clear messages

---

## 🎯 Success Criteria

**Phase 1 is complete when:**

1. `test_async_cloud_client.php` connects and receives messages
2. `test_async_publish.php` successfully sends commands (device responds)
3. Event emitter fires all events correctly
4. No memory leaks during 30+ second runs
5. Code is clean and well-documented

---

## 🐛 Troubleshooting

**Problem:** WebSocket connection fails

**Solution:** Verify Fossibot Cloud is reachable
```bash
curl -I https://mqtt.sydpower.com
```

---

**Problem:** MQTT authentication fails

**Solution:** Check token expiry in Connection class. Re-authenticate if needed.

---

**Problem:** Messages not received

**Solution:** Verify subscriptions are active. Check MQTT client loop is running.

---

## 📚 Next Steps

**Phase 1 complete!** → [05-PHASE-2-BRIDGE.md](05-PHASE-2-BRIDGE.md)

Begin implementing MqttBridge with multi-account support.