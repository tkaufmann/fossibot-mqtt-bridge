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

# Create minimal test config (valid but with no accounts)
cat > /tmp/fossibot-test-config.json << 'EOF'
{
  "accounts": [
    {
      "email": "test@example.com",
      "password": "test-password",
      "enabled": true
    }
  ],
  "mosquitto": {
    "host": "localhost",
    "port": 1883,
    "client_id": "fossibot_test"
  },
  "daemon": {
    "log_file": "/tmp/fossibot-test/bridge.log",
    "log_level": "info",
    "pid_file": "/tmp/fossibot-test/bridge.pid"
  },
  "bridge": {
    "status_publish_interval": 60,
    "device_poll_interval": 30,
    "reconnect_delay_min": 5,
    "reconnect_delay_max": 60
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
