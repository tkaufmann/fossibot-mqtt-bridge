# 07 - Phase 4: CLI & systemd Integration

**Phase:** 4 - Deployment
**Effort:** ~3 hours
**Prerequisites:** Phase 3 complete (Reconnect logic functional)
**Deliverables:** CLI entry point, systemd service, production-ready deployment

---

## 🎯 Phase Goals

1. Implement CLI entry point with argument parsing
2. Add config file loading and validation
3. Create systemd service unit file
4. Add logging configuration
5. Document deployment and management
6. Test complete daemon lifecycle

---

## 📋 Step-by-Step Implementation

### Step 4.1: Implement CLI Entry Point (60 min)

**Update:** `daemon/fossibot-bridge.php`

```php
#!/usr/bin/env php
<?php
// ABOUTME: CLI entry point for Fossibot MQTT Bridge daemon
// Loads config, initializes bridge, runs event loop

declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

use Fossibot\Bridge\MqttBridge;
use React\EventLoop\Loop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// =============================================================================
// CLI ARGUMENT PARSING
// =============================================================================

function showUsage(): void
{
    echo <<<USAGE
Fossibot MQTT Bridge - ReactPHP Daemon
Usage: fossibot-bridge.php [OPTIONS]

Options:
  -c, --config PATH    Path to config.json file (required)
  -h, --help          Show this help message
  -v, --version       Show version information
  --validate          Validate config and exit (no daemon start)

Examples:
  fossibot-bridge.php --config /etc/fossibot/config.json
  fossibot-bridge.php -c ./config/config.json
  fossibot-bridge.php --config config.json --validate

USAGE;
}

function showVersion(): void
{
    echo "Fossibot MQTT Bridge v2.0.0\n";
    echo "PHP " . PHP_VERSION . "\n";
    echo "ReactPHP Event Loop\n";
}

// Parse CLI arguments
$options = getopt('c:hv', ['config:', 'help', 'version', 'validate']);

if (isset($options['h']) || isset($options['help'])) {
    showUsage();
    exit(0);
}

if (isset($options['v']) || isset($options['version'])) {
    showVersion();
    exit(0);
}

$configPath = $options['c'] ?? $options['config'] ?? null;

if ($configPath === null) {
    echo "Error: --config argument is required\n\n";
    showUsage();
    exit(1);
}

// Resolve relative paths
if (!str_starts_with($configPath, '/')) {
    $configPath = getcwd() . '/' . $configPath;
}

// =============================================================================
// CONFIG LOADING & VALIDATION
// =============================================================================

function loadConfig(string $path): array
{
    if (!file_exists($path)) {
        throw new \RuntimeException("Config file not found: $path");
    }

    if (!is_readable($path)) {
        throw new \RuntimeException("Config file not readable: $path");
    }

    $json = file_get_contents($path);
    if ($json === false) {
        throw new \RuntimeException("Failed to read config file: $path");
    }

    $config = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException("Invalid JSON in config file: " . json_last_error_msg());
    }

    return $config;
}

function validateConfig(array $config): array
{
    $errors = [];

    // Validate accounts
    if (!isset($config['accounts']) || !is_array($config['accounts'])) {
        $errors[] = "Missing or invalid 'accounts' array";
    } elseif (empty($config['accounts'])) {
        $errors[] = "No accounts configured (accounts array is empty)";
    } else {
        foreach ($config['accounts'] as $i => $account) {
            if (empty($account['email'])) {
                $errors[] = "Account $i: missing 'email'";
            }
            if (empty($account['password'])) {
                $errors[] = "Account $i: missing 'password'";
            }
            if (!filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Account $i: invalid email format";
            }
        }
    }

    // Validate mosquitto
    if (!isset($config['mosquitto']) || !is_array($config['mosquitto'])) {
        $errors[] = "Missing or invalid 'mosquitto' configuration";
    } else {
        if (empty($config['mosquitto']['host'])) {
            $errors[] = "Missing mosquitto.host";
        }
        if (!isset($config['mosquitto']['port']) || !is_int($config['mosquitto']['port'])) {
            $errors[] = "Missing or invalid mosquitto.port (must be integer)";
        }
        if (empty($config['mosquitto']['client_id'])) {
            $errors[] = "Missing mosquitto.client_id";
        }
    }

    // Validate daemon
    if (!isset($config['daemon']) || !is_array($config['daemon'])) {
        $errors[] = "Missing or invalid 'daemon' configuration";
    } else {
        if (empty($config['daemon']['log_file'])) {
            $errors[] = "Missing daemon.log_file";
        }
        if (empty($config['daemon']['log_level'])) {
            $errors[] = "Missing daemon.log_level";
        } elseif (!in_array($config['daemon']['log_level'], ['debug', 'info', 'warning', 'error'])) {
            $errors[] = "Invalid daemon.log_level (must be: debug, info, warning, error)";
        }
    }

    // Validate bridge
    if (!isset($config['bridge']) || !is_array($config['bridge'])) {
        $errors[] = "Missing or invalid 'bridge' configuration";
    } else {
        if (!isset($config['bridge']['status_publish_interval']) || !is_int($config['bridge']['status_publish_interval'])) {
            $errors[] = "Missing or invalid bridge.status_publish_interval (must be integer)";
        }
        if (!isset($config['bridge']['reconnect_delay_min']) || !is_int($config['bridge']['reconnect_delay_min'])) {
            $errors[] = "Missing or invalid bridge.reconnect_delay_min (must be integer)";
        }
        if (!isset($config['bridge']['reconnect_delay_max']) || !is_int($config['bridge']['reconnect_delay_max'])) {
            $errors[] = "Missing or invalid bridge.reconnect_delay_max (must be integer)";
        }
    }

    return $errors;
}

try {
    echo "Loading config from: $configPath\n";
    $config = loadConfig($configPath);

    echo "Validating config...\n";
    $errors = validateConfig($config);

    if (!empty($errors)) {
        echo "\n❌ Config validation failed:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
        exit(1);
    }

    echo "✅ Config valid\n";
    echo "  Accounts: " . count($config['accounts']) . "\n";
    echo "  Mosquitto: {$config['mosquitto']['host']}:{$config['mosquitto']['port']}\n";
    echo "  Log level: {$config['daemon']['log_level']}\n";

    // If --validate flag, exit here
    if (isset($options['validate'])) {
        echo "\n✅ Validation complete (--validate flag set, not starting daemon)\n";
        exit(0);
    }

} catch (\Throwable $e) {
    echo "\n❌ Config error: " . $e->getMessage() . "\n";
    exit(1);
}

// =============================================================================
// LOGGER SETUP
// =============================================================================

function createLogger(array $config): Logger
{
    $logger = new Logger('fossibot_bridge');

    // Map log level string to Monolog constant
    $logLevel = match($config['daemon']['log_level']) {
        'debug' => Logger::DEBUG,
        'info' => Logger::INFO,
        'warning' => Logger::WARNING,
        'error' => Logger::ERROR,
        default => Logger::INFO
    };

    // Console handler (STDOUT)
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context%\n",
        "Y-m-d H:i:s",
        true,
        true
    );
    $consoleHandler = new StreamHandler('php://stdout', $logLevel);
    $consoleHandler->setFormatter($consoleFormatter);
    $logger->pushHandler($consoleHandler);

    // File handler (rotating, 7 days retention)
    $logFile = $config['daemon']['log_file'];

    // Create log directory if needed
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s",
        true,
        true
    );
    $fileHandler = new RotatingFileHandler($logFile, 7, $logLevel);
    $fileHandler->setFormatter($fileFormatter);
    $logger->pushHandler($fileHandler);

    return $logger;
}

try {
    $logger = createLogger($config);
    echo "✅ Logger initialized\n\n";
} catch (\Throwable $e) {
    echo "❌ Failed to initialize logger: " . $e->getMessage() . "\n";
    exit(1);
}

// =============================================================================
// DAEMON STARTUP
// =============================================================================

$logger->info('Fossibot MQTT Bridge starting', [
    'version' => '2.0.0',
    'php_version' => PHP_VERSION,
    'config_file' => $configPath,
    'pid' => getmypid()
]);

try {
    $loop = Loop::get();
    $bridge = new MqttBridge($config, $loop, $logger);

    echo "Starting bridge (press Ctrl+C to stop)...\n";
    echo "═══════════════════════════════════════\n\n";

    $bridge->start();

} catch (\Throwable $e) {
    $logger->critical('Bridge startup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    echo "\n❌ Bridge startup failed: " . $e->getMessage() . "\n";
    exit(1);
}

// This point is reached after loop->stop() (graceful shutdown)
$logger->info('Bridge stopped');
echo "\n✅ Bridge stopped\n";
exit(0);
```

