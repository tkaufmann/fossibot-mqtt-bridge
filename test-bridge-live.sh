#!/bin/bash
# ABOUTME: Live test script for MQTT Bridge

echo "🚀 Starting Fossibot MQTT Bridge..."
echo "=================================="
echo ""

# Kill old bridge if running
if [ -f bridge.pid ]; then
    OLD_PID=$(cat bridge.pid)
    if ps -p $OLD_PID > /dev/null 2>&1; then
        echo "⚠️  Stopping old bridge (PID: $OLD_PID)..."
        kill $OLD_PID
        sleep 2
    fi
    rm -f bridge.pid
fi

# Start bridge
echo "▶️  Starting bridge..."
php daemon/fossibot-bridge.php \
    --config config/config.json \
    --log-level debug \
    > bridge-live.log 2>&1 &

BRIDGE_PID=$!
echo $BRIDGE_PID > bridge.pid

echo "✅ Bridge started (PID: $BRIDGE_PID)"
echo ""
echo "📋 Useful commands:"
echo "-------------------"
echo "# Watch logs:"
echo "  tail -f bridge-live.log"
echo ""
echo "# Subscribe to device state:"
echo "  mosquitto_sub -t 'fossibot/7C2C67AB5F0E/state' -v"
echo ""
echo "# Subscribe to bridge status:"
echo "  mosquitto_sub -t 'fossibot/bridge/status' -v"
echo ""
echo "# Send USB ON command:"
echo "  mosquitto_pub -t 'fossibot/7C2C67AB5F0E/command' -m '{\"usb_enabled\": true}'"
echo ""
echo "# Stop bridge:"
echo "  kill $(cat bridge.pid)"
echo ""

# Wait a moment for startup
sleep 3

echo "📊 First log entries:"
echo "--------------------"
head -20 bridge-live.log
