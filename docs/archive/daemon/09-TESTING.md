# 09 - Testing Strategy

**Document:** Complete testing strategy for Fossibot MQTT Bridge
**Audience:** Developers, QA engineers
**Reading Time:** ~15 minutes

---

## 🎯 Testing Philosophy

### Core Principles

1. **No Mocking** - Test against real Fossibot Cloud API
2. **E2E Focus** - Prioritize end-to-end integration tests
3. **Real Hardware** - Verify with actual devices when possible
4. **Fast Feedback** - Tests should complete in <30 seconds
5. **Reproducible** - Tests must be repeatable and deterministic

### Why No Mocking?

**Problem with mocks:**
- API changes silently break integration
- Mock behavior diverges from reality
- False confidence in test coverage

**Real API testing:**
- Catches API changes immediately
- Tests actual behavior
- Real authentication flow
- Real MQTT protocol

**Tradeoff:** Tests require valid credentials and network access.

---

## 📋 Test Categories

### 1. Unit Tests (Synchronous Components)

**What to test:**
- TopicTranslator
- PayloadTransformer
- DeviceState
- Command classes
- Config validation

**Why unit tests here:**
- No I/O operations
- Pure functions
- Fast execution (<1ms per test)

**Test framework:** PHPUnit

---

### 2. Integration Tests (Async Components)

**What to test:**
- AsyncCloudClient connection
- MqttBridge orchestration
- Reconnect logic
- Token refresh
- End-to-end flows

**Why integration tests:**
- ReactPHP event loop complex to mock
- Real async behavior matters
- Network timing issues
- Token expiry scenarios

**Test framework:** Custom test scripts with assertions

---

### 3. System Tests (Complete Daemon)

**What to test:**
- CLI argument parsing
- Config loading
- Daemon startup/shutdown
- Signal handlers
- Multi-account support

**Why system tests:**
- Process lifecycle
- systemd integration
- Real-world deployment

**Test framework:** Bash scripts + PHP scripts

---

## 🧪 Test Suite Structure

```
tests/
├── Unit/
│   ├── TopicTranslatorTest.php
│   ├── PayloadTransformerTest.php
│   ├── DeviceStateTest.php
│   └── CommandsTest.php
├── Integration/
│   ├── AsyncCloudClientTest.php
│   ├── MqttBridgeTest.php
│   ├── ReconnectTest.php
│   └── MultiAccountTest.php
├── System/
│   ├── DaemonLifecycleTest.php
│   └── SystemdIntegrationTest.sh
└── bootstrap.php
```

---

## 📋 Implementation Guide

### Prerequisites

**⚠️ IMPORTANT:** The test infrastructure (PHPUnit, `tests/` directories, `phpunit.xml`) must be set up in **Phase 0, Step 0.6** before starting with test implementation. If you haven't completed Phase 0 yet, do that first.

This document assumes:
- PHPUnit is installed (`composer require --dev phpunit/phpunit ^10.5`)
- Test directories exist (`tests/Unit/`, `tests/Integration/`, `tests/System/`)
- `tests/bootstrap.php` is created with helper functions
- `phpunit.xml` is configured

See `03-PHASE-0-SETUP.md` Step 0.6 for setup instructions.

---

### Step 1: Unit Tests (30 min)

**File:** `tests/Unit/TopicTranslatorTest.php`

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fossibot\Bridge\TopicTranslator;

