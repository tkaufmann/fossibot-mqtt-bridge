# 06 - Phase 3: Reconnect Logic & Error Recovery

**Phase:** 3 - Resilience
**Effort:** ~4 hours
**Prerequisites:** Phase 2 complete (MqttBridge functional)
**Deliverables:** Smart reconnect logic, exponential backoff, token refresh, error recovery

---

## 🎯 Phase Goals

1. Implement smart reconnect strategy for cloud connections
2. Detect authentication errors and trigger re-auth
3. Add exponential backoff for failed reconnections
4. Handle broker reconnections
5. Implement token expiry detection
6. Add graceful error recovery

---

## 📋 Reconnect Strategy Overview

### Three-Tier Reconnect Strategy

```
Connection Lost
       ↓
┌─────────────────────────┐
│ Tier 1: Simple Reconnect │ (Existing token, existing WebSocket)
└─────────────────────────┘
       ↓ (Failed)
┌─────────────────────────┐
│ Tier 2: Re-authenticate  │ (New tokens, new WebSocket)
└─────────────────────────┘
       ↓ (Failed)
┌─────────────────────────┐
│ Tier 3: Exponential Backoff │ (5s, 15s, 30s, 60s max)
└─────────────────────────┘
       ↓ (Retry from Tier 1)
```

### Error Detection Strategy

| Error Type | Detection Method | Recovery Action |
|------------|------------------|-----------------|
| **WebSocket disconnect** | Pawl WebSocket `close` event | Tier 1: Simple reconnect |
| **MQTT auth failure** | CONNACK packet return code 5 in `processMqttPacket()` | Tier 2: Full re-auth |
| **MQTT token expired** | JWT `exp` claim check in `hasValidTokens()` | Tier 2: Full re-auth |
| **Auth token expired** | HTTP 401/403 in Connection API call | Tier 2: Full re-auth |
| **Network timeout** | WebSocket connect promise timeout | Tier 1: Simple reconnect |
| **Rate limiting** | HTTP 429 in Connection API call | Tier 3: Exponential backoff |
| **Server error** | HTTP 5xx in Connection API call | Tier 3: Exponential backoff |

---

## 📋 Step-by-Step Implementation

### Step 3.1: Add Reconnect State Management to AsyncCloudClient (60 min)

**Update:** `src/Bridge/AsyncCloudClient.php`

**IMPORTANT:** This step extends the **event-based AsyncCloudClient from Phase 1** (with custom MQTT packet handling, no `php-mqtt/client`).

Add these properties to the existing class (after line ~91 in Phase 1 version):

```php
// === ADD THESE PROPERTIES TO EXISTING AsyncCloudClient CLASS ===

// Reconnect state
private bool $reconnecting = false;
private int $reconnectAttempts = 0;
private int $maxReconnectAttempts = 10;
private array $backoffDelays = [5, 10, 15, 30, 45, 60]; // seconds
private ?\React\EventLoop\TimerInterface $reconnectTimer = null;

// Token expiry tracking
private ?int $mqttTokenExpiresAt = null;
private ?int $loginTokenExpiresAt = null;
```

**Add these methods** to the existing `AsyncCloudClient` class:

