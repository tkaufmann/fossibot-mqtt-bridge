# Phase 3: PID Management

**Time**: 30min
**Priority**: P0 (Quick Win - Do First!)
**Dependencies**: None

---

## Goal

Implementiere PID-File-Management um:
- Doppelstarts zu verhindern (zwei Bridge-Instanzen würden MQTT-Konflikte verursachen)
- Stale PID-Files zu erkennen (Bridge crashed → PID-File bleibt)
- Sauberes Cleanup bei graceful shutdown
- Grundlage für Control Script (Phase 4) zu schaffen

**Why P0**: Kritisch für Production - verhindert schwer debugbare MQTT-Konflikte bei versehentlichem Doppelstart.

---

## Steps

### Step 1: PID File Handling in daemon/fossibot-bridge.php (20min)

**File**: `daemon/fossibot-bridge.php`
**Lines**: After line 205 (after config validation, before logger setup)

**Add**:
```php
// =============================================================================
// PID FILE MANAGEMENT
// =============================================================================

/**
 * Check and create PID file.
 *
 * Prevents multiple bridge instances from running simultaneously.
 * Handles stale PID files from crashed processes.
 *
 * @param string $pidFile Path to PID file
 * @throws RuntimeException if another instance is running
 */
function checkAndCreatePidFile(string $pidFile): void
{
    // Create directory if needed
    $pidDir = dirname($pidFile);
    if (!is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }

    // Check for existing PID file
    if (file_exists($pidFile)) {
        $oldPid = (int)trim(file_get_contents($pidFile));

        // Check if process is still running
        if (posix_kill($oldPid, 0)) {
            // Process exists
            throw new \RuntimeException(
                "Bridge is already running with PID $oldPid\n" .
                "PID file: $pidFile\n" .
                "Use 'fossibot-bridge-ctl stop' to stop it first."
            );
        }

        // Stale PID file - remove it
        echo "⚠️  Stale PID file found (process $oldPid not running), removing\n";
        unlink($pidFile);
    }

    // Write our PID
    $currentPid = getmypid();
    file_put_contents($pidFile, $currentPid);

    echo "✅ PID file created: $pidFile (PID: $currentPid)\n";

    // Register shutdown handler to remove PID file
    register_shutdown_function(function() use ($pidFile, $currentPid) {
        if (file_exists($pidFile)) {
            $filePid = (int)trim(file_get_contents($pidFile));

            // Only remove if it's still our PID (not overwritten)
            if ($filePid === $currentPid) {
                unlink($pidFile);
            }
        }
    });
}

/**
 * Get PID file path from config or use default.
 */
function getPidFilePath(array $config): string
{
    // Check config for custom path
    if (isset($config['daemon']['pid_file'])) {
        $pidFile = $config['daemon']['pid_file'];

        // Expand relative paths relative to script directory
        if (!str_starts_with($pidFile, '/')) {
            $pidFile = __DIR__ . '/' . $pidFile;
        }

        return $pidFile;
    }

    // Default: /var/run/fossibot/bridge.pid (production) or ./bridge.pid (dev)
    if (is_dir('/var/run/fossibot')) {
        return '/var/run/fossibot/bridge.pid';
    }

    return __DIR__ . '/bridge.pid';
}

// Check PID file before starting
try {
    $pidFile = getPidFilePath($config);
    checkAndCreatePidFile($pidFile);
} catch (\RuntimeException $e) {
    echo "\n❌ " . $e->getMessage() . "\n";
    exit(1);
}
```

**Location**: Insert this code block **after line 205** (after config validation, before logger setup at line 207).

**Done when**: PID file is created on start, prevents double-start, removes stale PID files

**Commit**: `feat(daemon): add PID file management to prevent double-start`

---

### Step 2: Config Changes (5min)

**File**: `config/example.json`
**Lines**: Add to `daemon` section (after line 17)

**Before**:
```json
  "daemon": {
    "log_file": "logs/bridge.log",
    "log_level": "info"
  },
```

**After**:
```json
  "daemon": {
    "log_file": "logs/bridge.log",
    "log_level": "info",
    "pid_file": "bridge.pid"
  },
```