class TopicTranslatorTest extends TestCase
{
    private TopicTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new TopicTranslator();
    }

    public function testCloudToBrokerStateTranslation(): void
    {
        $cloudTopic = '7C2C67AB5F0E/device/response/client/04';
        $expected = 'fossibot/7C2C67AB5F0E/state';

        $result = $this->translator->cloudToBroker($cloudTopic);

        $this->assertEquals($expected, $result);
    }

    public function testCloudToBrokerDataTranslation(): void
    {
        $cloudTopic = '7C2C67AB5F0E/device/response/client/data';
        $expected = 'fossibot/7C2C67AB5F0E/state';

        $result = $this->translator->cloudToBroker($cloudTopic);

        $this->assertEquals($expected, $result);
    }

    public function testBrokerToCloudCommandTranslation(): void
    {
        $brokerTopic = 'fossibot/7C2C67AB5F0E/command';
        $expected = '7C2C67AB5F0E/client/request/data';

        $result = $this->translator->brokerToCloud($brokerTopic);

        $this->assertEquals($expected, $result);
    }

    public function testExtractMacFromCloudTopic(): void
    {
        $topic = '7C2C67AB5F0E/device/response/client/04';
        $expected = '7C2C67AB5F0E';

        $result = $this->translator->extractMacFromCloudTopic($topic);

        $this->assertEquals($expected, $result);
    }

    public function testExtractMacFromBrokerTopic(): void
    {
        $topic = 'fossibot/7C2C67AB5F0E/state';
        $expected = '7C2C67AB5F0E';

        $result = $this->translator->extractMacFromBrokerTopic($topic);

        $this->assertEquals($expected, $result);
    }

    public function testInvalidBrokerTopicThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->translator->brokerToCloud('invalid/topic/format');
    }

    public function testInvalidMacAddressReturnsNull(): void
    {
        $topic = 'INVALID/device/response/client/04';

        $result = $this->translator->extractMacFromCloudTopic($topic);

        $this->assertNull($result);
    }
}
```

**File:** `tests/Unit/PayloadTransformerTest.php`

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fossibot\Bridge\PayloadTransformer;
use Fossibot\Device\DeviceState;
use Fossibot\Commands\UsbOutputCommand;

class PayloadTransformerTest extends TestCase
{
    private PayloadTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new PayloadTransformer();
    }

    public function testStateToJson(): void
    {
        $state = new DeviceState();
        $state->soc = 85.5;
        $state->usbOutput = true;
        $state->acOutput = false;
        $state->dcOutput = false;
        $state->ledOutput = true;

        $json = $this->transformer->stateToJson($state);
        $data = json_decode($json, true);

        $this->assertEquals(85.5, $data['soc']);
        $this->assertTrue($data['usbOutput']);
        $this->assertFalse($data['acOutput']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testJsonToCommandUsbOn(): void
    {
        $json = '{"action":"usb_on"}';

        $command = $this->transformer->jsonToCommand($json);

        $this->assertInstanceOf(UsbOutputCommand::class, $command);
        $this->assertEquals('Turn USB output on', $command->getDescription());
    }

    public function testJsonToCommandUsbOff(): void
    {
        $json = '{"action":"usb_off"}';

        $command = $this->transformer->jsonToCommand($json);

        $this->assertInstanceOf(UsbOutputCommand::class, $command);
        $this->assertEquals('Turn USB output off', $command->getDescription());
    }

    public function testJsonToCommandWithParameters(): void
    {
        $json = '{"action":"set_charging_current","amperes":15}';

        $command = $this->transformer->jsonToCommand($json);

        $this->assertInstanceOf(\Fossibot\Commands\MaxChargingCurrentCommand::class, $command);
    }

    public function testInvalidJsonThrowsException(): void
    {
        $this->expectException(\JsonException::class);

        $this->transformer->jsonToCommand('invalid json');
    }

    public function testUnknownActionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transformer->jsonToCommand('{"action":"unknown_action"}');
    }

    public function testCommandToModbus(): void
    {
        $command = new UsbOutputCommand(true);

        $modbus = $this->transformer->commandToModbus($command);

        $this->assertIsString($modbus);
        $this->assertGreaterThan(0, strlen($modbus));
        // Verify it's binary data
        $this->assertMatchesRegularExpression('/[\x00-\xFF]+/', $modbus);
    }

    public function testRegistersToState(): void
    {
        $registers = [
            56 => 855,  // SoC: 85.5%
            41 => 64,   // USB on (bit 6)
            41 => 0     // All outputs off
        ];

        $state = $this->transformer->registersToState($registers);

        $this->assertInstanceOf(DeviceState::class, $state);
        $this->assertEquals(85.5, $state->soc);
    }
}
```

**File:** `tests/Unit/DeviceStateTest.php`

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fossibot\Device\DeviceState;

class DeviceStateTest extends TestCase
{
    public function testUpdateFromRegisters(): void
    {
        $state = new DeviceState();

        $registers = [
            56 => 855,  // SoC: 85.5%
            41 => 64    // USB on (bit 6 = 0x40)
        ];

        $state->updateFromRegisters($registers);

        $this->assertEquals(85.5, $state->soc);
        $this->assertTrue($state->usbOutput);
    }

    public function testToArray(): void
    {
        $state = new DeviceState();
        $state->soc = 75.0;
        $state->usbOutput = true;

        $array = $state->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(75.0, $array['soc']);
        $this->assertTrue($array['usbOutput']);
    }

    public function testJsonSerializable(): void
    {
        $state = new DeviceState();
        $state->soc = 90.0;
        $state->acOutput = true;

        $json = json_encode($state);
        $data = json_decode($json, true);

        $this->assertEquals(90.0, $data['soc']);
        $this->assertTrue($data['acOutput']);
    }
}
```

**Run unit tests:**
```bash
vendor/bin/phpunit --testsuite Unit
```

**Expected output:**
```
PHPUnit 10.5.0