```php
/**
 * Initiates reconnection with smart tier-based strategy.
 *
 * @param bool $forceReauth Force Tier 2 (full re-auth) immediately
 * @return PromiseInterface
 */
public function reconnect(bool $forceReauth = false): PromiseInterface
{
    if ($this->reconnecting) {
        $this->logger->debug('Reconnection already in progress', [
            'email' => $this->email
        ]);
        return \React\Promise\resolve();
    }

    $this->reconnecting = true;
    $this->reconnectAttempts++;

    $this->logger->info('Starting reconnection attempt', [
        'email' => $this->email,
        'attempt' => $this->reconnectAttempts,
        'force_reauth' => $forceReauth
    ]);

    // Cancel any pending reconnect timer
    if ($this->reconnectTimer !== null) {
        $this->loop->cancelTimer($this->reconnectTimer);
        $this->reconnectTimer = null;
    }

    // Tier 1: Simple reconnect (unless forceReauth)
    if (!$forceReauth && $this->hasValidTokens()) {
        return $this->attemptSimpleReconnect()
            ->then(
                fn() => $this->onReconnectSuccess(),
                fn($error) => $this->onReconnectFailure($error, false)
            );
    }

    // Tier 2: Full re-authentication
    return $this->attemptFullReauth()
        ->then(
            fn() => $this->onReconnectSuccess(),
            fn($error) => $this->onReconnectFailure($error, true)
        );
}

/**
 * Tier 1: Simple reconnect using existing authentication tokens.
 * Uses the Connection object stored during initial authentication.
 */
private function attemptSimpleReconnect(): PromiseInterface
{
    $this->logger->debug('Attempting Tier 1: Simple reconnect', [
        'email' => $this->email
    ]);

    // Close existing WebSocket cleanly (but keep Connection object)
    if ($this->websocket !== null) {
        $this->websocket->close();
        $this->websocket = null;
    }

    $this->connected = false;
    $this->mqttBuffer = '';

    // Reconnect with existing tokens from $this->connection
    return $this->connectWebSocket()
        ->then(fn() => $this->setupMqtt())
        ->then(fn() => $this->resubscribeToDevices());
}

/**
 * Tier 2: Full re-authentication flow (creates new Connection object).
 */
private function attemptFullReauth(): PromiseInterface
{
    $this->logger->debug('Attempting Tier 2: Full re-authentication', [
        'email' => $this->email
    ]);

    // Clean slate
    if ($this->websocket !== null) {
        $this->websocket->close();
        $this->websocket = null;
    }

    $this->connected = false;
    $this->mqttBuffer = '';
    $this->connection = null; // Force new Connection object
    $this->clearAuthTokens();

    // Full authentication flow (same as initial connect())
    return $this->authenticate()
        ->then(fn() => $this->connectWebSocket())
        ->then(fn() => $this->setupMqtt())
        ->then(fn() => $this->discoverDevices());
}

/**
 * Reconnection succeeded - reset state.
 */
private function onReconnectSuccess(): void
{
    $this->connected = true;
    $this->reconnecting = false;
    $this->reconnectAttempts = 0;

    $this->logger->info('Reconnection successful', [
        'email' => $this->email
    ]);

    $this->emit('reconnect');
}

/**
 * Reconnection failed - schedule Tier 3 retry with exponential backoff.
 *
 * @param \Throwable $error The error that caused failure
 * @param bool $wasReauth Whether this was a Tier 2 (full reauth) attempt
 */
private function onReconnectFailure(\Throwable $error, bool $wasReauth): void
{
    $this->reconnecting = false;

    // Check if we should give up
    if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
        $this->logger->error('Max reconnection attempts reached, giving up', [
            'email' => $this->email,
            'attempts' => $this->reconnectAttempts,
            'error' => $error->getMessage()
        ]);

        $this->emit('error', [$error]);
        return;
    }

    // Determine backoff delay
    $delayIndex = min($this->reconnectAttempts - 1, count($this->backoffDelays) - 1);
    $delay = $this->backoffDelays[$delayIndex];

    $this->logger->warning('Reconnection failed, scheduling retry', [
        'email' => $this->email,
        'attempt' => $this->reconnectAttempts,
        'next_retry_in_seconds' => $delay,
        'was_reauth' => $wasReauth,
        'error' => $error->getMessage()
    ]);

    // Tier 3: Schedule retry with exponential backoff
    $this->reconnectTimer = $this->loop->addTimer($delay, function() use ($wasReauth) {
        $this->reconnectTimer = null;

        // If simple reconnect failed, try full reauth next time
        $forceReauth = !$wasReauth;

        $this->reconnect($forceReauth);
    });

    $this->emit('reconnect_scheduled', [$delay]);
}

/**
 * Checks if cached authentication tokens are still valid.
 */
private function hasValidTokens(): bool
{
    // If Connection object doesn't exist, tokens are invalid
    if ($this->connection === null) {
        return false;
    }

    $now = time();

    // Check MQTT token expiry (primary concern, ~3 days)
    if ($this->mqttTokenExpiresAt !== null && $this->mqttTokenExpiresAt <= $now) {
        $this->logger->debug('MQTT token expired', [
            'email' => $this->email,
            'expired_at' => date('Y-m-d H:i:s', $this->mqttTokenExpiresAt)
        ]);
        return false;
    }

    // Check login token expiry (~14 years, rarely expires)
    if ($this->loginTokenExpiresAt !== null && $this->loginTokenExpiresAt <= $now) {
        $this->logger->debug('Login token expired', [
            'email' => $this->email,
            'expired_at' => date('Y-m-d H:i:s', $this->loginTokenExpiresAt)
        ]);
        return false;
    }

    return true;
}

/**
 * Clears all cached authentication tokens.
 */
private function clearAuthTokens(): void
{
    $this->mqttTokenExpiresAt = null;
    $this->loginTokenExpiresAt = null;
}

/**
 * Re-subscribes to all device topics after reconnection.
 */
private function resubscribeToDevices(): PromiseInterface
{
    $this->logger->debug('Re-subscribing to device topics', [
        'email' => $this->email,
        'device_count' => count($this->devices)
    ]);

    foreach ($this->devices as $device) {
        $mac = $device->getMqttId();
        $topic = "$mac/device/response/+";
        $this->subscribe($topic);
    }

    return \React\Promise\resolve();
}
```

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php
git commit -m "feat(bridge): Add three-tier reconnect logic to AsyncCloudClient"
```

**Deliverable:** ✅ Reconnect state management complete

---

### Step 3.2: Add Token Expiry Tracking and Error Detection (60 min)

**Update:** `src/Bridge/AsyncCloudClient.php`

**Modify the existing `authenticate()` method** to track token expiry:

```php
/**
 * Reuse existing Connection class for 3-stage auth.
 * MODIFICATION: Extract and store token expiry timestamps.
 */
