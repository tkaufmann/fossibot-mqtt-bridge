# 03 - Phase 0: Setup & Cleanup

**Phase:** 0 - Preparation
**Effort:** ~3 hours
**Prerequisites:** None
**Deliverables:** Clean codebase, ReactPHP dependencies installed, Mosquitto running

---

## 🎯 Phase Goals

1. Remove legacy synchronous code (clean slate)
2. Install ReactPHP ecosystem dependencies
3. Setup config system for multi-account
4. Install and verify Mosquitto broker
5. Create bridge component structure

---

## 📋 Step-by-Step Implementation

### Step 0.1: Remove Legacy Code (30 min)

**⚠️ DO THIS FIRST** to avoid conflicts with test scripts created in later steps.

**Files to delete:**
```bash
# Remove legacy directories
rm -rf src/Queue/
rm -rf src/Contracts/
rm -rf src/Parsing/

# Remove legacy classes
rm src/Device/DeviceFacade.php

# Check for old test scripts
ls test_*.php 2>/dev/null
# If old test scripts exist, delete them EXPLICITLY by name:
# rm test_old_connection.php test_old_queue.php ...
```

**Verify removal:**
```bash
git status
```

**Expected:**
```
deleted: src/Queue/QueueManager.php
deleted: src/Queue/ConnectionQueue.php
deleted: src/Contracts/CommandExecutor.php
deleted: src/Contracts/ResponseListener.php
deleted: src/Device/DeviceFacade.php
deleted: src/Parsing/ModbusResponseParser.php
```

**Update composer autoload:**
```bash
composer dump-autoload
```

**Verify no syntax errors:**
```bash
php -l src/Connection.php
php -l src/Device/Device.php
php -l src/Device/DeviceState.php
```

**Commit:**
```bash
git add -A
git commit -m "refactor: Remove legacy queue, facade, and parser components"
```

**Deliverable:** ✅ Legacy code removed, codebase clean

---

### Step 0.2: Install ReactPHP Dependencies (30 min)

**Add dependencies to composer.json:**

```bash
composer require react/event-loop:^1.5
composer require react/socket:^1.15
composer require react/promise:^3.2
composer require ratchet/pawl:^0.4
composer require php-mqtt/client:^2.1
composer require evenement/evenement:^3.0
```

**Verify installation:**
```bash
composer install
php -r "use React\EventLoop\Loop; echo 'ReactPHP OK';"
```

**Expected output:**
```
ReactPHP OK
```

**Test script:** `test_react_installation.php`
```php
<?php
require 'vendor/autoload.php';

use React\EventLoop\Loop;

echo "Testing ReactPHP installation...\n";

$loop = Loop::get();

$loop->addTimer(1.0, function() {
    echo "✅ Timer executed after 1 second\n";
    echo "✅ ReactPHP event loop is working!\n";
});

echo "Starting event loop...\n";
$loop->run();
```

**Run:**
```bash
php test_react_installation.php
```

**Expected:**
```
Testing ReactPHP installation...
Starting event loop...
✅ Timer executed after 1 second
✅ ReactPHP event loop is working!
```

**Commit:**
```bash
git add composer.json composer.lock test_react_installation.php
git commit -m "feat(deps): Add ReactPHP ecosystem dependencies"
```

**Deliverable:** ✅ ReactPHP dependencies installed and verified

---

### Step 0.3: Create Config System (45 min)

**Create directory structure:**
```bash
mkdir -p config
```

**File:** `config/example.json`

```json
{
  "accounts": [
    {
      "email": "user@example.com",
      "password": "your-password-here",
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

**File:** `config/README.md`

```markdown
# Configuration

## Setup

1. Copy example config:
   ```bash
   cp config/example.json config/config.json
   ```

2. Edit `config/config.json`:
   - Add your Fossibot account credentials
   - See `.env` for working test credentials
   - Multiple accounts: add more objects to `accounts` array

3. Test config:
   ```bash
   php test_config_load.php
   ```

## Development

For development, use credentials from `.env`:
```bash
FOSSIBOT_EMAIL=your-email@example.com
FOSSIBOT_PASSWORD=your-password
```

You can copy these into `config/config.json`.

## Configuration Options

### accounts

Array of Fossibot account credentials.

- `email` (string, required): Fossibot account email
- `password` (string, required): Fossibot account password
- `enabled` (bool, optional): Set to false to disable account (default: true)

### mosquitto

Local MQTT broker connection settings.

