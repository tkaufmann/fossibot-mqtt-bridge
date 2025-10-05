# DeviceState Architecture Implementation Plan

## Context & Goal

**Was ist das Problem?**
Aktuell können wir nur Commands an Fossibot F2400 Geräte senden (`$device->usbOn()`), aber wir können den aktuellen Gerätestatus nicht abfragen. Für Smart Home Integration brauchen wir aber: `$device->getSoC()`, `$device->isUsbOn()`, etc.

**Was ist ein Fossibot?**
Eine portable Powerstation (2400Wh Batterie) mit AC/USB/DC Ausgängen und konfigurierbaren Settings (Ladelimits, etc.). Wird über MQTT API gesteuert.

**Smart Home Use Cases:**
- `if ($device->getSoC() < 20%) sendAlert("Battery low");`
- `if ($device->isUsbOn()) logPowerConsumption();`
- Automatische Lastschaltung je nach Batteriestand

**Technische Herausforderung:**
- MQTT Messages kommen als Register-Arrays (Register 5 = SoC, Register 41 = Output States, etc.)
- Consumer will typisierte Properties (`$device->getSoC()` nicht `$registers[5]`)
- State muss persistent gehalten werden (Device kann offline gehen)
- Smart Home System braucht Callbacks bei State-Änderungen

## Architecture Decision Records

**Polling vs Event-Based:** POLLING (API ist für Request-Response designed, nicht Streaming)
**State Storage:** StateManager injected in DeviceFacade (Clean DI, testbar, skalierbar)
**Timestamps:** Ein globaler `lastFullUpdate` pro Device (nicht per Property)
**Properties:** Outputs + Settings (soc, usbOutput, maxChargingCurrent, etc.)
**Updates:** Manuelles Polling + automatische Response Updates
**Callbacks:** Am DeviceFacade registrieren (`$deviceFacade->onStateUpdate($callback)`)

## Implementation Plan

### Phase 1: Core DeviceState Infrastructure

#### Step 1: DeviceState Value Object (5 min)
**File:** `src/Device/DeviceState.php`

**Was implementieren:**
```php
<?php
declare(strict_types=1);

namespace Fossibot\Device;

use DateTime;

/**
 * Represents the current state of a Fossibot device.
 * Contains all readable properties from MQTT register responses.
 */
class DeviceState
{
    // Battery & Power
    public float $soc = 0.0;                    // State of Charge (%)

    // Output States (from Register 41 bitfield)
    public bool $usbOutput = false;             // USB ports on/off
    public bool $acOutput = false;              // AC outlets on/off
    public bool $dcOutput = false;              // DC ports on/off
    public bool $ledOutput = false;             // LED lights on/off

    // Settings (from Registers 20, 66, 67)
    public int $maxChargingCurrent = 0;         // 1-20 Amperes
    public float $dischargeLowerLimit = 0.0;    // 0-100%
    public float $acChargingUpperLimit = 100.0; // 0-100%

    // Metadata
    public DateTime $lastFullUpdate;

    public function __construct()
    {
        $this->lastFullUpdate = new DateTime('1970-01-01'); // "never updated"
    }

    /**
     * Update state from F2400 register array.
     *
     * @param array $registers Modbus registers (index => value)
     */
    public function updateFromRegisters(array $registers): void
    {
        // Battery (Register 5 = SoC)
        if (isset($registers[5])) {
            $this->soc = (float) $registers[5];
        }

        // Output States (Register 41 bitfield)
        if (isset($registers[41])) {
            $bitfield = $registers[41];
            $this->usbOutput = ($bitfield & 0x01) === 1;
            $this->acOutput = ($bitfield & 0x02) === 2;
            $this->dcOutput = ($bitfield & 0x04) === 4;
            $this->ledOutput = ($bitfield & 0x08) === 8;
        }

        // Settings
        if (isset($registers[20])) {
            $this->maxChargingCurrent = $registers[20];
        }
        if (isset($registers[66])) {
            $this->dischargeLowerLimit = $registers[66] / 10.0; // Tenths to percentage
        }
        if (isset($registers[67])) {
            $this->acChargingUpperLimit = $registers[67] / 10.0; // Tenths to percentage
        }

        $this->lastFullUpdate = new DateTime();
    }

    /**
     * Check if state data is fresh (not older than threshold).
     */
    public function isFresh(int $maxAgeSeconds = 300): bool
    {
        $age = time() - $this->lastFullUpdate->getTimestamp();
        return $age <= $maxAgeSeconds;
    }
}
```