**Note**: Use relative path for development (`bridge.pid`), absolute path for production (`/var/run/fossibot/bridge.pid`).

**Done when**: example.json contains `pid_file` configuration

**Commit**: `feat(config): add pid_file option to daemon configuration`

---

### Step 3: Test Double-Start Prevention (5min)

**Test Script**: `tests/test_pid_double_start.sh`

```bash
#!/bin/bash
# Test PID file double-start prevention

set -e

echo "=== PID Double-Start Prevention Test ==="
echo ""

# Cleanup
rm -f /tmp/test-bridge.pid

# Start first instance (background)
echo "--- Test 1: Starting first instance ---"
php daemon/fossibot-bridge.php --config config/config.json &
FIRST_PID=$!
echo "First instance PID: $FIRST_PID"
sleep 2

# Check PID file was created
if [ -f "bridge.pid" ]; then
    STORED_PID=$(cat bridge.pid)
    echo "✅ PID file created with PID: $STORED_PID"
else
    echo "❌ PID file not created"
    kill $FIRST_PID
    exit 1
fi

# Try to start second instance (should fail)
echo ""
echo "--- Test 2: Attempting second instance (should fail) ---"
if php daemon/fossibot-bridge.php --config config/config.json 2>&1 | grep -q "already running"; then
    echo "✅ Second instance prevented (correct)"
else
    echo "❌ Second instance was allowed (WRONG!)"
    kill $FIRST_PID
    exit 1
fi

# Stop first instance
echo ""
echo "--- Cleanup ---"
kill $FIRST_PID
sleep 1

# Check PID file was removed
if [ ! -f "bridge.pid" ]; then
    echo "✅ PID file removed on shutdown"
else
    echo "⚠️  PID file still exists (shutdown handler issue)"
    rm -f bridge.pid
fi

echo ""
echo "=== ✅ All PID Tests Passed ==="
```

**Run**:
```bash
chmod +x tests/test_pid_double_start.sh
./tests/test_pid_double_start.sh
```

**Expected output**:
```
=== PID Double-Start Prevention Test ===

--- Test 1: Starting first instance ---
First instance PID: 12345
✅ PID file created with PID: 12345

--- Test 2: Attempting second instance (should fail) ---
✅ Second instance prevented (correct)

--- Cleanup ---
✅ PID file removed on shutdown

=== ✅ All PID Tests Passed ===
```

**Done when**: Double-start is prevented and PID file is cleaned up on exit

**Commit**: `test(daemon): add PID double-start prevention test`

---

### Step 4: Test Stale PID Cleanup (5min)

**Test Script**: `tests/test_pid_stale.sh`

```bash
#!/bin/bash
# Test stale PID file cleanup

set -e

echo "=== Stale PID File Cleanup Test ==="
echo ""

# Create stale PID file with non-existent process
echo "--- Creating stale PID file ---"
mkdir -p /tmp/fossibot-test
echo "99999" > /tmp/fossibot-test/bridge.pid
echo "Stale PID file created: /tmp/fossibot-test/bridge.pid (PID: 99999)"

# Try to start bridge (should detect stale PID and remove it)
echo ""
echo "--- Starting bridge (should clean up stale PID) ---"

# Create minimal test config
cat > /tmp/fossibot-test-config.json << 'EOF'
{
  "accounts": [],
  "mosquitto": {
    "host": "localhost",
    "port": 1883
  },
  "daemon": {
    "log_file": "/tmp/fossibot-test/bridge.log",
    "log_level": "info",
    "pid_file": "/tmp/fossibot-test/bridge.pid"
  },
  "bridge": {
    "status_publish_interval": 60,
    "device_poll_interval": 30
  }
}
EOF

# Start bridge with test config (will fail due to no accounts, but PID handling runs first)
if php daemon/fossibot-bridge.php --config /tmp/fossibot-test-config.json 2>&1 | grep -q "Stale PID file"; then
    echo "✅ Stale PID detected and cleaned up"
else
    echo "❌ Stale PID not detected"
    exit 1
fi

# Check new PID file was created (before bridge exited)
# Note: Bridge will exit due to no accounts, that's expected

echo ""
echo "--- Cleanup ---"
rm -rf /tmp/fossibot-test
rm -f /tmp/fossibot-test-config.json

echo ""
echo "=== ✅ Stale PID Test Passed ==="
```