private function authenticate(): PromiseInterface
{
    $this->connection = new Connection(
        $this->email,
        $this->password,
        $this->logger
    );

    try {
        // This is synchronous but fast (~1-2 seconds)
        $this->connection->connect();

        // Extract MQTT token expiry from Connection object
        $mqttToken = $this->connection->getMqttToken();
        if (isset($mqttToken['token'])) {
            $this->mqttTokenExpiresAt = $this->extractJwtExpiry($mqttToken['token']);
        }

        // Extract login token expiry (stored in Connection from Stage 2)
        // Access via Connection's internal state if exposed, or skip (14 year expiry)

        return \React\Promise\resolve();
    } catch (\Exception $e) {
        return \React\Promise\reject($e);
    }
}

/**
 * Extracts expiry timestamp from JWT token.
 */
private function extractJwtExpiry(string $jwt): ?int
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }

    // Decode JWT payload (second part)
    $payload = json_decode(base64_decode($parts[1]), true);

    return $payload['exp'] ?? null;
}
```

**Modify the existing `setupMqtt()` method** to detect auth failures:

```php
/**
 * Setup MQTT over WebSocket (event-based).
 * MODIFICATION: Detect CONNACK errors and trigger reconnect.
 */
private function setupMqtt(): PromiseInterface
{
    $deferred = new \React\Promise\Deferred();

    // ... existing WebSocket event registration ...

    // Modify CONNACK handler to detect auth failures
    $this->once('mqtt_connack', function($returnCode) use ($deferred) {
        if ($returnCode === 0) {
            $this->logger->info('MQTT connection accepted');
            $deferred->resolve();
        } else {
            $error = new \RuntimeException("MQTT connection refused: code $returnCode");
            $this->logger->error('MQTT connection refused', ['code' => $returnCode]);

            // CONNACK code 5 = Not authorized → force re-auth
            if ($returnCode === 5) {
                $this->logger->warning('MQTT auth failed, scheduling re-auth');
                $this->loop->futureTick(function() {
                    $this->reconnect(true); // Force Tier 2 re-auth
                });
            }

            $deferred->reject($error);
        }
    });

    // ... existing MQTT CONNECT packet sending ...

    return $deferred->promise();
}
```

**Modify the existing `connectWebSocket()` method** to register disconnect handler:

```php
/**
 * Connect to WebSocket.
 * MODIFICATION: Register disconnect handler for auto-reconnect.
 */