**Wie testen:**
```php
// Test: Object creation
$state = new DeviceState();
assert($state->soc === 0.0);
assert($state->lastFullUpdate->format('Y') === '1970');

// Test: Register update
$registers = [5 => 85, 41 => 3, 20 => 12]; // SoC=85%, USB+AC on, 12A charging
$state->updateFromRegisters($registers);
assert($state->soc === 85.0);
assert($state->usbOutput === true);
assert($state->acOutput === true);
assert($state->dcOutput === false);
assert($state->maxChargingCurrent === 12);
assert($state->isFresh());
```

#### Step 2: DeviceStateManager (10 min)
**File:** `src/Device/DeviceStateManager.php`

**Was implementieren:**
```php
<?php
declare(strict_types=1);

namespace Fossibot\Device;

/**
 * Manages DeviceState instances for multiple devices.
 * Central registry for all device states with callback support.
 */
class DeviceStateManager
{
    private array $deviceStates = [];    // macAddress => DeviceState
    private array $callbacks = [];       // macAddress => callable[]

    /**
     * Get DeviceState for a MAC address.
     * Creates new instance if not exists.
     */
    public function getDeviceState(string $macAddress): DeviceState
    {
        if (!isset($this->deviceStates[$macAddress])) {
            $this->deviceStates[$macAddress] = new DeviceState();
        }

        return $this->deviceStates[$macAddress];
    }

    /**
     * Update device state from MQTT registers and trigger callbacks.
     */
    public function updateDeviceState(string $macAddress, array $registers): void
    {
        $state = $this->getDeviceState($macAddress);
        $state->updateFromRegisters($registers);

        // Trigger callbacks for this device
        if (isset($this->callbacks[$macAddress])) {
            foreach ($this->callbacks[$macAddress] as $callback) {
                $callback($state);
            }
        }
    }

    /**
     * Register callback for device state changes.
     */
    public function onDeviceUpdate(string $macAddress, callable $callback): void
    {
        if (!isset($this->callbacks[$macAddress])) {
            $this->callbacks[$macAddress] = [];
        }

        $this->callbacks[$macAddress][] = $callback;
    }

    /**
     * Get all managed device MAC addresses.
     */
    public function getManagedDevices(): array
    {
        return array_keys($this->deviceStates);
    }
}
```

**Wie testen:**
```php
// Test: Get device state
$manager = new DeviceStateManager();
$state = $manager->getDeviceState('ABC123');
assert($state instanceof DeviceState);
assert($state->soc === 0.0);

// Test: Update triggers callback
$callbackFired = false;
$manager->onDeviceUpdate('ABC123', function($state) use (&$callbackFired) {
    $callbackFired = true;
    assert($state->soc === 75.0);
});

$manager->updateDeviceState('ABC123', [5 => 75]); // SoC = 75%
assert($callbackFired === true);
assert($manager->getManagedDevices() === ['ABC123']);
```

#### Step 3: DeviceFacade State API Extension (5 min)
**File:** `src/Device/DeviceFacade.php` (modify existing)