...............                                                   15 / 15 (100%)

Time: 00:00.123, Memory: 10.00 MB

OK (15 tests, 35 assertions)
```

**Commit:**
```bash
git add tests/Unit/
git commit -m "test: Add unit tests for TopicTranslator, PayloadTransformer, DeviceState"
```

---

### Step 2: Integration Tests (45 min)

**File:** `tests/Integration/AsyncCloudClientTest.php`

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Fossibot\Bridge\AsyncCloudClient;
use React\EventLoop\Loop;

class AsyncCloudClientTest extends TestCase
{
    private string $email;
    private string $password;

    protected function setUp(): void
    {
        $this->email = getTestEmail();
        $this->password = getTestPassword();
    }

    public function testConnectionEstablished(): void
    {
        $loop = Loop::get();
        $logger = createTestLogger();
        $client = new AsyncCloudClient($this->email, $this->password, $loop, $logger);

        $connected = false;

        $client->on('connect', function() use (&$connected, $loop) {
            $connected = true;
            $loop->stop();
        });

        $client->on('error', function($error) use ($loop) {
            $this->fail('Connection failed: ' . $error->getMessage());
            $loop->stop();
        });

        $client->connect();

        // Timeout after 30 seconds
        $loop->addTimer(30, function() use ($loop) {
            $loop->stop();
        });

        $loop->run();

        $this->assertTrue($connected, 'Client should connect successfully');
    }

    public function testDeviceDiscovery(): void
    {
        $loop = Loop::get();
        $logger = createTestLogger();
        $client = new AsyncCloudClient($this->email, $this->password, $loop, $logger);

        $devices = [];

        $client->on('connect', function() use ($client, &$devices, $loop) {
            $devices = $client->getDevices();
            $loop->stop();
        });

        $client->connect();

        $loop->addTimer(30, fn() => $loop->stop());
        $loop->run();

        $this->assertNotEmpty($devices, 'Should discover at least one device');
        $this->assertIsArray($devices);

        $device = $devices[0];
        $this->assertNotEmpty($device->getMqttId());
        $this->assertEquals(12, strlen($device->getMqttId())); // MAC address format
    }

    public function testMessageReception(): void
    {
        $loop = Loop::get();
        $logger = createTestLogger();
        $client = new AsyncCloudClient($this->email, $this->password, $loop, $logger);

        $messageReceived = false;

        $client->on('connect', function() use ($client) {
            // Wait for first message
        });

        $client->on('message', function($topic, $payload) use (&$messageReceived, $loop) {
            $messageReceived = true;
            $this->assertIsString($topic);
            $this->assertIsString($payload);
            $this->assertNotEmpty($payload);
            $loop->stop();
        });

        $client->connect();

        // Wait up to 60 seconds for device message
        $loop->addTimer(60, fn() => $loop->stop());
        $loop->run();

        $this->assertTrue($messageReceived, 'Should receive at least one message from device');
    }

    public function testPublishCommand(): void
    {
        $loop = Loop::get();
        $logger = createTestLogger();
        $client = new AsyncCloudClient($this->email, $this->password, $loop, $logger);

        $published = false;

        $client->on('connect', function() use ($client, &$published, $loop) {
            $devices = $client->getDevices();
            if (empty($devices)) {
                $this->markTestSkipped('No devices available for publish test');
                $loop->stop();
                return;
            }

            $device = $devices[0];
            $topic = $device->getMqttId() . '/client/request/data';
            $payload = hex2bin('11060029000140d9'); // USB ON command

            try {
                $client->publish($topic, $payload);
                $published = true;
            } catch (\Throwable $e) {
                $this->fail('Publish failed: ' . $e->getMessage());
            }

            $loop->addTimer(2, fn() => $loop->stop());
        });

        $client->connect();

        $loop->addTimer(30, fn() => $loop->stop());
        $loop->run();

        $this->assertTrue($published, 'Should successfully publish command');
    }

    public function testGracefulDisconnect(): void
    {
        $loop = Loop::get();
        $logger = createTestLogger();
        $client = new AsyncCloudClient($this->email, $this->password, $loop, $logger);

        $disconnected = false;

        $client->on('connect', function() use ($client) {
            $client->disconnect();
        });

        $client->on('disconnect', function() use (&$disconnected, $loop) {
            $disconnected = true;
            $loop->stop();
        });

        $client->connect();

        $loop->addTimer(30, fn() => $loop->stop());
        $loop->run();

        $this->assertTrue($disconnected, 'Should disconnect gracefully');
    }
}
```

