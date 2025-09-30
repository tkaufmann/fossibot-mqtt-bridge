# 05 - Phase 2: MqttBridge Multi-Account Implementation

**Phase:** 2 - Bridge Infrastructure
**Effort:** ~6 hours
**Prerequisites:** Phase 1 complete (AsyncCloudClient working)
**Deliverables:** Complete MqttBridge with multi-account, TopicTranslator, PayloadTransformer

---

## 🎯 Phase Goals

1. Implement TopicTranslator (Cloud ↔ Broker topic mapping)
2. Implement PayloadTransformer (Modbus ↔ JSON conversion)
3. Implement MqttBridge with multi-account support
4. Connect bridge to local Mosquitto broker
5. Route messages bidirectionally (Cloud ↔ Broker)
6. Test end-to-end message flow

---

## 📋 Step-by-Step Implementation

### Step 2.1: Implement TopicTranslator (45 min)

**File:** `src/Bridge/TopicTranslator.php`

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Translates MQTT topics between Fossibot Cloud and local broker.
 *
 * Cloud topics: {mac}/device/response/client/04
 * Broker topics: fossibot/{mac}/state
 */
class TopicTranslator
{
    /**
     * Convert cloud topic to broker topic.
     *
     * Examples:
     * - "7C2C67AB5F0E/device/response/client/04" → "fossibot/7C2C67AB5F0E/state"
     * - "7C2C67AB5F0E/device/response/client/data" → "fossibot/7C2C67AB5F0E/state"
     *
     * @param string $cloudTopic Fossibot Cloud topic
     * @return string Standard MQTT topic
     */
    public function cloudToBroker(string $cloudTopic): string
    {
        $mac = $this->extractMacFromCloudTopic($cloudTopic);

        if ($mac === null) {
            throw new \InvalidArgumentException("Cannot extract MAC from cloud topic: $cloudTopic");
        }

        // All device response topics → state topic
        if (str_contains($cloudTopic, '/device/response/')) {
            return "fossibot/$mac/state";
        }

        // Unknown pattern
        return "fossibot/$mac/unknown";
    }

    /**
     * Convert broker topic to cloud topic.
     *
     * Example:
     * - "fossibot/7C2C67AB5F0E/command" → "7C2C67AB5F0E/client/request/data"
     *
     * @param string $brokerTopic Standard MQTT topic
     * @return string Fossibot Cloud topic
     */
    public function brokerToCloud(string $brokerTopic): string
    {
        $mac = $this->extractMacFromBrokerTopic($brokerTopic);

        if ($mac === null) {
            throw new \InvalidArgumentException("Cannot extract MAC from broker topic: $brokerTopic");
        }

        // Commands → client request topic
        if (str_contains($brokerTopic, '/command')) {
            return "$mac/client/request/data";
        }

        throw new \InvalidArgumentException("Unknown broker topic pattern: $brokerTopic");
    }

    /**
     * Extract MAC address from cloud topic.
     *
     * @param string $topic Cloud topic (e.g., "7C2C67AB5F0E/device/response/client/04")
     * @return string|null MAC address or null if not found
     */
    public function extractMacFromCloudTopic(string $topic): ?string
    {
        // MAC is first segment before /
        $parts = explode('/', $topic);

        if (empty($parts[0])) {
            return null;
        }

        $mac = $parts[0];

        // Validate MAC format (12 hex chars)
        if (strlen($mac) === 12 && ctype_xdigit($mac)) {
            return strtoupper($mac);
        }

        return null;
    }