**Make executable:**
```bash
chmod +x daemon/fossibot-bridge.php
```

**Test CLI:**
```bash
# Show help
daemon/fossibot-bridge.php --help

# Show version
daemon/fossibot-bridge.php --version

# Validate config
daemon/fossibot-bridge.php --config config/example.json --validate

# Start daemon (Ctrl+C to stop)
daemon/fossibot-bridge.php --config config/example.json
```

**Commit:**
```bash
git add daemon/fossibot-bridge.php
git commit -m "feat(cli): Implement CLI entry point with argument parsing"
```

**Deliverable:** ✅ Functional CLI entry point

---

### Step 4.2: Create systemd Service Unit (30 min)

**File:** `daemon/fossibot-bridge.service`

```ini
[Unit]
Description=Fossibot MQTT Bridge Daemon
Documentation=https://github.com/youruser/fossibot-php2
After=network.target mosquitto.service
Wants=mosquitto.service

[Service]
Type=simple
User=fossibot
Group=fossibot
WorkingDirectory=/opt/fossibot-bridge
ExecStart=/usr/bin/php /opt/fossibot-bridge/daemon/fossibot-bridge.php --config /etc/fossibot/config.json
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/log/fossibot

# Resource limits
LimitNOFILE=65536
MemoryMax=512M

# Environment
Environment="PHP_MEMORY_LIMIT=256M"

[Install]
WantedBy=multi-user.target
```