**File:** `tests/Integration/ReconnectTest.php`

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Fossibot\Bridge\AsyncCloudClient;
use React\EventLoop\Loop;

class ReconnectTest extends TestCase
{
    public function testSimpleReconnect(): void
    {
        $loop = Loop::get();
        $logger = createTestLogger();
        $client = new AsyncCloudClient(getTestEmail(), getTestPassword(), $loop, $logger);

        $reconnected = false;

        $client->on('connect', function() use ($client) {
            echo "Connected, forcing disconnect...\n";
            $client->disconnect();
        });

        $client->on('disconnect', function() use ($client) {
            echo "Disconnected, triggering reconnect...\n";
            $client->reconnect(false);
        });

        $client->on('reconnect', function() use (&$reconnected, $loop) {
            echo "Reconnected!\n";
            $reconnected = true;
            $loop->stop();
        });

        $client->connect();

        $loop->addTimer(60, function() use ($loop) {
            echo "Timeout\n";
            $loop->stop();
        });

        $loop->run();

        $this->assertTrue($reconnected, 'Should reconnect after disconnect');
    }

    public function testExponentialBackoff(): void
    {
        $this->markTestSkipped('Requires simulating repeated failures - manual test');
    }
}
```

**Run integration tests:**
```bash
vendor/bin/phpunit --testsuite Integration
```

**Note:** These tests take 30-60 seconds as they connect to real API.

**Commit:**
```bash
git add tests/Integration/
git commit -m "test: Add integration tests for AsyncCloudClient and reconnect logic"
```

---

### Step 3: System Tests (30 min)

**File:** `tests/System/DaemonLifecycleTest.php`

```php
<?php
declare(strict_types=1);

namespace Tests\System;

use PHPUnit\Framework\TestCase;

class DaemonLifecycleTest extends TestCase
{
    private string $daemonPath;
    private string $configPath;

    protected function setUp(): void
    {
        $this->daemonPath = __DIR__ . '/../../daemon/fossibot-bridge.php';
        $this->configPath = __DIR__ . '/../../config/example.json';

        $this->assertFileExists($this->daemonPath);
        $this->assertFileIsReadable($this->daemonPath);
    }

    public function testHelpOutput(): void
    {
        $output = shell_exec("php {$this->daemonPath} --help 2>&1");

        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('--config', $output);
    }

    public function testVersionOutput(): void
    {
        $output = shell_exec("php {$this->daemonPath} --version 2>&1");

        $this->assertStringContainsString('v2.0.0', $output);
    }

    public function testConfigValidation(): void
    {
        $output = shell_exec("php {$this->daemonPath} --config {$this->configPath} --validate 2>&1");

        $this->assertStringContainsString('Config valid', $output);
    }

    public function testInvalidConfigDetection(): void
    {
        $invalidConfig = ['accounts' => []];
        $tmpFile = tempnam(sys_get_temp_dir(), 'config');
        file_put_contents($tmpFile, json_encode($invalidConfig));

        $output = shell_exec("php {$this->daemonPath} --config {$tmpFile} --validate 2>&1");

        $this->assertStringContainsString('validation failed', $output);

        unlink($tmpFile);
    }

    public function testMissingConfigError(): void
    {
        $output = shell_exec("php {$this->daemonPath} --config /nonexistent/config.json 2>&1");

        $this->assertStringContainsString('not found', $output);
    }

    public function testStartupWithTimeout(): void
    {
        if (!file_exists(__DIR__ . '/../../config/config.json')) {
            $this->markTestSkipped('config/config.json not found - create from example');
        }

        $process = proc_open(
            "php {$this->daemonPath} --config config/config.json 2>&1",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes,
            __DIR__ . '/../..'
        );

        $this->assertIsResource($process);

        sleep(5);

        $status = proc_get_status($process);
        $this->assertTrue($status['running'], 'Daemon should start successfully');

        // Graceful shutdown
        proc_terminate($process, SIGTERM);
        sleep(2);

        $status = proc_get_status($process);
        $this->assertFalse($status['running'], 'Daemon should stop on SIGTERM');

        proc_close($process);
    }
}
```

**Run system tests:**
```bash
vendor/bin/phpunit --testsuite System
```

**Commit:**
```bash
git add tests/System/
git commit -m "test: Add system tests for daemon lifecycle"
```

---

### Step 4: Test Runner Script (15 min)

**File:** `run-tests.sh`

```bash
#!/bin/bash
# ABOUTME: Runs complete test suite with pretty output

set -e