    /**
     * Extract MAC address from broker topic.
     *
     * @param string $topic Broker topic (e.g., "fossibot/7C2C67AB5F0E/state")
     * @return string|null MAC address or null if not found
     */
    public function extractMacFromBrokerTopic(string $topic): ?string
    {
        // Pattern: fossibot/{mac}/...
        if (preg_match('/^fossibot\/([A-F0-9]{12})\//i', $topic, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}
```

**Test script:** `test_topic_translator.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\TopicTranslator;

echo "Testing TopicTranslator...\n\n";

$translator = new TopicTranslator();

// Test 1: Cloud to Broker
echo "Test 1: cloudToBroker\n";
$cloudTopic = '7C2C67AB5F0E/device/response/client/04';
$brokerTopic = $translator->cloudToBroker($cloudTopic);
assert($brokerTopic === 'fossibot/7C2C67AB5F0E/state', "Expected 'fossibot/7C2C67AB5F0E/state', got '$brokerTopic'");
echo "✅ $cloudTopic\n   → $brokerTopic\n\n";

// Test 2: Broker to Cloud
echo "Test 2: brokerToCloud\n";
$brokerTopic = 'fossibot/7C2C67AB5F0E/command';
$cloudTopic = $translator->brokerToCloud($brokerTopic);
assert($cloudTopic === '7C2C67AB5F0E/client/request/data', "Expected '7C2C67AB5F0E/client/request/data', got '$cloudTopic'");
echo "✅ $brokerTopic\n   → $cloudTopic\n\n";

// Test 3: Extract MAC from cloud topic
echo "Test 3: extractMacFromCloudTopic\n";
$mac = $translator->extractMacFromCloudTopic('7c2c67ab5f0e/device/response/client/04');
assert($mac === '7C2C67AB5F0E', "Expected '7C2C67AB5F0E', got '$mac'");
echo "✅ Extracted MAC: $mac\n\n";

// Test 4: Extract MAC from broker topic
echo "Test 4: extractMacFromBrokerTopic\n";
$mac = $translator->extractMacFromBrokerTopic('fossibot/7C2C67AB5F0E/state');
assert($mac === '7C2C67AB5F0E', "Expected '7C2C67AB5F0E', got '$mac'");
echo "✅ Extracted MAC: $mac\n\n";

echo "✅ All TopicTranslator tests passed!\n";
```

**Run:**
```bash
php test_topic_translator.php
```

**Commit:**
```bash
git add src/Bridge/TopicTranslator.php test_topic_translator.php
git commit -m "feat(bridge): Implement TopicTranslator for MQTT topic mapping"
```

**Deliverable:** ✅ TopicTranslator complete

---

### Step 2.2: Implement PayloadTransformer (90 min)

**File:** `src/Bridge/PayloadTransformer.php`

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Fossibot\Device\DeviceState;
use Fossibot\Commands\Command;
use Fossibot\Commands\UsbOutputCommand;
use Fossibot\Commands\AcOutputCommand;
use Fossibot\Commands\DcOutputCommand;
use Fossibot\Commands\LedOutputCommand;
use Fossibot\Commands\ReadRegistersCommand;
use Fossibot\Commands\MaxChargingCurrentCommand;
use Fossibot\Commands\DischargeLowerLimitCommand;
use Fossibot\Commands\AcChargingUpperLimitCommand;

/**
 * Transforms MQTT payloads between Modbus binary and JSON formats.
 */
class PayloadTransformer
{
    /**
     * Parse Modbus binary payload to register array.
     *
     * @param string $binaryPayload Raw Modbus response
     * @return array Register index => value
     */
    public function parseModbusPayload(string $binaryPayload): array
    {
        if (strlen($binaryPayload) < 5) {
            return [];
        }

        $header = unpack('C*', substr($binaryPayload, 0, 5));
        $byteCount = $header[3];

        $dataStart = 5;
        $dataEnd = $dataStart + $byteCount;

        if ($dataEnd > strlen($binaryPayload)) {
            return [];
        }

        $data = substr($binaryPayload, $dataStart, $byteCount);
        $registers = [];

        // Parse 16-bit registers (big-endian)
        $registerCount = $byteCount / 2;
        for ($i = 0; $i < $registerCount; $i++) {
            $offset = $i * 2;
            if ($offset + 1 < strlen($data)) {
                $high = ord($data[$offset]);
                $low = ord($data[$offset + 1]);
                $registers[$i] = ($high << 8) | $low;
            }
        }

        return $registers;
    }

    /**
     * Convert register array to DeviceState object.
     *
     * @param array $registers Register values
     * @return DeviceState
     */
    public function registersToState(array $registers): DeviceState
    {
        $state = new DeviceState();
        $state->updateFromRegisters($registers);
        return $state;
    }

    /**
     * Convert DeviceState to JSON string.
     *
     * @param DeviceState $state
     * @return string JSON
     */
    public function stateToJson(DeviceState $state): string
    {
        return json_encode([
            'soc' => $state->soc,
            'inputWatts' => $state->inputWatts,
            'outputWatts' => $state->outputWatts,
            'dcInputWatts' => $state->dcInputWatts,
            'usbOutput' => $state->usbOutput,
            'acOutput' => $state->acOutput,
            'dcOutput' => $state->dcOutput,
            'ledOutput' => $state->ledOutput,
            'maxChargingCurrent' => $state->maxChargingCurrent,
            'dischargeLowerLimit' => $state->dischargeLowerLimit,
            'acChargingUpperLimit' => $state->acChargingUpperLimit,
            'timestamp' => $state->lastFullUpdate->format('c')
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Parse JSON command string to Command object.
     *
     * @param string $json JSON command
     * @return Command
     * @throws \InvalidArgumentException If action unknown or parameters invalid
     */
    public function jsonToCommand(string $json): Command
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['action'])) {
            throw new \InvalidArgumentException('Missing "action" field in command JSON');
        }

        $action = $data['action'];

        return match($action) {
            'usb_on' => new UsbOutputCommand(true),
            'usb_off' => new UsbOutputCommand(false),
            'ac_on' => new AcOutputCommand(true),
            'ac_off' => new AcOutputCommand(false),
            'dc_on' => new DcOutputCommand(true),
            'dc_off' => new DcOutputCommand(false),
            'led_on' => new LedOutputCommand(true),
            'led_off' => new LedOutputCommand(false),
            'read_settings' => new ReadRegistersCommand(),
            'set_charging_current' => new MaxChargingCurrentCommand(
                (int)($data['amperes'] ?? throw new \InvalidArgumentException('Missing amperes parameter'))
            ),
            'set_discharge_limit' => new DischargeLowerLimitCommand(
                (float)($data['percentage'] ?? throw new \InvalidArgumentException('Missing percentage parameter'))
            ),
            'set_ac_charging_limit' => new AcChargingUpperLimitCommand(
                (float)($data['percentage'] ?? throw new \InvalidArgumentException('Missing percentage parameter'))
            ),
            default => throw new \InvalidArgumentException("Unknown action: $action")
        };
    }

    /**
     * Convert Command object to Modbus binary string.
     *
     * @param Command $command
     * @return string Binary Modbus payload
     */
    public function commandToModbus(Command $command): string
    {
        $bytes = $command->getModbusBytes();
        $binary = '';

        foreach ($bytes as $byte) {
            $binary .= chr($byte);
        }

        return $binary;
    }
}
```

**Test script:** `test_payload_transformer.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\PayloadTransformer;
use Fossibot\Commands\UsbOutputCommand;

echo "Testing PayloadTransformer...\n\n";

$transformer = new PayloadTransformer();

// Test 1: Parse Modbus
echo "Test 1: parseModbusPayload\n";
$modbusHex = '11030000a2' . str_repeat('0000', 81);
$modbus = hex2bin($modbusHex);
$registers = $transformer->parseModbusPayload($modbus);
assert(count($registers) === 81, "Expected 81 registers");
echo "✅ Parsed 81 registers\n\n";

// Test 2: Registers to State
echo "Test 2: registersToState\n";
$registers = [
    56 => 855,  // SoC = 85.5%
    41 => 0b0000000001000000,  // USB=1
    20 => 12
];
$state = $transformer->registersToState($registers);
assert($state->soc === 85.5, "SoC should be 85.5");
assert($state->usbOutput === true, "USB should be on");
echo "✅ State conversion correct\n\n";

// Test 3: State to JSON
echo "Test 3: stateToJson\n";
$json = $transformer->stateToJson($state);
$decoded = json_decode($json, true);
assert($decoded['soc'] === 85.5, "JSON SoC should be 85.5");
assert($decoded['usbOutput'] === true, "JSON USB should be true");
assert(isset($decoded['inputWatts']), "JSON should have inputWatts");
assert(isset($decoded['outputWatts']), "JSON should have outputWatts");
assert(isset($decoded['dcInputWatts']), "JSON should have dcInputWatts");
echo "✅ JSON: " . substr($json, 0, 100) . "...\n\n";

// Test 4: JSON to Command
echo "Test 4: jsonToCommand\n";
$commandJson = '{"action":"usb_on"}';
$command = $transformer->jsonToCommand($commandJson);
assert($command instanceof UsbOutputCommand, "Should be UsbOutputCommand");
echo "✅ Command created: " . get_class($command) . "\n\n";

// Test 5: Command to Modbus
echo "Test 5: commandToModbus\n";
$modbus = $transformer->commandToModbus($command);
$hex = bin2hex($modbus);
echo "✅ Modbus hex: $hex\n";
assert(strlen($modbus) === 8, "Should be 8 bytes");

echo "\n✅ All PayloadTransformer tests passed!\n";
```

**Run:**
```bash
php test_payload_transformer.php
```

**Commit:**
```bash
git add src/Bridge/PayloadTransformer.php test_payload_transformer.php
git commit -m "feat(bridge): Implement PayloadTransformer for Modbus↔JSON conversion"
```

**Deliverable:** ✅ PayloadTransformer complete

---

### Step 2.3: Implement MqttBridge Base Structure (90 min)

**File:** `src/Bridge/MqttBridge.php`

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Fossibot\Device\DeviceStateManager;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * MQTT Bridge orchestrator with ReactPHP event loop.
 *
 * Manages multiple AsyncCloudClient instances (multi-account support).
 * Routes messages between Fossibot Cloud and local Mosquitto broker.
 * Handles state management and reconnection logic.
 */
class MqttBridge
{
    private LoopInterface $loop;
    private array $config;
    private LoggerInterface $logger;

    /** @var AsyncCloudClient[] Indexed by account email */
    private array $cloudClients = [];

    private ?MqttClient $brokerClient = null;
    private DeviceStateManager $stateManager;
    private TopicTranslator $topicTranslator;
    private PayloadTransformer $payloadTransformer;

    private bool $running = false;
    private int $startTime = 0;

    public function __construct(
        array $config,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->loop = Loop::get();

        // Initialize utilities
        $this->stateManager = new DeviceStateManager();
        $this->topicTranslator = new TopicTranslator();
        $this->payloadTransformer = new PayloadTransformer();
    }

    /**
     * Start bridge (blocking - runs event loop).
     */
    public function run(): void
    {
        $this->logger->info('MqttBridge starting...');
        $this->startTime = time();

        // Setup signal handlers
        $this->setupSignalHandlers();

        // Initialize accounts
        $this->initializeAccounts();

        // Connect to local broker
        $this->connectBroker();

        // Publish initial bridge status
        $this->publishBridgeStatus();

        // Setup broker message loop (process incoming commands from broker)
        $this->loop->addPeriodicTimer(0.1, function() {
            if ($this->brokerClient !== null) {
                try {
                    // Process pending messages from local broker (non-blocking)
                    $this->brokerClient->loop(true);
                } catch (\Exception $e) {
                    $this->logger->error('Broker message loop error', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });

        // Setup status publish timer (every 60s)
        $this->loop->addPeriodicTimer(
            $this->config['bridge']['status_publish_interval'] ?? 60,
            fn() => $this->publishBridgeStatus()
        );

        $this->running = true;
        $this->logger->info('MqttBridge ready, entering event loop');

        // Run event loop (blocks here)
        $this->loop->run();

        $this->logger->info('MqttBridge stopped');
    }

    /**
     * Shutdown bridge gracefully.
     */
    public function shutdown(): void
    {
        $this->logger->info('MqttBridge shutting down...');
        $this->running = false;

        // Disconnect all cloud clients
        foreach ($this->cloudClients as $email => $client) {
            $this->logger->info('Disconnecting cloud client', ['email' => $email]);
            $client->disconnect();
        }

        // Publish offline status
        $this->publishBridgeStatus('offline');

        // Disconnect broker
        if ($this->brokerClient !== null) {
            $this->brokerClient->disconnect();
        }

        // Stop event loop
        $this->loop->stop();
    }

    // --- Private Methods ---

    private function setupSignalHandlers(): void
    {
        // SIGTERM (systemd stop)
        pcntl_signal(SIGTERM, function() {
            $this->logger->info('Received SIGTERM');
            $this->shutdown();
        });

        // SIGINT (Ctrl+C)
        pcntl_signal(SIGINT, function() {
            $this->logger->info('Received SIGINT');
            $this->shutdown();
        });

        // Dispatch signals in event loop
        $this->loop->addPeriodicTimer(1, function() {
            pcntl_signal_dispatch();
        });
    }

    private function initializeAccounts(): void
    {
        foreach ($this->config['accounts'] as $account) {
            if (isset($account['enabled']) && $account['enabled'] === false) {
                $this->logger->info('Account disabled, skipping', ['email' => $account['email']]);
                continue;
            }

            $email = $account['email'];
            $password = $account['password'];

            $this->logger->info('Initializing account', ['email' => $email]);

            $client = new AsyncCloudClient($email, $password, $this->loop, $this->logger);

            // Register event handlers
            $this->registerCloudClientEvents($client, $email);

            $this->cloudClients[$email] = $client;

            // Connect (async)
            $client->connect()->then(
                function() use ($email) {
                    $this->logger->info('Account connected', ['email' => $email]);
                },
                function($error) use ($email) {
                    $this->logger->error('Account connection failed', [
                        'email' => $email,
                        'error' => $error->getMessage()
                    ]);
                }
            );
        }

        $this->logger->info('Initialized accounts', ['count' => count($this->cloudClients)]);
    }

    private function registerCloudClientEvents(AsyncCloudClient $client, string $email): void
    {
        $client->on('connect', function() use ($email, $client) {
            $this->logger->info('Cloud client connected', ['email' => $email]);

            // Publish availability for all devices
            foreach ($client->getDevices() as $device) {
                $this->publishAvailability($device->getMqttId(), 'online');
            }
        });

        $client->on('message', function($topic, $payload) use ($email) {
            $this->handleCloudMessage($email, $topic, $payload);
        });

        $client->on('disconnect', function() use ($email) {
            $this->logger->warning('Cloud client disconnected', ['email' => $email]);
            // TODO: Reconnect logic in Phase 3
        });

        $client->on('error', function($error) use ($email) {
            $this->logger->error('Cloud client error', [
                'email' => $email,
                'error' => $error->getMessage()
            ]);
        });
    }

    private function connectBroker(): void
    {
        $host = $this->config['mosquitto']['host'];
        $port = $this->config['mosquitto']['port'];
        $clientId = $this->config['mosquitto']['client_id'] ?? 'fossibot_bridge';

        $this->logger->info('Connecting to local broker', [
            'host' => $host,
            'port' => $port
        ]);

        $this->brokerClient = new MqttClient($host, $port, $clientId);

        $settings = (new ConnectionSettings)
            ->setKeepAliveInterval(60)
            ->setUseTls(false);

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
    }

    private function handleCloudMessage(string $accountEmail, string $topic, string $payload): void
    {
        try {
            // Extract MAC
            $mac = $this->topicTranslator->extractMacFromCloudTopic($topic);
            if ($mac === null) {
                return;
            }

            // Parse Modbus
            $registers = $this->payloadTransformer->parseModbusPayload($payload);
            if (empty($registers)) {
                return;
            }

            // Update state
            $this->stateManager->updateDeviceState($mac, $registers);

            // Get state and convert to JSON
            $state = $this->stateManager->getDeviceState($mac);
            $json = $this->payloadTransformer->stateToJson($state);

            // Publish to broker
            $brokerTopic = $this->topicTranslator->cloudToBroker($topic);
            $this->brokerClient->publish($brokerTopic, $json, 1, true);

            $this->logger->debug('State published', [
                'mac' => $mac,
                'soc' => $state->soc
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error handling cloud message', [
                'topic' => $topic,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleBrokerCommand(string $topic, string $payload): void
    {
        try {
            // Extract MAC
            $mac = $this->topicTranslator->extractMacFromBrokerTopic($topic);
            if ($mac === null) {
                return;
            }

            // Parse JSON command
            $command = $this->payloadTransformer->jsonToCommand($payload);

            // Convert to Modbus
            $modbusPayload = $this->payloadTransformer->commandToModbus($command);

            // Find client responsible for this device
            $client = $this->findClientForDevice($mac);
            if ($client === null) {
                $this->logger->warning('No client found for device', ['mac' => $mac]);
                return;
            }

            // Publish to cloud
            $cloudTopic = $this->topicTranslator->brokerToCloud($topic);
            $client->publish($cloudTopic, $modbusPayload);

            $this->logger->info('Command forwarded to cloud', [
                'mac' => $mac,
                'command' => $command->getDescription()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error handling broker command', [
                'topic' => $topic,
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function findClientForDevice(string $mac): ?AsyncCloudClient
    {
        foreach ($this->cloudClients as $client) {
            foreach ($client->getDevices() as $device) {
                if ($device->getMqttId() === $mac) {
                    return $client;
                }
            }
        }

        return null;
    }

    private function publishAvailability(string $mac, string $status): void
    {
        $topic = "fossibot/$mac/availability";
        $this->brokerClient->publish($topic, $status, 1, true);

        $this->logger->debug('Published availability', [
            'mac' => $mac,
            'status' => $status
        ]);
    }

    private function publishBridgeStatus(string $status = 'online'): void
    {
        $devices = [];

        foreach ($this->cloudClients as $client) {
            foreach ($client->getDevices() as $device) {
                $devices[] = [
                    'id' => $device->getMqttId(),
                    'name' => $device->getDeviceName(),
                    'model' => $device->getModel(),
                    'cloudConnected' => $client->isConnected(),
                    'lastSeen' => date('c')
                ];
            }
        }

        $statusMessage = [
            'status' => $status,
            'version' => '2.0.0',
            'uptime_seconds' => time() - $this->startTime,
            'accounts' => array_map(fn($email) => [
                'email' => $this->maskEmail($email),
                'connected' => $this->cloudClients[$email]->isConnected(),
                'device_count' => count($this->cloudClients[$email]->getDevices())
            ], array_keys($this->cloudClients)),
            'devices' => $devices,
            'timestamp' => date('c')
        ];

        $json = json_encode($statusMessage, JSON_THROW_ON_ERROR);
        $this->brokerClient->publish('fossibot/bridge/status', $json, 1, true);

        $this->logger->debug('Published bridge status');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email);

        if (strlen($local) <= 2) {
            return $email; // Too short to mask meaningfully
        }

        $masked = $local[0] . '***' . $local[strlen($local) - 1];
        return "$masked@$domain";
    }
}
```

**Important Notes:**

1. **Broker Message Loop**: The `php-mqtt/client` for the local Mosquitto broker requires periodic `loop()` calls to process incoming messages. Without this, the bridge would be **deaf to commands** from Home Assistant/Node-RED!

2. **Hybrid Polling Approach**: Unlike `AsyncCloudClient` (pure event-based), the broker connection uses polling because `php-mqtt/client` doesn't integrate with ReactPHP streams. This is acceptable because:
   - Only ONE broker connection (vs. potentially many cloud connections)
   - Local network latency is negligible (<1ms)
   - 100ms polling interval is sufficient for manual commands

3. **Power Metrics**: The JSON payload includes `inputWatts`, `outputWatts`, and `dcInputWatts` as specified in Phase 1.

4. **Email Masking**: Uses the spec from `02-TOPICS-MESSAGES.md`: `j***n@example.com` (first + last char of local part).

**Commit:**
```bash
git add src/Bridge/MqttBridge.php
git commit -m "feat(bridge): Implement MqttBridge with broker message loop"
```

**Deliverable:** ✅ MqttBridge base structure complete with bidirectional communication

---

### Step 2.4: Test End-to-End Message Flow (90 min)

**Test script:** `test_bridge_e2e.php`

```php
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\MqttBridge;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('bridge');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

echo "╔═══════════════════════════════════════════════════════╗\n";
echo "║    MqttBridge End-to-End Integration Test            ║\n";
echo "╚═══════════════════════════════════════════════════════╝\n\n";

// Load config
$configPath = 'config/config.json';

if (!file_exists($configPath)) {
    echo "❌ Config file not found: $configPath\n";
    echo "   Copy config/example.json and add your credentials.\n";
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);

echo "Config loaded:\n";
echo "  Accounts: " . count($config['accounts']) . "\n";
echo "  Broker: {$config['mosquitto']['host']}:{$config['mosquitto']['port']}\n\n";

echo "Starting bridge...\n";
echo "(Press Ctrl+C to stop)\n\n";

$bridge = new MqttBridge($config, $logger);

// Handle Ctrl+C gracefully
pcntl_signal(SIGINT, function() use ($bridge) {
    echo "\n\nShutting down...\n";
    $bridge->shutdown();
});

try {
    $bridge->run(); // Blocks here
} catch (\Exception $e) {
    echo "\n❌ Bridge error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n✅ Bridge stopped cleanly\n";
```

**Run:**
```bash
php test_bridge_e2e.php
```

**In another terminal, test commands:**
```bash
# Subscribe to state updates
mosquitto_sub -h localhost -t 'fossibot/+/state' -v

# Send command
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'

# Check bridge status
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -C 1
```

**Expected behavior:**
1. Bridge connects to all accounts
2. Devices appear in bridge status
3. State updates flow from cloud to broker
4. Commands flow from broker to cloud (device responds)

**Commit:**
```bash
git add test_bridge_e2e.php
git commit -m "test: Add end-to-end bridge integration test"
```

**Deliverable:** ✅ End-to-end flow working

---

## ✅ Phase 2 Completion Checklist

- [ ] TopicTranslator implemented and tested
- [ ] PayloadTransformer implemented and tested
- [ ] MqttBridge multi-account structure complete
- [ ] Cloud client event registration working
- [ ] Local broker connection established
- [ ] Cloud → Broker message flow working (state updates)
- [ ] Broker → Cloud message flow working (commands)
- [ ] Bridge status publishing functional
- [ ] Device availability tracking working
- [ ] All test scripts pass
- [ ] Code committed with clear messages

---

## 🎯 Success Criteria

**Phase 2 is complete when:**

1. `test_topic_translator.php` passes
2. `test_payload_transformer.php` passes
3. `test_bridge_e2e.php` runs without errors
4. State updates visible via `mosquitto_sub -t 'fossibot/+/state'`
5. Commands work: Device responds to MQTT commands
6. Bridge status shows all accounts and devices
7. Multiple accounts work simultaneously

---

## 🐛 Troubleshooting

**Problem:** Bridge can't connect to Mosquitto

**Solution:** Verify Mosquitto is running
```bash
systemctl status mosquitto
```

---

**Problem:** No state updates received

**Solution:**
1. Check AsyncCloudClient event handlers are registered
2. Verify cloud client subscriptions (check logs for SUBACK)
3. Enable DEBUG logging to see incoming MQTT packets

---

**Problem:** Commands don't reach device (CRITICAL!)

**Solution:** This is caused by missing broker message loop!
1. Verify `addPeriodicTimer(0.1, ... brokerClient->loop())` exists in `MqttBridge::run()`
2. Check logs for "Broker message loop error"
3. Test broker subscription manually:
```bash
mosquitto_pub -t 'fossibot/7C2C67AB5F0E/command' -m '{"action":"usb_on"}'
# Should see "Command forwarded to cloud" in logs
```

---

**Problem:** Missing power metrics in JSON

**Solution:** Verify `stateToJson()` includes `inputWatts`, `outputWatts`, `dcInputWatts`

---

## 📚 Next Steps

**Phase 2 complete!** → [06-PHASE-3-RECONNECT.md](06-PHASE-3-RECONNECT.md)

Implement robust reconnection logic and error handling.