**File:** `daemon/install-systemd.sh`

```bash
#!/bin/bash
# ABOUTME: Installs Fossibot Bridge as systemd service

set -e

echo "Fossibot MQTT Bridge - systemd Installation"
echo "==========================================="
echo

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Error: This script must be run as root (use sudo)"
    exit 1
fi

# Create fossibot user if not exists
if ! id -u fossibot >/dev/null 2>&1; then
    echo "Creating fossibot user..."
    useradd --system --shell /usr/sbin/nologin --home-dir /opt/fossibot-bridge fossibot
else
    echo "✅ User fossibot already exists"
fi

# Create directories
echo "Creating directories..."
mkdir -p /opt/fossibot-bridge
mkdir -p /etc/fossibot
mkdir -p /var/log/fossibot

# Set ownership
chown -R fossibot:fossibot /opt/fossibot-bridge
chown -R fossibot:fossibot /var/log/fossibot
chmod 700 /etc/fossibot

echo "✅ Directories created"

# Copy files
echo "Copying bridge files..."
cp -r ../src /opt/fossibot-bridge/
cp -r ../daemon /opt/fossibot-bridge/
cp -r ../vendor /opt/fossibot-bridge/
cp ../composer.json /opt/fossibot-bridge/
cp ../composer.lock /opt/fossibot-bridge/

chown -R fossibot:fossibot /opt/fossibot-bridge

echo "✅ Files copied"

# Copy config example
if [ ! -f /etc/fossibot/config.json ]; then
    echo "Copying example config..."
    cp ../config/example.json /etc/fossibot/config.json
    chown fossibot:fossibot /etc/fossibot/config.json
    chmod 600 /etc/fossibot/config.json
    echo "⚠️  Please edit /etc/fossibot/config.json with your credentials!"
else
    echo "✅ Config already exists at /etc/fossibot/config.json"
fi

# Install systemd unit
echo "Installing systemd service..."
cp fossibot-bridge.service /etc/systemd/system/
chmod 644 /etc/systemd/system/fossibot-bridge.service

# Reload systemd
systemctl daemon-reload

echo "✅ systemd service installed"
echo
echo "Next steps:"
echo "  1. Edit config: sudo nano /etc/fossibot/config.json"
echo "  2. Enable service: sudo systemctl enable fossibot-bridge"
echo "  3. Start service: sudo systemctl start fossibot-bridge"
echo "  4. Check status: sudo systemctl status fossibot-bridge"
echo "  5. View logs: sudo journalctl -u fossibot-bridge -f"
echo
```

**Make executable:**
```bash
chmod +x daemon/install-systemd.sh
```

**Commit:**
```bash
git add daemon/fossibot-bridge.service daemon/install-systemd.sh
git commit -m "feat(systemd): Add systemd service unit and installer"
```

