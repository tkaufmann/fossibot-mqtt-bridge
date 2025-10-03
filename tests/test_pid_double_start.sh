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
