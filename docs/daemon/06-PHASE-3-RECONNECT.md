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
| **WebSocket disconnect** | Pawl `close` event | Tier 1: Simple reconnect |
| **MQTT disconnect** | MqttClient exception | Tier 1: Simple reconnect |
| **Auth token expired** | HTTP 401/403 in API call | Tier 2: Full re-auth |
| **MQTT auth failure** | CONNACK return code 5 | Tier 2: Full re-auth |
| **Network timeout** | Promise timeout (10s) | Tier 1: Simple reconnect |
| **Rate limiting** | HTTP 429 | Tier 3: Exponential backoff |
| **Server error** | HTTP 5xx | Tier 3: Exponential backoff |

---

## 📋 Step-by-Step Implementation

### Step 3.1: Add Reconnect State Management to AsyncCloudClient (60 min)

**Update:** `src/Bridge/AsyncCloudClient.php`

Add state tracking and reconnect configuration:

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;

/**
 * Async MQTT client for Fossibot Cloud with smart reconnect logic.
 *
 * Implements three-tier reconnect strategy:
 * - Tier 1: Simple reconnect (reuse tokens)
 * - Tier 2: Full re-authentication (new tokens)
 * - Tier 3: Exponential backoff (5s → 60s max)
 */
class AsyncCloudClient extends EventEmitter
{
    // Connection state
    private bool $connected = false;
    private bool $reconnecting = false;

    // Reconnect configuration
    private int $reconnectAttempts = 0;
    private int $maxReconnectAttempts = 10;
    private array $backoffDelays = [5, 10, 15, 30, 45, 60]; // seconds

    // Token expiry tracking
    private ?int $mqttTokenExpiresAt = null;
    private ?int $loginTokenExpiresAt = null;

    // Reconnect timer
    private ?\React\EventLoop\TimerInterface $reconnectTimer = null;

    // ... existing constructor and methods ...

    /**
     * Initiates reconnection with smart tier-based strategy.
     *
     * @param bool $forceReauth Force Tier 2 (full re-auth) immediately
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
     */
    private function attemptSimpleReconnect(): PromiseInterface
    {
        $this->logger->debug('Attempting Tier 1: Simple reconnect', [
            'email' => $this->email
        ]);

        // Close existing connections cleanly
        $this->disconnect();

        // Reconnect with existing tokens
        return $this->connectWebSocket()
            ->then(fn() => $this->setupMqtt())
            ->then(fn() => $this->resubscribeToDevices());
    }

    /**
     * Tier 2: Full re-authentication flow (Stage 1-4).
     */
    private function attemptFullReauth(): PromiseInterface
    {
        $this->logger->debug('Attempting Tier 2: Full re-authentication', [
            'email' => $this->email
        ]);

        // Clean slate
        $this->disconnect();
        $this->clearAuthTokens();

        // Full authentication flow
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
        // Note: Actual token strings are cleared in authenticate() method
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
            $topic = "{$device->getMqttId()}/device/response/+";
            $this->subscribe($topic);
        }

        return \React\Promise\resolve();
    }

    /**
     * Override disconnect to ensure clean state.
     */
    public function disconnect(): void
    {
        $this->connected = false;

        if ($this->mqttClient !== null) {
            try {
                $this->mqttClient->disconnect();
            } catch (\Throwable $e) {
                $this->logger->debug('Error disconnecting MQTT client', [
                    'error' => $e->getMessage()
                ]);
            }
            $this->mqttClient = null;
        }

        if ($this->websocket !== null) {
            $this->websocket->close();
            $this->websocket = null;
        }

        if ($this->messageLoopTimer !== null) {
            $this->loop->cancelTimer($this->messageLoopTimer);
            $this->messageLoopTimer = null;
        }
    }
}
```

**Update:** `src/Bridge/AsyncCloudClient.php` (authenticate method)

Add token expiry tracking:

```php
/**
 * Executes full authentication flow (Stage 1-4).
 * Stores token expiry times for reconnect logic.
 */