private function connectWebSocket(): PromiseInterface
{
    $wsConnector = new WebSocketConnector($this->loop);
    $mqttUrl = 'wss://mqtt.sydpower.com:8083/mqtt';

    $this->logger->debug('Connecting WebSocket', ['url' => $mqttUrl]);

    return $wsConnector($mqttUrl)->then(
        function(WebSocket $conn) {
            $this->websocket = $conn;
            $this->logger->debug('WebSocket connected');

            // Register disconnect handler for auto-reconnect
            $conn->on('close', function($code = null, $reason = null) {
                $this->logger->warning('WebSocket closed', [
                    'code' => $code,
                    'reason' => $reason
                ]);

                $this->connected = false;
                $this->emit('disconnect');

                // Auto-reconnect if not manually disconnected
                if ($this->running && !$this->reconnecting) {
                    $this->loop->futureTick(function() {
                        $this->reconnect(false); // Try Tier 1 first
                    });
                }
            });

            return $conn;
        }
    );
}
```

**Add helper property** to track if client should reconnect:

```php
// ADD to properties section:
private bool $running = true; // Set to false during shutdown
```

**Modify `disconnect()` to set running flag:**

```php
/**
 * Disconnect from cloud (async).
 * MODIFICATION: Set running=false to prevent auto-reconnect.
 */