**Deliverable:** ✅ systemd service unit

---

### Step 4.3: Create Deployment Documentation (45 min)

**File:** `daemon/DEPLOYMENT.md`

```markdown
# Deployment Guide

Complete guide for deploying Fossibot MQTT Bridge in production.

---

## Prerequisites

### System Requirements

- **OS**: Ubuntu 20.04+ or Debian 11+
- **PHP**: 8.1 or higher
- **Memory**: 256MB minimum, 512MB recommended
- **Disk**: 100MB for application + logs

### Required Software

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP and extensions
sudo apt install php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Mosquitto
sudo apt install mosquitto mosquitto-clients
sudo systemctl enable mosquitto
sudo systemctl start mosquitto
```

---

## Installation

### 1. Clone Repository

```bash
cd /opt
sudo git clone https://github.com/youruser/fossibot-php2.git fossibot-bridge
cd fossibot-bridge
```

### 2. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Configure Service

```bash
cd daemon
sudo ./install-systemd.sh
```

### 4. Edit Configuration

```bash
sudo nano /etc/fossibot/config.json
```

Add your Fossibot account credentials:

```json
{
  "accounts": [
    {
      "email": "your-email@example.com",
      "password": "your-password",
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
    "log_file": "/var/log/fossibot/bridge.log",
    "log_level": "info"
  },
  "bridge": {
    "status_publish_interval": 60,
    "reconnect_delay_min": 5,
    "reconnect_delay_max": 60
  }
}
```

**Secure config file:**
```bash
sudo chmod 600 /etc/fossibot/config.json
```

### 5. Validate Configuration

```bash
sudo -u fossibot php /opt/fossibot-bridge/daemon/fossibot-bridge.php \
  --config /etc/fossibot/config.json --validate
```

Expected output:
```
✅ Config valid
  Accounts: 1
  Mosquitto: localhost:1883
  Log level: info
```

---

## Service Management

### Enable and Start

```bash
sudo systemctl enable fossibot-bridge
sudo systemctl start fossibot-bridge
```

### Check Status

```bash
sudo systemctl status fossibot-bridge
```

Expected output:
```
● fossibot-bridge.service - Fossibot MQTT Bridge Daemon
     Loaded: loaded (/etc/systemd/system/fossibot-bridge.service)
     Active: active (running) since Mon 2025-09-30 12:00:00 UTC
   Main PID: 12345 (php)
      Tasks: 3
     Memory: 45.2M
```

### View Logs

```bash
# Real-time logs
sudo journalctl -u fossibot-bridge -f

# Last 100 lines
sudo journalctl -u fossibot-bridge -n 100

# Logs since today
sudo journalctl -u fossibot-bridge --since today
```

### Stop Service

```bash
sudo systemctl stop fossibot-bridge
```

### Restart Service

```bash
sudo systemctl restart fossibot-bridge
```

---

## Verification

### 1. Check Bridge Status

```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v
```

Expected:
```json
fossibot/bridge/status {"status":"online","version":"2.0.0",...}
```

### 2. Check Device Discovery

```bash
mosquitto_sub -h localhost -t 'fossibot/+/state' -v
```

Should show device states within 30 seconds.

### 3. Test Command

```bash
# Get device MAC from status message
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'
```

Device USB output should turn on.

---

## Troubleshooting

### Service won't start

**Check logs:**
```bash
sudo journalctl -u fossibot-bridge -n 50
```

**Common issues:**
- Config file syntax error → Validate with `--validate` flag
- Mosquitto not running → `sudo systemctl start mosquitto`
- Missing dependencies → `composer install`
- Permission issues → Check `/var/log/fossibot` ownership

### No devices discovered

**Check authentication:**
```bash
sudo journalctl -u fossibot-bridge | grep auth
```

Look for authentication errors (401/403).

**Verify credentials:**
- Test login via web interface
- Check for typos in config.json
- Ensure password is correct (no trailing spaces)

### Bridge keeps reconnecting

**Check MQTT token expiry:**
```bash
sudo journalctl -u fossibot-bridge | grep "token expired"
```

Token should be valid for ~3 days. If expiring immediately, check system clock.

### High memory usage

**Check memory stats:**
```bash
systemctl status fossibot-bridge | grep Memory
```

Normal: 30-80MB per account