**Run**:
```bash
chmod +x tests/test_pid_stale.sh
./tests/test_pid_stale.sh
```

**Expected output**:
```
=== Stale PID File Cleanup Test ===

--- Creating stale PID file ---
Stale PID file created: /tmp/fossibot-test/bridge.pid (PID: 99999)

--- Starting bridge (should clean up stale PID) ---
⚠️  Stale PID file found (process 99999 not running), removing
✅ Stale PID detected and cleaned up

--- Cleanup ---

=== ✅ Stale PID Test Passed ===
```

**Done when**: Stale PID files are detected and removed automatically

**Commit**: `test(daemon): add stale PID cleanup test`

---

## Validation Checklist

After completing all steps, verify:

- ✅ PID file is created when bridge starts
- ✅ Second instance is prevented with clear error message
- ✅ Stale PID files (from crashed processes) are detected and removed
- ✅ PID file is removed on graceful shutdown
- ✅ Config contains `pid_file` option
- ✅ Both test scripts pass

---

## Manual Testing

```bash
# Test 1: Normal start
./daemon/fossibot-bridge.php --config config/config.json &
PID=$!
sleep 2

# Check PID file exists
cat bridge.pid
# Should show: <PID>

# Test 2: Try double-start
./daemon/fossibot-bridge.php --config config/config.json
# Should fail with "already running" message

# Test 3: Kill process (simulate crash)
kill -9 $PID
sleep 1

# PID file should still exist (no cleanup on SIGKILL)
ls -la bridge.pid
# Should exist

# Test 4: Start again (should detect stale PID)
./daemon/fossibot-bridge.php --config config/config.json &
NEW_PID=$!
# Should print: "Stale PID file found, removing"

# Cleanup
kill $NEW_PID
sleep 1
```

---

## Troubleshooting

### "Bridge is already running" but no process found

**Cause**: Stale PID file from crashed process

**Fix**:
```bash
# Manual cleanup
rm -f bridge.pid

# Or let bridge auto-cleanup
./daemon/fossibot-bridge.php --config config/config.json
# Will detect stale PID and remove it
```

### PID file not created

**Check**:
```bash
# Verify directory permissions
ls -la $(dirname bridge.pid)

# For production path:
ls -la /var/run/fossibot/
```

**Fix**:
```bash
# Create directory with correct permissions
sudo mkdir -p /var/run/fossibot
sudo chown fossibot:fossibot /var/run/fossibot
```

### PID file not removed on exit

**Cause**: Shutdown handler not called (e.g., SIGKILL)

**Expected behavior**: Normal (SIGKILL bypasses shutdown handlers)

**Solution**: Next startup will detect and remove stale PID

---

## Production Deployment

**Development** (`config/config.json`):
```json
"daemon": {
  "pid_file": "bridge.pid"
}
```
→ Creates `bridge.pid` in project root

**Production** (`/etc/fossibot/config.json`):
```json
"daemon": {
  "pid_file": "/var/run/fossibot/bridge.pid"
}
```
→ Creates PID in FHS-compliant location

**systemd** will create `/var/run/fossibot/` automatically via `RuntimeDirectory=fossibot` (Phase 6).

---

## Integration with Control Script (Phase 4)

PID file enables these operations in `fossibot-bridge-ctl`:

```bash
# Check if running
fossibot-bridge-ctl status
# Reads PID file, checks process

# Stop bridge
fossibot-bridge-ctl stop
# Reads PID file, sends SIGTERM

# Restart bridge
fossibot-bridge-ctl restart
# stop + start
```

---

## Next Steps

After Phase 3 completion:
- **Phase 4**: Control Script (uses PID file for status/stop/restart)
- **Phase 2**: Health Check Server (adds liveness probe)
- **Phase 6**: systemd Service (adds `RuntimeDirectory` for `/var/run/fossibot/`)

---

**Phase 3 Complete**: PID file management prevents double-start and enables control script operations.