private function authenticate(): PromiseInterface
{
    $this->logger->debug('Starting authentication', ['email' => $this->email]);

    return $this->authenticateStage1() // Anonymous token
        ->then(function($stage1Response) {
            // Extract anonymous token expiry
            if (isset($stage1Response['expiresInSecond'])) {
                $this->mqttTokenExpiresAt = time() + (int)$stage1Response['expiresInSecond'];
            }

            return $this->authenticateStage2(); // Login
        })
        ->then(function($stage2Response) {
            // Extract login token expiry
            if (isset($stage2Response['tokenExpired'])) {
                $this->loginTokenExpiresAt = (int)($stage2Response['tokenExpired'] / 1000);
            }

            return $this->authenticateStage3(); // MQTT token
        })
        ->then(function($stage3Response) {
            // Parse JWT to extract expiry
            if (isset($stage3Response['token'])) {
                $this->mqttTokenExpiresAt = $this->extractJwtExpiry($stage3Response['token']);
            }

            return $stage3Response;
        });
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

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php
git commit -m "feat(reconnect): Add three-tier reconnect strategy to AsyncCloudClient"
```

**Deliverable:** ✅ AsyncCloudClient has reconnect state management

---

### Step 3.2: Add Error Detection and Auto-Reconnect (45 min)

**Update:** `src/Bridge/AsyncCloudClient.php` (connect method)

Register disconnect handlers:

```php
/**
 * Establishes connection to Fossibot Cloud MQTT.
 * Registers automatic reconnect on disconnect.
 */
public function connect(): PromiseInterface
{
    return $this->authenticate()
        ->then(fn() => $this->connectWebSocket())
        ->then(fn() => $this->setupWebSocketHandlers()) // NEW
        ->then(fn() => $this->setupMqtt())
        ->then(fn() => $this->discoverDevices())
        ->then(function() {
            $this->connected = true;
            $this->reconnectAttempts = 0; // Reset on successful connect
            $this->emit('connect');
        })
        ->otherwise(function($error) {
            $this->logger->error('Connection failed', [
                'email' => $this->email,
                'error' => $error->getMessage()
            ]);

            // Schedule reconnect on initial connection failure
            $this->onReconnectFailure($error, false);

            throw $error;
        });
}

/**
 * Registers WebSocket event handlers for automatic reconnection.
 */
private function setupWebSocketHandlers(): PromiseInterface
{
    // Handle WebSocket close
    $this->websocket->on('close', function() {
        if (!$this->connected) {
            return; // Already handling disconnect
        }

        $this->logger->warning('WebSocket connection closed', [
            'email' => $this->email
        ]);

        $this->connected = false;
        $this->emit('disconnect');

        // Trigger automatic reconnect
        $this->reconnect(false);
    });

    // Handle WebSocket errors
    $this->websocket->on('error', function(\Exception $error) {
        $this->logger->error('WebSocket error', [
            'email' => $this->email,
            'error' => $error->getMessage()
        ]);

        $this->emit('error', [$error]);

        // Don't trigger reconnect here - wait for 'close' event
    });

    return \React\Promise\resolve();
}
```

**Update:** `src/Bridge/AsyncCloudClient.php` (setupMqtt method)

Add MQTT error detection:

```php
/**
 * Initializes MQTT client over WebSocket transport.
 * Detects authentication failures via CONNACK codes.
 */
private function setupMqtt(): PromiseInterface
{
    $this->logger->debug('Setting up MQTT protocol', ['email' => $this->email]);

    try {
        $this->mqttClient = new \PhpMqtt\Client\MqttClient(
            stream: $this->websocket->stream,
            clientId: 'fossibot_client_' . uniqid(),
            logger: $this->logger
        );

        $connectionSettings = (new \PhpMqtt\Client\ConnectionSettings)
            ->setUsername($this->mqttUsername)
            ->setPassword($this->mqttPassword)
            ->setConnectTimeout(10)
            ->setUseTls(false)
            ->setKeepAliveInterval(60);

        $this->mqttClient->connect($connectionSettings);

        // Check CONNACK return code
        // Note: php-mqtt/client throws exception on non-zero CONNACK
        // Exception message contains return code

    } catch (\PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException $e) {
        // Check if this is an authentication failure (CONNACK code 5)
        if (str_contains($e->getMessage(), 'code 5') ||
            str_contains($e->getMessage(), 'not authorized')) {

            $this->logger->warning('MQTT authentication failed, triggering re-auth', [
                'email' => $this->email,
                'error' => $e->getMessage()
            ]);

            // Force Tier 2 (full re-auth) on next reconnect
            $this->clearAuthTokens();
        }

        throw $e;
    }

    return \React\Promise\resolve();
}
```

**Commit:**
```bash
git add src/Bridge/AsyncCloudClient.php
git commit -m "feat(reconnect): Add automatic reconnect on WebSocket/MQTT disconnect"
```

**Deliverable:** ✅ Automatic reconnection on connection loss

---

### Step 3.3: Add Broker Reconnect Logic to MqttBridge (30 min)

**Update:** `src/Bridge/MqttBridge.php`

Add broker reconnect handling:

```php
/**
 * Initializes local Mosquitto broker connection with reconnect handling.
 */
private function initializeBrokerClient(): void
{
    $this->logger->info('Connecting to local MQTT broker', [
        'host' => $this->config['mosquitto']['host'],
        'port' => $this->config['mosquitto']['port']
    ]);

    $this->brokerClient = new \PhpMqtt\Client\MqttClient(
        server: $this->config['mosquitto']['host'],
        port: $this->config['mosquitto']['port'],
        clientId: $this->config['mosquitto']['client_id'],
        logger: $this->logger
    );

    $connectionSettings = (new \PhpMqtt\Client\ConnectionSettings)
        ->setConnectTimeout(5)
        ->setUseTls(false)
        ->setKeepAliveInterval(60)
        ->setLastWillTopic('fossibot/bridge/status')
        ->setLastWillMessage('offline')
        ->setLastWillQualityOfService(1)
        ->setRetainLastWill(true);

    // Add credentials if configured
    if ($this->config['mosquitto']['username'] !== null) {
        $connectionSettings
            ->setUsername($this->config['mosquitto']['username'])
            ->setPassword($this->config['mosquitto']['password']);
    }

    try {
        $this->brokerClient->connect($connectionSettings);

        $this->logger->info('Connected to local MQTT broker');

        // Setup broker reconnect monitoring
        $this->setupBrokerReconnect();

    } catch (\Throwable $e) {
        $this->logger->error('Failed to connect to local broker', [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Monitors broker connection and reconnects if needed.
 */
private function setupBrokerReconnect(): void
{
    // Periodic connection check (every 30 seconds)
    $this->loop->addPeriodicTimer(30, function() {
        if (!$this->isBrokerConnected()) {
            $this->logger->warning('Local broker connection lost, reconnecting...');
            $this->reconnectBroker();
        }
    });
}

/**
 * Checks if broker connection is alive.
 */
private function isBrokerConnected(): bool
{
    try {
        // Send PINGREQ to check connection
        $this->brokerClient->loop(true);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Reconnects to local broker with exponential backoff.
 */
private function reconnectBroker(int $attempt = 1): void
{
    $maxAttempts = 5;
    $delays = [5, 10, 15, 30, 60];

    if ($attempt > $maxAttempts) {
        $this->logger->critical('Failed to reconnect to local broker after max attempts');
        return;
    }

    try {
        $this->brokerClient->connect();

        $this->logger->info('Reconnected to local broker');

        // Re-subscribe to command topics
        $this->subscribeToCommandTopics();

    } catch (\Throwable $e) {
        $delay = $delays[min($attempt - 1, count($delays) - 1)];

        $this->logger->warning('Broker reconnect failed, retrying', [
            'attempt' => $attempt,
            'next_retry_in_seconds' => $delay,
            'error' => $e->getMessage()
        ]);

        $this->loop->addTimer($delay, function() use ($attempt) {
            $this->reconnectBroker($attempt + 1);
        });
    }
}
```

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