echo "========================================="
echo "  Fossibot MQTT Bridge - Test Suite"
echo "========================================="
echo

# Check .env
if [ ! -f .env ]; then
    echo "❌ Error: .env file not found"
    echo "   Create .env with FOSSIBOT_EMAIL and FOSSIBOT_PASSWORD"
    exit 1
fi

# Check Mosquitto
if ! pgrep -x mosquitto > /dev/null; then
    echo "⚠️  Warning: Mosquitto not running"
    echo "   Some tests may fail. Start with: brew services start mosquitto"
    echo
fi

# Unit tests
echo "Running Unit Tests..."
echo "--------------------"
vendor/bin/phpunit --testsuite Unit --testdox
echo
echo "✅ Unit tests passed"
echo

# Integration tests (slower)
echo "Running Integration Tests (may take 60s)..."
echo "--------------------------------------------"
vendor/bin/phpunit --testsuite Integration --testdox
echo
echo "✅ Integration tests passed"
echo

# System tests
echo "Running System Tests..."
echo "-----------------------"
vendor/bin/phpunit --testsuite System --testdox
echo
echo "✅ System tests passed"
echo

echo "========================================="
echo "  ✅ All tests passed!"
echo "========================================="
```

**Make executable:**
```bash
chmod +x run-tests.sh
```

**Commit:**
```bash
git add run-tests.sh
git commit -m "test: Add test runner script"
```

---

## 🎯 Testing Checklist

### Before Committing Code

- [ ] Run `./run-tests.sh` - all tests pass
- [ ] Run `php daemon/fossibot-bridge.php --validate` on example config
- [ ] Check logs for errors/warnings
- [ ] Test with real device (manual verification)

### Before Releasing

- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] System tests pass
- [ ] Manual E2E test with real device
- [ ] Test on clean system (Docker/VM)
- [ ] Documentation updated

---

## 📊 Test Coverage Goals

| Component | Target Coverage | Test Type |
|-----------|----------------|-----------|
| TopicTranslator | 100% | Unit |
| PayloadTransformer | 90%+ | Unit |
| DeviceState | 100% | Unit |
| Commands | 80%+ | Unit |
| AsyncCloudClient | 70%+ | Integration |
| MqttBridge | 60%+ | Integration |
| CLI | 80%+ | System |

**Note:** Lower coverage for async components is acceptable due to event loop complexity.

---

## 🐛 Debugging Failed Tests

### Unit Test Failure

```bash
# Run specific test with verbose output
vendor/bin/phpunit --filter testCloudToBrokerStateTranslation --verbose

# Enable debug logging
vendor/bin/phpunit --debug
```

### Integration Test Timeout

```bash
# Check credentials
cat .env

# Test connection manually
php test_async_cloud_client.php

# Enable debug logging in test
```

### System Test Hangs

```bash
# Check process status
ps aux | grep fossibot-bridge

# Kill hung processes
pkill -f fossibot-bridge

# Check for port conflicts
lsof -i :1883
```

---

## 📚 Continuous Integration

### GitHub Actions Example

**File:** `.github/workflows/tests.yml`

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mosquitto:
        image: eclipse-mosquitto:2
        ports:
          - 1883:1883

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, xml, curl

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Create .env
        run: |
          echo "FOSSIBOT_EMAIL=${{ secrets.FOSSIBOT_EMAIL }}" > .env
          echo "FOSSIBOT_PASSWORD=${{ secrets.FOSSIBOT_PASSWORD }}" >> .env

      - name: Run tests
        run: ./run-tests.sh
```

---

## ✅ Success Criteria

**Testing is complete when:**

1. All unit tests pass in <1 second
2. Integration tests pass in <60 seconds
3. System tests verify CLI functionality
4. `./run-tests.sh` provides clear output
5. Tests run against real Fossibot API
6. No mocking of external services
7. Documentation explains testing philosophy

---

## 📚 Summary

### What We Test

✅ **Unit Tests:**
- Topic translation logic
- Payload transformation
- Device state management
- Command generation

✅ **Integration Tests:**
- Real API authentication
- WebSocket connection
- MQTT protocol
- Message flow
- Reconnection logic

✅ **System Tests:**
- CLI argument parsing
- Config validation
- Daemon lifecycle
- Signal handling

### What We Don't Test

❌ **Event Loop Internals** - Too complex, low value
❌ **Network Failures** - Manual testing sufficient
❌ **Token Expiry Edge Cases** - Requires time manipulation
❌ **systemd Integration** - Requires root, manual testing

---

**Testing complete!** All documentation phases finished. Ready for implementation.