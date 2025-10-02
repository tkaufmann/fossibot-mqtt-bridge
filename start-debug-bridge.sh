#!/bin/bash
# ABOUTME: Start bridge in debug mode with clean log

# Stop all old bridges
pkill -f "fossibot-bridge.php" 2>/dev/null
sleep 1

# Clean up old files
rm -f bridge.pid bridge-debug.log

# Start bridge
php daemon/fossibot-bridge.php --config config/config.json > bridge-debug.log 2>&1 &
BRIDGE_PID=$!

# Save PID
echo "$BRIDGE_PID" > bridge.pid

# Wait for startup
sleep 2

echo "✅ Bridge started"
echo "   PID: $BRIDGE_PID"
echo "   Time: $(date '+%H:%M:%S')"
echo ""
echo "📊 Watching for polling timer..."