**Was hinzufügen:**
```php
// Add to existing DeviceFacade constructor:
public function __construct(
    private readonly Device $device,
    private readonly CommandExecutor $executor,
    private readonly DeviceStateManager $stateManager // NEW PARAMETER
) {}

// Add state query methods:

/**
 * Get current battery State of Charge.
 */
public function getSoC(): float
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->soc;
}

/**
 * Check if USB output is currently active.
 */
public function isUsbOutputActive(): bool
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->usbOutput;
}

/**
 * Check if AC output is currently active.
 */
public function isAcOutputActive(): bool
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->acOutput;
}

/**
 * Check if DC output is currently active.
 */
public function isDcOutputActive(): bool
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->dcOutput;
}

/**
 * Check if LED output is currently active.
 */
public function isLedOutputActive(): bool
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->ledOutput;
}

/**
 * Get current maximum charging current setting.
 */
public function getCurrentMaxChargingCurrent(): int
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->maxChargingCurrent;
}

/**
 * Get current discharge lower limit setting.
 */
public function getCurrentDischargeLowerLimit(): float
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->dischargeLowerLimit;
}

/**
 * Get current AC charging upper limit setting.
 */
public function getCurrentAcChargingUpperLimit(): float
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->acChargingUpperLimit;
}

/**
 * Manually refresh device state by sending read command.
 */
public function refreshState(): void
{
    $this->readSettings(); // Uses existing readSettings command
}

/**
 * Register callback for state updates of this device.
 */
public function onStateUpdate(callable $callback): void
{
    $this->stateManager->onDeviceUpdate($this->getMqttId(), $callback);
}

/**
 * Check if cached state is fresh (not stale).
 */
public function isStateFresh(int $maxAgeSeconds = 300): bool
{
    $state = $this->stateManager->getDeviceState($this->getMqttId());
    return $state->isFresh($maxAgeSeconds);
}
```

**Wie testen:**
```php
// Test: Default state
$stateManager = new DeviceStateManager();
$facade = new DeviceFacade($device, $queueManager, $stateManager);
assert($facade->getSoC() === 0.0);
assert($facade->isUsbOutputActive() === false);

// Test: State update
$stateManager->updateDeviceState($facade->getMqttId(), [5 => 42, 41 => 1]);
assert($facade->getSoC() === 42.0);
assert($facade->isUsbOutputActive() === true);

// Test: Callback
$callbackCalled = false;
$facade->onStateUpdate(function($state) use (&$callbackCalled) {
    $callbackCalled = true;
});
$stateManager->updateDeviceState($facade->getMqttId(), [5 => 50]);
assert($callbackCalled === true);
```

### Phase 2: Response Integration

#### Step 4: Connect StateManager to Response Flow (15 min)
**Files:** `src/Connection.php` (modify existing ResponseListener)

**Was ändern:**
In `Connection::setupResponseCallback()` - die anonyme ResponseListener Klasse erweitern:

```php
// In setupResponseCallback method, modify the anonymous class:
$responseListener = new class($this->logger, $this->stateManager) implements ResponseListener {
    private LoggerInterface $logger;
    private Connection $connection;
    private ?DeviceStateManager $stateManager; // NEW

    public function __construct(LoggerInterface $logger, ?DeviceStateManager $stateManager = null) {
        $this->logger = $logger;
        $this->stateManager = $stateManager;
    }

    public function onResponse(string $topic, array $registers, string $macAddress): void {
        // Update StateManager FIRST
        if ($this->stateManager) {
            $this->stateManager->updateDeviceState($macAddress, $registers);
            $this->logger->debug('DeviceState updated from MQTT response', [
                'mac' => $macAddress,
                'topic' => $topic,
                'register_count' => count($registers)
            ]);
        }

        // THEN do existing logic (for backwards compatibility)
        if (isset($this->connection)) {
            $payload = $this->registersToPayload($registers);
            $this->connection->handleMqttMessage($topic, $payload);
        }
    }

    // ... rest unchanged
};

// Pass StateManager to ResponseListener
$responseListener->setConnection($this);
$client->addResponseListener($responseListener);
```