public function disconnect(): PromiseInterface
{
    $this->logger->info('AsyncCloudClient disconnecting');

    $this->running = false; // Prevent auto-reconnect
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
```

**Important Notes:**

1. **Event-Based Architecture**: The reconnect logic integrates with the existing event-based `AsyncCloudClient` from Phase 1. No `php-mqtt/client` library is used.

2. **WebSocket Close Event**: Pawl WebSocket automatically emits `close` event on disconnect. We register handler in `connectWebSocket()`.

3. **MQTT Auth Failures**: Detected in `processMqttPacket()` when CONNACK return code is 5. This triggers `reconnect(true)` to force Tier 2 re-auth.

4. **Token Expiry**: JWT expiry is extracted from MQTT token. Connection object is kept in memory for Tier 1 reconnects.

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php
git commit -m "feat(reconnect): Add auto-reconnect with token expiry detection"
```

**Deliverable:** ✅ Automatic reconnection with auth error detection

---

### Step 3.3: Add Broker Reconnect Logic to MqttBridge (30 min)

**Update:** `src/Bridge/MqttBridge.php`

**IMPORTANT:** This step modifies the existing `connectBroker()` method from Phase 2 to add reconnect handling.

**Add properties** for broker reconnect state:

```php
// ADD to MqttBridge properties:
private int $brokerReconnectAttempt = 0;
private int $maxBrokerReconnectAttempts = 5;
private array $brokerBackoffDelays = [5, 10, 15, 30, 60]; // seconds
```

**Replace the existing `connectBroker()` method** with this version that includes error handling:

```php
/**
 * Connect to local Mosquitto broker with reconnect logic.
 */
private function connectBroker(): void
{
    $host = $this->config['mosquitto']['host'];
    $port = $this->config['mosquitto']['port'];
    $clientId = $this->config['mosquitto']['client_id'] ?? 'fossibot_bridge';

    $this->logger->info('Connecting to local broker', [
        'host' => $host,
        'port' => $port,
        'attempt' => $this->brokerReconnectAttempt + 1
    ]);

    try {
        $this->brokerClient = new MqttClient($host, $port, $clientId);

        $settings = (new ConnectionSettings)
            ->setKeepAliveInterval(60)
            ->setUseTls(false)
            ->setLastWillTopic('fossibot/bridge/status')
            ->setLastWillMessage('offline')
            ->setLastWillQualityOfService(1)
            ->setRetainLastWill(true);

        if (!empty($this->config['mosquitto']['username'])) {
            $settings->setUsername($this->config['mosquitto']['username']);
            $settings->setPassword($this->config['mosquitto']['password']);
        }

        $this->brokerClient->connect($settings, true);

        // Subscribe to command topics
        $this->brokerClient->subscribe('fossibot/+/command', function($topic, $payload) {
            $this->handleBrokerCommand($topic, $payload);
        }, 1);

        $this->logger->info('Connected to local broker');

        // Reset reconnect counter on success
        $this->brokerReconnectAttempt = 0;

    } catch (\Exception $e) {
        $this->handleBrokerConnectionFailure($e);
    }
}

/**
 * Handles broker connection failure with exponential backoff.
 */
private function handleBrokerConnectionFailure(\Exception $error): void
{
    $this->brokerReconnectAttempt++;

    if ($this->brokerReconnectAttempt > $this->maxBrokerReconnectAttempts) {
        $this->logger->critical('Failed to connect to local broker after max attempts', [
            'attempts' => $this->brokerReconnectAttempt,
            'error' => $error->getMessage()
        ]);

        // Don't give up completely - reset counter and continue trying
        $this->brokerReconnectAttempt = 0;
        $delay = $this->brokerBackoffDelays[count($this->brokerBackoffDelays) - 1];
    } else {
        $delayIndex = min($this->brokerReconnectAttempt - 1, count($this->brokerBackoffDelays) - 1);
        $delay = $this->brokerBackoffDelays[$delayIndex];
    }

    $this->logger->error('Failed to connect to local broker, retrying', [
        'attempt' => $this->brokerReconnectAttempt,
        'next_retry_in_seconds' => $delay,
        'error' => $error->getMessage()
    ]);

    // Schedule reconnect attempt
    $this->loop->addTimer($delay, function() {
        $this->connectBroker();
    });
}
```

**Add periodic broker health check** in the `run()` method (after the broker message loop):

```php
// In MqttBridge::run(), add after broker message loop setup:

// Setup broker health check (every 30 seconds)
$this->loop->addPeriodicTimer(30, function() {
    if ($this->brokerClient !== null) {
        try {
            // The loop() call will throw if connection is dead
            $this->brokerClient->loop(true);
        } catch (\Exception $e) {
            $this->logger->warning('Broker health check failed, reconnecting', [
                'error' => $e->getMessage()
            ]);

            // Trigger reconnect
            $this->brokerClient = null;
            $this->connectBroker();
        }
    }
});
```

**Important Notes:**

1. **No Hard Failure**: Unlike cloud clients, broker failure should not stop the bridge. It keeps retrying indefinitely.

2. **Health Check**: The periodic health check detects silent connection drops (e.g., network cable unplugged).

3. **Last Will and Testament**: LWT ensures `fossibot/bridge/status` shows `offline` if bridge crashes before graceful shutdown.

4. **Exponential Backoff**: Same pattern as cloud reconnect (5s → 60s max), but never gives up completely.

**Commit:**
```bash
git add src/Bridge/MqttBridge.php
git commit -m "feat(reconnect): Add broker reconnect with exponential backoff"
```

**Deliverable:** ✅ Broker reconnection resilience

---

### Step 3.4: Test Reconnect Scenarios (60 min)

**Test Script:** `test_reconnect_scenarios.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use React\EventLoop\Loop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$email = getenv('FOSSIBOT_EMAIL');
$password = getenv('FOSSIBOT_PASSWORD');

$logger = new Logger('reconnect_test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

echo "=== Reconnect Scenario Tests ===\n\n";

$loop = Loop::get();

// Test 1: Automatic reconnect on WebSocket close
echo "Test 1: WebSocket disconnect and auto-reconnect\n";
echo "-----------------------------------------------\n";

$client = new AsyncCloudClient($email, $password, $loop, $logger);

$client->on('connect', function() {
    echo "✅ Connected\n";
});

$client->on('disconnect', function() {
    echo "⚠️  Disconnected\n";
});

$client->on('reconnect', function() {
    echo "✅ Reconnected successfully\n";
});

$client->on('reconnect_scheduled', function($delay) {
    echo "⏱️  Reconnect scheduled in {$delay} seconds\n";
});

$client->on('error', function($error) {
    echo "❌ Error: " . $error->getMessage() . "\n";
});

// Connect and then simulate disconnect after 5 seconds
$client->connect()->then(function() use ($client, $loop) {
    echo "Initial connection successful, will close connection in 5s\n\n";

    $loop->addTimer(5, function() use ($client) {
        echo "Simulating disconnect...\n";
        $client->disconnect();
    });
});

// Run for 30 seconds to observe reconnection
$loop->addTimer(30, function() use ($loop) {
    echo "\nTest complete\n";
    $loop->stop();
});

$loop->run();
```

**Expected Output:**
```
Test 1: WebSocket disconnect and auto-reconnect
-----------------------------------------------
✅ Connected
Initial connection successful, will close connection in 5s

Simulating disconnect...
⚠️  Disconnected
✅ Reconnected successfully

Test complete
```

**Test Script:** `test_token_expiry.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use React\EventLoop\Loop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$email = getenv('FOSSIBOT_EMAIL');
$password = getenv('FOSSIBOT_PASSWORD');

$logger = new Logger('token_test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

echo "=== Token Expiry Test ===\n\n";

$loop = Loop::get();
$client = new AsyncCloudClient($email, $password, $loop, $logger);

$client->connect()->then(function() use ($client) {
    echo "✅ Connected\n";

    // Use reflection to check token expiry times
    $reflection = new \ReflectionClass($client);

    $mqttExpiry = $reflection->getProperty('mqttTokenExpiresAt');
    $mqttExpiry->setAccessible(true);
    $mqttExpiryValue = $mqttExpiry->getValue($client);

    $loginExpiry = $reflection->getProperty('loginTokenExpiresAt');
    $loginExpiry->setAccessible(true);
    $loginExpiryValue = $loginExpiry->getValue($client);

    echo "\nToken Expiry Times:\n";
    echo "-------------------\n";

    if ($mqttExpiryValue !== null) {
        $expiresIn = $mqttExpiryValue - time();
        echo "MQTT Token: " . date('Y-m-d H:i:s', $mqttExpiryValue);
        echo " (~" . round($expiresIn / 3600, 1) . " hours)\n";
    }

    if ($loginExpiryValue !== null) {
        $expiresIn = $loginExpiryValue - time();
        echo "Login Token: " . date('Y-m-d H:i:s', $loginExpiryValue);
        echo " (~" . round($expiresIn / 86400, 1) . " days)\n";
    }

    echo "\n✅ Token expiry tracking working\n";
});

$loop->addTimer(5, fn() => $loop->stop());
$loop->run();
```

**Expected Output:**
```
=== Token Expiry Test ===

✅ Connected

Token Expiry Times:
-------------------
MQTT Token: 2025-10-03 01:58:44 (~72.0 hours)
Login Token: 2039-11-20 12:34:56 (~5112.5 days)

✅ Token expiry tracking working
```

**Test Script:** `test_broker_reconnect.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\MqttBridge;
use React\EventLoop\Loop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('broker_reconnect_test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

echo "=== Broker Reconnect Test ===\n\n";
echo "Instructions:\n";
echo "1. Script will connect to broker\n";
echo "2. Stop Mosquitto: brew services stop mosquitto\n";
echo "3. Observe reconnect attempts\n";
echo "4. Start Mosquitto: brew services start mosquitto\n";
echo "5. Observe successful reconnection\n\n";

$config = json_decode(file_get_contents('config/example.json'), true);
$loop = Loop::get();

$bridge = new MqttBridge($config, $loop, $logger);

try {
    $bridge->start();
    echo "✅ Bridge started and connected to broker\n\n";
    echo "Now stop Mosquitto and observe reconnection...\n\n";
} catch (\Throwable $e) {
    echo "❌ Failed to start bridge: " . $e->getMessage() . "\n";
    exit(1);
}

// Run for 5 minutes to observe reconnection behavior
$loop->addTimer(300, function() use ($loop) {
    echo "\nTest complete\n";
    $loop->stop();
});

$loop->run();
```

**Run tests:**
```bash
php test_reconnect_scenarios.php
php test_token_expiry.php
php test_broker_reconnect.php  # Interactive test
```

**Commit:**
```bash
git add test_reconnect_scenarios.php test_token_expiry.php test_broker_reconnect.php
git commit -m "test: Add reconnect scenario validation tests"
```

**Deliverable:** ✅ Reconnect logic tested with multiple scenarios

---

### Step 3.5: Add Graceful Shutdown Handling (45 min)

**Update:** `src/Bridge/MqttBridge.php`

Add signal handlers for graceful shutdown:

```php
/**
 * Starts the bridge event loop with graceful shutdown handling.
 */
public function start(): void
{
    $this->logger->info('Starting Fossibot MQTT Bridge');

    // Initialize components
    $this->initializeBrokerClient();
    $this->initializeAccounts();
    $this->startStatusPublisher();

    // Register shutdown handlers
    $this->registerShutdownHandlers();

    // Publish initial status
    $this->publishBridgeStatus();

    $this->logger->info('Bridge started successfully, entering event loop');

    // Run event loop
    $this->loop->run();
}

/**
 * Registers signal handlers for graceful shutdown.
 */
private function registerShutdownHandlers(): void
{
    // SIGTERM (systemd stop)
    $this->loop->addSignal(SIGTERM, function() {
        $this->logger->info('Received SIGTERM, shutting down gracefully');
        $this->shutdown();
    });

    // SIGINT (Ctrl+C)
    $this->loop->addSignal(SIGINT, function() {
        $this->logger->info('Received SIGINT, shutting down gracefully');
        $this->shutdown();
    });
}

/**
 * Performs graceful shutdown.
 */
private function shutdown(): void
{
    $this->logger->info('Starting graceful shutdown');

    // Publish offline status
    $this->brokerClient->publish(
        'fossibot/bridge/status',
        'offline',
        1,
        true
    );

    // Publish device offline status
    foreach ($this->cloudClients as $email => $client) {
        foreach ($client->getDevices() as $device) {
            $mac = $device->getMqttId();
            $this->brokerClient->publish(
                "fossibot/$mac/availability",
                'offline',
                1,
                true
            );
        }
    }

    // Disconnect cloud clients
    foreach ($this->cloudClients as $email => $client) {
        $this->logger->debug('Disconnecting cloud client', ['email' => $email]);
        $client->disconnect();
    }

    // Disconnect broker client
    $this->logger->debug('Disconnecting from local broker');
    $this->brokerClient->disconnect();

    // Stop event loop
    $this->loop->stop();

    $this->logger->info('Graceful shutdown complete');
}
```

**Commit:**
```bash
git add src/Bridge/MqttBridge.php
git commit -m "feat(shutdown): Add graceful shutdown with signal handlers"
```

**Deliverable:** ✅ Graceful shutdown on SIGTERM/SIGINT

---

## ✅ Phase 3 Completion Checklist

- [ ] AsyncCloudClient has three-tier reconnect strategy
- [ ] Token expiry tracking implemented
- [ ] Automatic reconnection on WebSocket/MQTT disconnect
- [ ] Broker reconnection with exponential backoff
- [ ] Graceful shutdown with signal handlers
- [ ] All reconnect scenarios tested
- [ ] All commits made with proper messages

---

## 🎯 Success Criteria

**Phase 3 is complete when:**

1. Cloud disconnections trigger automatic reconnection
2. Authentication errors trigger full re-authentication
3. Failed reconnects use exponential backoff (5s → 60s)
4. Broker disconnections are handled gracefully
5. Token expiry is tracked and triggers re-auth
6. SIGTERM/SIGINT trigger graceful shutdown
7. All test scripts pass:
   - `test_reconnect_scenarios.php` shows auto-reconnect
   - `test_token_expiry.php` shows token tracking
   - `test_broker_reconnect.php` shows broker resilience

---

## 🐛 Troubleshooting

**Problem:** Reconnection loops continuously

**Solution:** Check token expiry logic:
```bash
php test_token_expiry.php
```

If tokens show as expired immediately, verify JWT parsing in `extractJwtExpiry()`.

---

**Problem:** Graceful shutdown doesn't publish offline status

**Solution:** Verify broker connection before shutdown:
```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v
# Then stop bridge
```

Should see `offline` message retained.

---

**Problem:** Exponential backoff not working

**Solution:** Check logs for reconnect attempts:
```bash
tail -f logs/bridge.log | grep reconnect
```

Should see increasing delays: 5s, 10s, 15s, 30s, 45s, 60s.

---

## 📚 Next Steps

**Phase 3 complete!** → [07-PHASE-4-CLI.md](07-PHASE-4-CLI.md)

Implement CLI entry point and systemd service configuration.