High (>200MB): Potential memory leak, restart service:
```bash
sudo systemctl restart fossibot-bridge
```

---

## Updating

### Update Code

```bash
cd /opt/fossibot-bridge
sudo git pull
composer install --no-dev --optimize-autoloader
sudo systemctl restart fossibot-bridge
```

### Update Config

```bash
sudo nano /etc/fossibot/config.json
# Make changes
sudo systemctl restart fossibot-bridge
```

---

## Monitoring

### Setup Log Rotation

Create `/etc/logrotate.d/fossibot`:

```
/var/log/fossibot/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    missingok
    create 0640 fossibot fossibot
}
```

### Monitor with systemd

```bash
# Enable email notifications on failure
sudo systemctl edit fossibot-bridge
```

Add:
```ini
[Unit]
OnFailure=failure-notification@%n.service
```

---

## Security Best Practices

1. **Restrict config file permissions:**
   ```bash
   sudo chmod 600 /etc/fossibot/config.json
   ```

2. **Enable Mosquitto authentication:**
   ```bash
   sudo mosquitto_passwd -c /etc/mosquitto/passwd bridge_user
   ```

   Update `/etc/mosquitto/mosquitto.conf`:
   ```
   password_file /etc/mosquitto/passwd
   ```

3. **Use firewall to restrict MQTT access:**
   ```bash
   sudo ufw allow from 192.168.1.0/24 to any port 1883
   ```

4. **Regular updates:**
   ```bash
   sudo apt update && sudo apt upgrade
   ```

---

## Uninstallation

```bash
# Stop and disable service
sudo systemctl stop fossibot-bridge
sudo systemctl disable fossibot-bridge

# Remove files
sudo rm /etc/systemd/system/fossibot-bridge.service
sudo rm -rf /opt/fossibot-bridge
sudo rm -rf /etc/fossibot
sudo rm -rf /var/log/fossibot

# Remove user
sudo userdel fossibot

# Reload systemd
sudo systemctl daemon-reload
```
```

**Commit:**
```bash
git add daemon/DEPLOYMENT.md
git commit -m "docs(deployment): Add production deployment guide"
```

**Deliverable:** ✅ Deployment documentation

---

### Step 4.4: Test Complete Daemon Lifecycle (45 min)

**Test Script:** `test_daemon_lifecycle.php`

```php
<?php
require 'vendor/autoload.php';

echo "=== Daemon Lifecycle Test ===\n\n";

// Test 1: Config validation
echo "Test 1: Config validation\n";
echo "-------------------------\n";

$output = shell_exec('php daemon/fossibot-bridge.php --config config/example.json --validate 2>&1');
echo $output;

if (str_contains($output, '✅ Config valid')) {
    echo "✅ Config validation works\n\n";
} else {
    echo "❌ Config validation failed\n\n";
    exit(1);
}

// Test 2: Invalid config detection
echo "Test 2: Invalid config detection\n";
echo "---------------------------------\n";

$invalidConfig = [
    'accounts' => [],  // Empty accounts (invalid)
    'mosquitto' => ['host' => 'localhost'],
];

file_put_contents('/tmp/invalid_config.json', json_encode($invalidConfig));
$output = shell_exec('php daemon/fossibot-bridge.php --config /tmp/invalid_config.json --validate 2>&1');

if (str_contains($output, '❌ Config validation failed')) {
    echo "✅ Invalid config detection works\n\n";
} else {
    echo "❌ Should have failed validation\n\n";
    exit(1);
}

unlink('/tmp/invalid_config.json');

// Test 3: Help output
echo "Test 3: Help output\n";
echo "-------------------\n";

$output = shell_exec('php daemon/fossibot-bridge.php --help 2>&1');

if (str_contains($output, 'Usage:') && str_contains($output, '--config')) {
    echo "✅ Help output correct\n\n";
} else {
    echo "❌ Help output incorrect\n\n";
    exit(1);
}

// Test 4: Version output
echo "Test 4: Version output\n";
echo "----------------------\n";

$output = shell_exec('php daemon/fossibot-bridge.php --version 2>&1');

if (str_contains($output, 'v2.0.0')) {
    echo "✅ Version output correct\n\n";
} else {
    echo "❌ Version output incorrect\n\n";
    exit(1);
}

// Test 5: Daemon start (with timeout)
echo "Test 5: Daemon startup\n";
echo "----------------------\n";
echo "Starting daemon for 10 seconds...\n";