**Was in setupResponseSubscriptions() ändern:**
```php
// Add StateManager parameter to setupResponseSubscriptions
private function setupResponseSubscriptions(MqttWebSocketClient $client, ?DeviceStateManager $stateManager = null): void
{
    // ... existing code ...

    // Pass StateManager to response callback
    $this->setupResponseCallback($client, $stateManager);
}

// Update s4_connectMqtt call
private function s4_connectMqtt(?DeviceStateManager $stateManager = null): MqttWebSocketClient
{
    // ... existing code until setupResponseSubscriptions call ...

    // Pass StateManager down
    $this->setupResponseSubscriptions($client, $stateManager);
}
```

**Wie testen:**
```php
// Test: StateManager gets updated on MQTT response
$stateManager = new DeviceStateManager();
$connection = new Connection($email, $password, $logger);

// Mock: Send fake MQTT response
$registers = [5 => 88, 41 => 5]; // SoC=88%, USB+DC on
$connection->simulateMqttResponse('ABC123/device/response/client/data', $registers, 'ABC123');

// Verify StateManager was updated
$state = $stateManager->getDeviceState('ABC123');
assert($state->soc === 88.0);
assert($state->usbOutput === true);
assert($state->dcOutput === true);
```

#### Step 5: QueueManager StateManager Integration (10 min)
**File:** `src/Queue/QueueManager.php` (modify existing)

**Was ändern in addConnection():**
```php
public function addConnection(string $email, string $password, ?DeviceStateManager $stateManager = null): void
{
    // ... existing validation ...

    try {
        // Create and authenticate connection
        $connection = new \Fossibot\Connection($email, $password, $this->logger);
        $connection->connect($stateManager); // Pass StateManager to connect()

        // ... rest unchanged ...
    }
}
```

**Was in Connection->connect() ändern:**
```php
// In Connection.php:
public function connect(?DeviceStateManager $stateManager = null): void {
    // ... existing stages 1-3 unchanged ...

    $this->mqttClient = $this->s4_connectMqtt($stateManager); // Pass down
    $this->logger->info('Stage 4 completed: MQTT WebSocket connected');
}
```

**Consumer Usage wird:**
```php
// Option 1: Automatic StateManager
$queueManager = QueueManager::getInstance($logger);
$queueManager->addConnection($email, $password); // Creates default StateManager

// Option 2: Custom StateManager
$stateManager = new DeviceStateManager();
$queueManager->addConnection($email, $password, $stateManager);

// DeviceFacade creation unchanged
$devices = $queueManager->getRegisteredDevices();
$deviceFacade = new DeviceFacade($devices[0], $queueManager, $stateManager);
```

### Phase 3: Testing & Validation

#### Step 6: End-to-End Test Script (15 min)
**File:** `test_device_state.php`

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Fossibot\Device\DeviceState;
use Fossibot\Device\DeviceStateManager;
use Fossibot\Device\DeviceFacade;
use Fossibot\Queue\QueueManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !str_starts_with($line, '#')) {
            putenv($line);
        }
    }
}

$email = getenv('FOSSIBOT_EMAIL');
$password = getenv('FOSSIBOT_PASSWORD');

echo "🧪 DeviceState End-to-End Test\n";
echo "==============================\n\n";