- `host` (string): Broker hostname (default: localhost)
- `port` (int): Broker port (default: 1883)
- `username` (string|null): Auth username (null = no auth)
- `password` (string|null): Auth password
- `client_id` (string): MQTT client ID (default: fossibot_bridge)

### daemon

Daemon process settings.

- `log_file` (string): Path to log file
- `log_level` (string): Log level (debug, info, warning, error)

### bridge

Bridge behavior settings.

- `status_publish_interval` (int): Seconds between status publishes (default: 60)
- `reconnect_delay_min` (int): Initial reconnect delay in seconds (default: 5)
- `reconnect_delay_max` (int): Maximum reconnect delay in seconds (default: 60)

## Security

⚠️ **IMPORTANT:** Keep `config/config.json` private!
- Contains passwords in plaintext
- Add to `.gitignore` (already done)
- Use restrictive file permissions: `chmod 600 config/config.json`
```

**Update `.gitignore`:**
```bash
echo "config/config.json" >> .gitignore
echo "logs/" >> .gitignore
```

**Test script:** `test_config_load.php`
```php
<?php
require 'vendor/autoload.php';

$configPath = 'config/example.json';

echo "Testing config loading...\n";
echo "Config file: $configPath\n\n";

if (!file_exists($configPath)) {
    echo "❌ Config file not found\n";
    exit(1);
}

