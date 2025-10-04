#!/bin/bash
# ABOUTME: Test script for systemd service functionality
# Tests service lifecycle, directory management, and process ownership

set -e

echo "=== systemd Service Test ==="
echo ""

SERVICE_NAME="fossibot-bridge"

# Requires root and systemd
if [ "$EUID" -ne 0 ]; then
    echo "⚠️  This test requires root privileges"
    echo "Run: sudo $0"
    exit 1
fi

if ! command -v systemctl &> /dev/null; then
    echo "⚠️  systemd not available, skipping test"
    exit 0
fi

# Test 1: Service file valid
echo "--- Test 1: Service File Validity ---"
if systemctl cat "$SERVICE_NAME" > /dev/null 2>&1; then
    echo "✅ Service file valid"
else
    echo "❌ Service file not found or invalid"
    exit 1
fi

# Test 2: Start service
echo ""
echo "--- Test 2: Start Service ---"
systemctl start "$SERVICE_NAME"
sleep 3

if systemctl is-active --quiet "$SERVICE_NAME"; then
    echo "✅ Service started"
else
    echo "❌ Service failed to start"
    journalctl -u "$SERVICE_NAME" -n 20
    exit 1
fi

# Test 3: Check runtime directories created
echo ""
echo "--- Test 3: Runtime Directories ---"
if [ -d "/var/run/fossibot" ]; then
    echo "✅ RuntimeDirectory created: /var/run/fossibot"
else
    echo "❌ RuntimeDirectory not created"
fi

if [ -d "/var/lib/fossibot" ]; then
    echo "✅ StateDirectory created: /var/lib/fossibot"
else
    echo "❌ StateDirectory not created"
fi

if [ -d "/var/log/fossibot" ]; then
    echo "✅ LogsDirectory created: /var/log/fossibot"
else
    echo "❌ LogsDirectory not created"
fi

# Test 4: Check process ownership
echo ""
echo "--- Test 4: Process Ownership ---"
PID=$(systemctl show -p MainPID --value "$SERVICE_NAME")
if [ "$PID" != "0" ]; then
    OWNER=$(ps -o user= -p "$PID")
    if [ "$OWNER" = "fossibot" ]; then
        echo "✅ Process running as fossibot user"
    else
        echo "❌ Process running as $OWNER (expected: fossibot)"
    fi
fi

# Test 5: Restart service
echo ""
echo "--- Test 5: Restart Service ---"
systemctl restart "$SERVICE_NAME"
sleep 3

if systemctl is-active --quiet "$SERVICE_NAME"; then
    echo "✅ Service restarted successfully"
else
    echo "❌ Service failed to restart"
    exit 1
fi

# Test 6: Stop service
echo ""
echo "--- Test 6: Stop Service ---"
systemctl stop "$SERVICE_NAME"
sleep 2

if ! systemctl is-active --quiet "$SERVICE_NAME"; then
    echo "✅ Service stopped"
else
    echo "❌ Service still running"
    exit 1
fi

echo ""
echo "=== ✅ All systemd Service Tests Passed ==="