// Setup
$logger = new Logger('state-test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$stateManager = new DeviceStateManager();
$queueManager = QueueManager::getInstance($logger);

echo "📡 Connecting with StateManager...\n";
$queueManager->addConnection($email, $password, $stateManager);

$registeredMacs = $queueManager->getRegisteredMacs();
$macAddress = $registeredMacs[0];

// Create DeviceFacade
$deviceData = [
    'device_id' => $macAddress,
    'device_name' => 'F2400 State Test',
    'product_id' => 'test',
    'model' => 'F2400',
    'mqtt_state' => 1,
    'created_at' => '2024-01-01T00:00:00Z'
];

$device = \Fossibot\Device\Device::fromApiResponse($deviceData);
$deviceFacade = new DeviceFacade($device, $queueManager, $stateManager);

echo "✅ Device: {$macAddress}\n\n";

// Test callback registration
echo "📞 Registering state update callback...\n";
$deviceFacade->onStateUpdate(function($state) {
    echo "🔔 State updated! SoC: {$state->soc}%, USB: " . ($state->usbOutput ? 'ON' : 'OFF') . "\n";
});

// Test initial state (should be defaults)
echo "📊 Initial state:\n";
echo "   SoC: " . $deviceFacade->getSoC() . "%\n";
echo "   USB: " . ($deviceFacade->isUsbOutputActive() ? 'ON' : 'OFF') . "\n";
echo "   Fresh: " . ($deviceFacade->isStateFresh() ? 'YES' : 'NO') . "\n\n";

// Test manual refresh
echo "🔄 Refreshing state...\n";
$deviceFacade->refreshState(); // Sends readSettings command
echo "⏱️  Waiting 10 seconds for response...\n";
sleep(10);

echo "📊 Refreshed state:\n";
echo "   SoC: " . $deviceFacade->getSoC() . "%\n";
echo "   USB: " . ($deviceFacade->isUsbOutputActive() ? 'ON' : 'OFF') . "\n";
echo "   AC: " . ($deviceFacade->isAcOutputActive() ? 'ON' : 'OFF') . "\n";
echo "   Max Charging: " . $deviceFacade->getCurrentMaxChargingCurrent() . "A\n";
echo "   Discharge Limit: " . $deviceFacade->getCurrentDischargeLowerLimit() . "%\n";
echo "   AC Charge Limit: " . $deviceFacade->getCurrentAcChargingUpperLimit() . "%\n";
echo "   Fresh: " . ($deviceFacade->isStateFresh() ? 'YES' : 'NO') . "\n\n";

// Test command -> state update
echo "🔌 Sending USB ON command (should trigger callback)...\n";
$deviceFacade->usbOn();
sleep(3);

echo "📊 State after command:\n";
echo "   USB: " . ($deviceFacade->isUsbOutputActive() ? 'ON' : 'OFF') . "\n\n";

echo "✅ DeviceState test completed!\n";
```

## Success Criteria

Nach erfolgreicher Implementation:

1. **✅ State Queries funktionieren:**
   ```php
   $soc = $deviceFacade->getSoC();        // Returns actual battery %
   $isOn = $deviceFacade->isUsbOutputActive(); // Returns true/false
   ```

2. **✅ Callbacks funktionieren:**
   ```php
   $deviceFacade->onStateUpdate(function($state) {
       echo "SoC changed to: {$state->soc}%";
   });
   // Callback fires when MQTT response arrives
   ```

3. **✅ Manual Refresh funktioniert:**
   ```php
   $deviceFacade->refreshState(); // Triggers MQTT request
   // State updated when response arrives
   ```

4. **✅ Stale Detection funktioniert:**
   ```php
   $isFresh = $deviceFacade->isStateFresh(300); // 5 minutes threshold
   ```

5. **✅ Hardware Integration:**
   - Real commands trigger state updates
   - Real MQTT responses update cached state
   - Smart Home callbacks fire on actual device changes

## Common Pitfalls & Solutions

**Problem:** DeviceFacade constructor breaks existing code
**Solution:** Update existing test scripts to pass StateManager parameter

**Problem:** Response integration doesn't trigger
**Solution:** Verify StateManager is passed through entire connection chain: QueueManager → Connection → ResponseListener

**Problem:** State never updates
**Solution:** Check MQTT topic subscriptions are working, verify register parsing in DeviceState::updateFromRegisters()

**Problem:** Callbacks don't fire
**Solution:** Verify StateManager::updateDeviceState() is called from ResponseListener

**Problem:** Fresh state detection fails
**Solution:** Ensure DateTime is updated in DeviceState::updateFromRegisters()

## Next Steps After Implementation

1. **Smart Home Integration Script** - Create script that polls device state and calls Smart Home APIs
2. **Web Dashboard** - Simple HTML page showing real-time device states
3. **Alert System** - Email/SMS notifications for critical SoC levels
4. **Multi-Device Support** - Test with multiple Fossibot devices
5. **Performance Optimization** - Batch updates, connection pooling