$json = file_get_contents($configPath);
$config = json_decode($json, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Invalid JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "✅ JSON valid\n";

// Validate structure
$errors = [];

if (empty($config['accounts'])) {
    $errors[] = "Missing 'accounts' array";
}

foreach ($config['accounts'] as $i => $account) {
    if (empty($account['email'])) {
        $errors[] = "Account $i missing email";
    }
    if (empty($account['password'])) {
        $errors[] = "Account $i missing password";
    }
}

if (empty($config['mosquitto']['host'])) {
    $errors[] = "Missing mosquitto.host";
}

if (!empty($errors)) {
    echo "❌ Validation errors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    exit(1);
}

echo "✅ Config structure valid\n";
echo "\nAccounts configured: " . count($config['accounts']) . "\n";
echo "Mosquitto broker: {$config['mosquitto']['host']}:{$config['mosquitto']['port']}\n";
echo "Log level: {$config['daemon']['log_level']}\n";

echo "\n✅ Config test passed!\n";
```

**Run:**
```bash
php test_config_load.php
```

**Commit:**
```bash
git add config/ .gitignore test_config_load.php
git commit -m "feat(config): Add JSON config system with multi-account support"
```

**Deliverable:** ✅ Config system functional

---

### Step 0.4: Install and Test Mosquitto (20 min)

**macOS:**
```bash
brew install mosquitto
brew services start mosquitto
```

**Linux (Ubuntu/Debian):**
```bash
sudo apt-get update
sudo apt-get install mosquitto mosquitto-clients
sudo systemctl enable mosquitto
sudo systemctl start mosquitto
```

**Windows:**
1. Download installer from [mosquitto.org/download](https://mosquitto.org/download/)
2. Run installer (select "Service" option during installation)
3. Mosquitto runs as Windows Service automatically
4. Verify in Services app (`services.msc`) - "Mosquitto Broker" should be "Running"

**Alternative (All Platforms):** Docker
```bash
docker run -d -p 1883:1883 --name mosquitto eclipse-mosquitto:2
```

**Verify Mosquitto:**
```bash
# Check service
brew services list | grep mosquitto  # macOS
# or
systemctl status mosquitto           # Linux

# Test pub/sub
# Terminal 1:
mosquitto_sub -h localhost -t 'test/topic' -v

# Terminal 2:
mosquitto_pub -h localhost -t 'test/topic' -m 'Hello MQTT'
```

**Expected in Terminal 1:**
```
test/topic Hello MQTT
```

**Test script:** `test_mosquitto.php`
```php
<?php
require 'vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "Testing Mosquitto connection...\n";

try {
    $mqtt = new MqttClient('localhost', 1883, 'test_client_' . uniqid());
    $connectionSettings = (new ConnectionSettings)
        ->setConnectTimeout(3)
        ->setUseTls(false);

    $mqtt->connect($connectionSettings);
    echo "✅ Connected to Mosquitto\n";

    // Publish test message
    $mqtt->publish('test/topic', 'Test from PHP', 0);
    echo "✅ Published test message\n";

    $mqtt->disconnect();
    echo "✅ Disconnected cleanly\n";

    echo "\n✅ Mosquitto test passed!\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
```

**Run:**
```bash
php test_mosquitto.php
```

**Commit:**
```bash
git add test_mosquitto.php
git commit -m "test: Add Mosquitto connection test"
```

**Deliverable:** ✅ Mosquitto running and accessible

---

### Step 0.5: Create Bridge Directory Structure (10 min)

**Create directories:**
```bash
mkdir -p src/Bridge
mkdir -p daemon
mkdir -p logs
```

**Create placeholder files with PHPDoc:**

**File:** `src/Bridge/AsyncCloudClient.php`
```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Async MQTT client for Fossibot Cloud connection.
 *
 * Connects to Fossibot Cloud via MQTT over WebSocket using ReactPHP.
 * Emits events for messages, connection status, and errors.
 * One instance per Fossibot account.
 */
class AsyncCloudClient
{
    // TODO: Implementation in Phase 1
}
```

**File:** `src/Bridge/MqttBridge.php`
```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * MQTT Bridge orchestrator with ReactPHP event loop.
 *
 * Manages multiple AsyncCloudClient instances (multi-account support).
 * Routes messages between Fossibot Cloud and local Mosquitto broker.
 * Handles state management and reconnection logic.
 */
class MqttBridge
{
    // TODO: Implementation in Phase 2
}
```

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
    // TODO: Implementation in Phase 2
}
```

**File:** `src/Bridge/PayloadTransformer.php`
```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Transforms MQTT payloads between Modbus binary and JSON.
 *
 * Modbus → JSON: Parse registers, build DeviceState, serialize JSON
 * JSON → Modbus: Parse command JSON, build Command, generate Modbus bytes
 */
class PayloadTransformer
{
    // TODO: Implementation in Phase 2
}
```

**File:** `daemon/fossibot-bridge.php`
```bash
#!/usr/bin/env php
<?php
// ABOUTME: CLI entry point for Fossibot MQTT Bridge daemon
// Loads config, initializes bridge, runs event loop

declare(strict_types=1);

// TODO: Implementation in Phase 4
echo "Fossibot Bridge - Not yet implemented\n";
```

**Make executable:**
```bash
chmod +x daemon/fossibot-bridge.php
```

**Test structure:**
```bash
tree src/Bridge daemon
```

**Expected:**
```
src/Bridge/
├── AsyncCloudClient.php
├── MqttBridge.php
├── PayloadTransformer.php
└── TopicTranslator.php

daemon/
└── fossibot-bridge.php
```

**Update composer autoload:**
```bash
composer dump-autoload
```

**Commit:**
```bash
git add src/Bridge/ daemon/
git commit -m "feat(bridge): Create bridge component directory structure"
```

**Deliverable:** ✅ Directory structure ready

---

### Step 0.6: Setup Test Infrastructure (15 min)

**⚠️ IMPORTANT:** Set up the test infrastructure NOW, before implementing new components. This allows you to write tests as you implement each phase, following test-driven development principles.

**Install PHPUnit:**
```bash
composer require --dev phpunit/phpunit ^10.5
```

**Create directory structure:**
```bash
mkdir -p tests/{Unit,Integration,System}
```

**File:** `tests/bootstrap.php`

```php
<?php
// ABOUTME: Test suite bootstrap - loads autoloader and test utilities

declare(strict_types=1);

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load .env for credentials
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Test helpers
function getTestEmail(): string
{
    $email = getenv('FOSSIBOT_EMAIL');
    if (empty($email)) {
        throw new \RuntimeException('FOSSIBOT_EMAIL not set in .env');
    }
    return $email;
}

function getTestPassword(): string
{
    $password = getenv('FOSSIBOT_PASSWORD');
    if (empty($password)) {
        throw new \RuntimeException('FOSSIBOT_PASSWORD not set in .env');
    }
    return $password;
}

function createTestLogger(): \Psr\Log\LoggerInterface
{
    $logger = new \Monolog\Logger('test');
    $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::DEBUG));
    return $logger;
}
```

**File:** `phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         verbose="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="System">
            <directory>tests/System</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
    </php>
</phpunit>
```

**Verify test infrastructure:**
```bash
# Should show empty test suites (no tests yet)
vendor/bin/phpunit --list-suites
```

**Expected output:**
```
Available test suite(s):
 - Unit
 - Integration
 - System
```

**Commit:**
```bash
git add tests/ phpunit.xml
git commit -m "test: Add test infrastructure and PHPUnit config"
```

**Deliverable:** ✅ Test infrastructure ready for use

**📖 Note:** For detailed testing strategy and philosophy, see `docs/daemon/09-TESTING.md`. Write tests for each component as you implement it in Phases 1-4.

---

### Step 0.7: Verify Existing Components (15 min)

**Test existing components still work:**

**Script:** `test_existing_components.php`
```php
<?php
require 'vendor/autoload.php';

use Fossibot\Connection;
use Fossibot\Device\DeviceState;
use Fossibot\Device\DeviceStateManager;
use Fossibot\Commands\UsbOutputCommand;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

echo "Testing existing components...\n\n";

// Test 1: DeviceState
echo "Test 1: DeviceState\n";
$state = new DeviceState();
$state->updateFromRegisters([56 => 855, 41 => 64]);
assert($state->soc === 85.5, "SoC should be 85.5");
assert($state->usbOutput === true, "USB should be on");
echo "✅ DeviceState works\n\n";

// Test 2: DeviceStateManager
echo "Test 2: DeviceStateManager\n";
$manager = new DeviceStateManager();
$manager->updateDeviceState('7C2C67AB5F0E', [56 => 900]);
$state = $manager->getDeviceState('7C2C67AB5F0E');
assert($state->soc === 90.0, "SoC should be 90.0");
echo "✅ DeviceStateManager works\n\n";

// Test 3: Commands
echo "Test 3: Commands\n";
$command = new UsbOutputCommand(true);
assert($command->getDescription() === 'Turn USB output on', "Description correct");
$bytes = $command->getModbusBytes();
assert(count($bytes) === 8, "Should have 8 bytes");
echo "✅ Commands work\n\n";

// Test 4: Connection (requires credentials)
echo "Test 4: Connection (skipped - requires credentials)\n";
echo "   Note: Connection class will be tested in Phase 1\n\n";

echo "✅ All existing component tests passed!\n";
```

**Run:**
```bash
php test_existing_components.php
```

**Expected:**
```
Testing existing components...

Test 1: DeviceState
✅ DeviceState works

Test 2: DeviceStateManager
✅ DeviceStateManager works

Test 3: Commands
✅ Commands work

Test 4: Connection (skipped - requires credentials)
   Note: Connection class will be tested in Phase 1

✅ All existing component tests passed!
```

**Commit:**
```bash
git add test_existing_components.php
git commit -m "test: Verify existing components still functional"
```

**Deliverable:** ✅ Existing code verified working

---

## ✅ Phase 0 Completion Checklist

- [x] Legacy code deleted (Queue, Facade, Contracts, Parsing) - **Step 0.1**
- [x] ReactPHP dependencies installed and tested - **Step 0.2**
- [x] Config system created with `example.json` - **Step 0.3**
- [x] `.gitignore` updated for `config.json` and `logs/` - **Step 0.3**
- [x] Mosquitto installed and running - **Step 0.4**
- [x] Bridge directory structure created - **Step 0.5**
- [x] Test infrastructure setup (PHPUnit, tests/ directories) - **Step 0.6**
- [x] Existing components verified working - **Step 0.7**
- [x] All test scripts pass
- [x] All commits made with proper messages

---

## 🎯 Success Criteria

**Phase 0 is complete when:**

1. No legacy code remains in repository
2. `composer install` runs without errors
3. `test_react_installation.php` passes
4. `test_config_load.php` passes
5. `test_mosquitto.php` passes
6. PHPUnit test infrastructure is set up (`vendor/bin/phpunit --list-suites` works)
7. `test_existing_components.php` passes
8. `src/Bridge/` directory exists with placeholder files
9. Git history is clean with descriptive commits

---

## 🐛 Troubleshooting

**Problem:** `composer install` fails for `php-mqtt/client`

**Solution:** Check PHP version (requires 8.1+)
```bash
php -v
```

---

**Problem:** Mosquitto fails to start

**Solution:** Check port 1883 is not in use
```bash
lsof -i :1883
```

If port is used, kill process or configure different port in `config.json`.

---

**Problem:** `test_mosquitto.php` fails with connection timeout

**Solution:** Verify Mosquitto is running
```bash
brew services list | grep mosquitto  # macOS
systemctl status mosquitto           # Linux
```

---

## 📚 Next Steps

**Phase 0 complete!** → [04-PHASE-1-CLIENT.md](04-PHASE-1-CLIENT.md)

Begin implementing AsyncCloudClient with Pawl + php-mqtt/client.