// Note: Requires valid credentials in config
if (!file_exists('config/config.json')) {
    echo "⚠️  Skipping (config/config.json not found)\n";
    echo "   Create config/config.json from example to test daemon startup\n\n";
} else {
    // Start daemon in background
    $process = proc_open(
        'php daemon/fossibot-bridge.php --config config/config.json 2>&1',
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ],
        $pipes
    );

    if (is_resource($process)) {
        // Give daemon 5 seconds to start
        sleep(5);

        // Check if still running
        $status = proc_get_status($process);
        if ($status['running']) {
            echo "✅ Daemon started successfully\n";

            // Send SIGTERM to trigger graceful shutdown
            proc_terminate($process, SIGTERM);

            // Wait for graceful shutdown (max 5 seconds)
            $shutdownStart = time();
            while ($status['running'] && (time() - $shutdownStart) < 5) {
                sleep(1);
                $status = proc_get_status($process);
            }

            if (!$status['running']) {
                echo "✅ Graceful shutdown successful\n\n";
            } else {
                echo "⚠️  Daemon didn't stop gracefully, killing\n\n";
                proc_terminate($process, SIGKILL);
            }
        } else {
            $output = stream_get_contents($pipes[1]);
            echo "❌ Daemon failed to start:\n";
            echo $output . "\n";
            exit(1);
        }

        // Cleanup
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    } else {
        echo "❌ Failed to start process\n\n";
        exit(1);
    }
}

echo "✅ All daemon lifecycle tests passed!\n";
```

**Run test:**
```bash
php test_daemon_lifecycle.php
```

**Expected output:**
```
=== Daemon Lifecycle Test ===

Test 1: Config validation
-------------------------
Loading config from: config/example.json
Validating config...
✅ Config valid
  Accounts: 1
  Mosquitto: localhost:1883
  Log level: info
✅ Config validation works

Test 2: Invalid config detection
---------------------------------
❌ Config validation failed:
  - No accounts configured (accounts array is empty)
✅ Invalid config detection works

Test 3: Help output
-------------------
✅ Help output correct

Test 4: Version output
----------------------
✅ Version output correct

Test 5: Daemon startup
----------------------
Starting daemon for 10 seconds...
✅ Daemon started successfully
✅ Graceful shutdown successful

✅ All daemon lifecycle tests passed!
```

**Commit:**
```bash
git add test_daemon_lifecycle.php
git commit -m "test: Add daemon lifecycle integration test"
```

**Deliverable:** ✅ Complete daemon lifecycle tested

---

## ✅ Phase 4 Completion Checklist

- [ ] CLI entry point with argument parsing
- [ ] Config loading and validation
- [ ] Logger initialization (console + file)
- [ ] systemd service unit file
- [ ] Installation script for systemd
- [ ] Deployment documentation
- [ ] Lifecycle test script
- [ ] All commits made with proper messages

---

## 🎯 Success Criteria

**Phase 4 is complete when:**

1. `daemon/fossibot-bridge.php --help` shows usage
2. `daemon/fossibot-bridge.php --validate` validates config
3. `daemon/fossibot-bridge.php --config config.json` starts bridge
4. Ctrl+C triggers graceful shutdown
5. systemd service installs without errors
6. Service can be started/stopped via systemctl
7. Logs appear in journalctl
8. `test_daemon_lifecycle.php` passes all tests

---

## 🐛 Troubleshooting

**Problem:** CLI shows "config file not found"

**Solution:** Use absolute path or check working directory:
```bash
cd /path/to/fossibot-php2
php daemon/fossibot-bridge.php --config ./config/config.json
```

---

**Problem:** systemd service fails to start

**Solution:** Check service logs:
```bash
sudo journalctl -u fossibot-bridge -n 50
```

Common: Wrong paths in service file, missing permissions.

---

**Problem:** Log directory permission denied

**Solution:** Ensure log directory exists and is writable:
```bash
sudo mkdir -p /var/log/fossibot
sudo chown fossibot:fossibot /var/log/fossibot
sudo chmod 755 /var/log/fossibot
```

---

## 📚 Next Steps

**Phase 4 complete!** → [08-PHASE-5-DOCS.md](08-PHASE-5-DOCS.md)

Write user documentation, examples, and final deployment guide.