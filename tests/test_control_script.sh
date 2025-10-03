#!/bin/bash
# Test control script commands

set -e

SCRIPT="./bin/fossibot-bridge-ctl"

echo "=== Control Script Test ==="
echo ""

# Test 1: Help
echo "--- Test 1: Help ---"
if $SCRIPT help | grep -q "Usage:"; then
    echo "✅ Help command works"
else
    echo "❌ Help command failed"
    exit 1
fi

# Test 2: Validate
echo ""
echo "--- Test 2: Validate ---"
if $SCRIPT validate; then
    echo "✅ Validate command works"
else
    echo "❌ Validate command failed"
    exit 1
fi

# Test 3: Start
echo ""
echo "--- Test 3: Start ---"
$SCRIPT start
sleep 3

# Test 4: Status (should be running)
echo ""
echo "--- Test 4: Status (running) ---"
if $SCRIPT status | grep -q "running"; then
    echo "✅ Status shows running"
else
    echo "❌ Status doesn't show running"
    $SCRIPT stop
    exit 1
fi

# Test 5: Logs
echo ""
echo "--- Test 5: Logs ---"
if $SCRIPT logs 10 | grep -q "fossibot"; then
    echo "✅ Logs command works"
else
    echo "❌ Logs command failed"
fi

# Test 6: Restart
echo ""
echo "--- Test 6: Restart ---"
$SCRIPT restart
sleep 3

if $SCRIPT status | grep -q "running"; then
    echo "✅ Restart works"
else
    echo "❌ Restart failed"
    exit 1
fi

# Test 7: Stop
echo ""
echo "--- Test 7: Stop ---"
$SCRIPT stop
sleep 2

# Test 8: Status (should be stopped)
echo ""
echo "--- Test 8: Status (stopped) ---"
if $SCRIPT status | grep -q "not running"; then
    echo "✅ Status shows stopped"
else
    echo "❌ Status doesn't show stopped"
    exit 1
fi

echo ""
echo "=== ✅ All Control Script Tests Passed ==="
