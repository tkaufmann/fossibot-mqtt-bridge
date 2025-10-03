# Phase 4: Control Script

**Time**: 1h 0min
**Priority**: P0
**Dependencies**: Phase 3 (PID Management)

---

## Goal

Erstelle `fossibot-bridge-ctl` - ein CLI-Wrapper für Bridge-Management:
- Einheitliche Befehle: `start`, `stop`, `restart`, `status`, `logs`, `validate`, `health`
- Funktioniert sowohl in Development als auch Production (systemd)
- Auto-Detection: systemd vs. direkter Aufruf
- Schönes Output-Format mit Farben und Status-Icons

**Why P0**: Essentiell für Production Operations - vereinfacht Administration massiv.

---

## Architecture Decision

**Two Modes**:
1. **systemd Mode** (Production): Wrapper um `systemctl` Befehle
2. **Direct Mode** (Development): Direkter PHP-Aufruf mit PID-Management

**Auto-Detection**:
```bash
if systemctl list-units --type=service | grep -q fossibot-bridge; then
    # systemd mode
else
    # direct mode
fi
```

---

## Steps

### Step 1: Create bin/fossibot-bridge-ctl (40min)

**File**: `bin/fossibot-bridge-ctl`
**Lines**: New file

```bash
#!/bin/bash
# ABOUTME: Control script for Fossibot MQTT Bridge daemon management
# Provides unified CLI for start/stop/restart/status/logs operations

set -e

# =============================================================================
# CONFIGURATION
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SERVICE_NAME="fossibot-bridge"
DEFAULT_CONFIG="/etc/fossibot/config.json"

# Development fallback
if [ ! -f "$DEFAULT_CONFIG" ]; then
    DEFAULT_CONFIG="$PROJECT_ROOT/config/config.json"
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# =============================================================================
# HELPER FUNCTIONS
# =============================================================================

print_usage() {
    cat << EOF
Usage: fossibot-bridge-ctl <command> [options]

Commands:
  start           Start the bridge daemon
  stop            Stop the bridge daemon
  restart         Restart the bridge daemon
  status          Show daemon status
  logs [lines]    Show daemon logs (default: 50 lines)
  validate        Validate configuration
  health          Check health endpoint (requires bridge running)
  help            Show this help message

Examples:
  fossibot-bridge-ctl start
  fossibot-bridge-ctl status
  fossibot-bridge-ctl logs 100
  fossibot-bridge-ctl validate

Mode Detection:
  - If systemd service exists: Uses systemctl commands
  - Otherwise: Uses direct PHP daemon calls
EOF
}

is_systemd_available() {
    systemctl list-units --type=service 2>/dev/null | grep -q "$SERVICE_NAME" || return 1
}

get_pid_from_file() {
    local pid_file="$1"

    if [ ! -f "$pid_file" ]; then
        return 1
    fi

    local pid=$(cat "$pid_file" 2>/dev/null)
    if [ -z "$pid" ]; then
        return 1
    fi

    # Check if process exists
    if ! kill -0 "$pid" 2>/dev/null; then
        return 1
    fi

    echo "$pid"
}

get_pid_file() {
    # Try to extract from config
    if [ -f "$DEFAULT_CONFIG" ]; then
        local pid_file=$(jq -r '.daemon.pid_file // empty' "$DEFAULT_CONFIG" 2>/dev/null)

        if [ -n "$pid_file" ]; then
            # Expand relative paths
            if [[ "$pid_file" != /* ]]; then
                pid_file="$PROJECT_ROOT/$pid_file"
            fi
            echo "$pid_file"
            return 0
        fi
    fi

    # Default locations
    if [ -f "/var/run/fossibot/bridge.pid" ]; then
        echo "/var/run/fossibot/bridge.pid"
    else
        echo "$PROJECT_ROOT/bridge.pid"
    fi
}

get_log_file() {
    # Try to extract from config
    if [ -f "$DEFAULT_CONFIG" ]; then
        local log_file=$(jq -r '.daemon.log_file // empty' "$DEFAULT_CONFIG" 2>/dev/null)

        if [ -n "$log_file" ]; then
            # Expand relative paths
            if [[ "$log_file" != /* ]]; then
                log_file="$PROJECT_ROOT/$log_file"
            fi
            echo "$log_file"
            return 0
        fi
    fi

    # Default location
    echo "$PROJECT_ROOT/logs/bridge.log"
}

# =============================================================================
# SYSTEMD MODE COMMANDS
# =============================================================================

systemd_start() {
    echo -e "${BLUE}Starting $SERVICE_NAME via systemd...${NC}"
    sudo systemctl start "$SERVICE_NAME"
    sleep 1
    systemd_status
}

systemd_stop() {
    echo -e "${BLUE}Stopping $SERVICE_NAME via systemd...${NC}"
    sudo systemctl stop "$SERVICE_NAME"
    echo -e "${GREEN}✅ Service stopped${NC}"
}

systemd_restart() {
    echo -e "${BLUE}Restarting $SERVICE_NAME via systemd...${NC}"
    sudo systemctl restart "$SERVICE_NAME"
    sleep 1
    systemd_status
}

systemd_status() {
    if systemctl is-active --quiet "$SERVICE_NAME"; then
        echo -e "${GREEN}✅ $SERVICE_NAME is running${NC}"

        # Get PID from systemctl
        local pid=$(systemctl show -p MainPID --value "$SERVICE_NAME")
        if [ "$pid" != "0" ]; then
            echo -e "   PID: $pid"

            # Show uptime
            local uptime=$(systemctl show -p ActiveEnterTimestamp --value "$SERVICE_NAME")
            echo -e "   Started: $uptime"
        fi

        # Show recent logs
        echo ""
        echo -e "${BLUE}Recent logs:${NC}"
        sudo journalctl -u "$SERVICE_NAME" -n 5 --no-pager
    else
        echo -e "${RED}❌ $SERVICE_NAME is not running${NC}"

        # Check if failed
        if systemctl is-failed --quiet "$SERVICE_NAME"; then
            echo -e "${YELLOW}⚠️  Service is in failed state${NC}"
            echo ""
            echo -e "${BLUE}Last logs:${NC}"
            sudo journalctl -u "$SERVICE_NAME" -n 10 --no-pager
        fi
        return 1
    fi
}

systemd_logs() {
    local lines="${1:-50}"
    echo -e "${BLUE}Showing last $lines lines from journalctl...${NC}"
    sudo journalctl -u "$SERVICE_NAME" -n "$lines" --no-pager
}

# =============================================================================
# DIRECT MODE COMMANDS
# =============================================================================

direct_start() {
    local pid_file=$(get_pid_file)

    # Check if already running
    if pid=$(get_pid_from_file "$pid_file"); then
        echo -e "${YELLOW}⚠️  Bridge is already running (PID: $pid)${NC}"
        return 1
    fi

    echo -e "${BLUE}Starting bridge in background...${NC}"
    echo -e "   Config: $DEFAULT_CONFIG"
    echo -e "   PID file: $pid_file"

    # Start in background
    nohup php "$PROJECT_ROOT/daemon/fossibot-bridge.php" \
        --config "$DEFAULT_CONFIG" \
        >> "$(get_log_file)" 2>&1 &

    sleep 2

    # Check if started successfully
    if pid=$(get_pid_from_file "$pid_file"); then
        echo -e "${GREEN}✅ Bridge started (PID: $pid)${NC}"
    else
        echo -e "${RED}❌ Bridge failed to start${NC}"
        echo -e "${YELLOW}Check logs: $(get_log_file)${NC}"
        return 1
    fi
}

direct_stop() {
    local pid_file=$(get_pid_file)

    if ! pid=$(get_pid_from_file "$pid_file"); then
        echo -e "${YELLOW}⚠️  Bridge is not running${NC}"
        return 1
    fi

    echo -e "${BLUE}Stopping bridge (PID: $pid)...${NC}"

    # Send SIGTERM for graceful shutdown
    kill -TERM "$pid"

    # Wait for shutdown (max 10 seconds)
    for i in {1..10}; do
        if ! kill -0 "$pid" 2>/dev/null; then
            echo -e "${GREEN}✅ Bridge stopped${NC}"
            return 0
        fi
        sleep 1
    done

    # Force kill if still running
    echo -e "${YELLOW}⚠️  Graceful shutdown timed out, forcing kill...${NC}"
    kill -KILL "$pid" 2>/dev/null || true
    echo -e "${GREEN}✅ Bridge killed${NC}"
}

direct_restart() {
    echo -e "${BLUE}Restarting bridge...${NC}"
    direct_stop || true
    sleep 1
    direct_start
}

direct_status() {
    local pid_file=$(get_pid_file)

    if pid=$(get_pid_from_file "$pid_file"); then
        echo -e "${GREEN}✅ Bridge is running${NC}"
        echo -e "   PID: $pid"
        echo -e "   PID file: $pid_file"

        # Show uptime
        local start_time=$(ps -p "$pid" -o lstart=)
        echo -e "   Started: $start_time"

        # Show memory usage
        local mem=$(ps -p "$pid" -o rss= | awk '{print int($1/1024)"M"}')
        echo -e "   Memory: $mem"

        return 0
    else
        echo -e "${RED}❌ Bridge is not running${NC}"

        # Check for stale PID file
        if [ -f "$pid_file" ]; then
            echo -e "${YELLOW}⚠️  Stale PID file found: $pid_file${NC}"
        fi

        return 1
    fi
}

direct_logs() {
    local lines="${1:-50}"
    local log_file=$(get_log_file)

    if [ ! -f "$log_file" ]; then
        echo -e "${RED}❌ Log file not found: $log_file${NC}"
        return 1
    fi

    echo -e "${BLUE}Showing last $lines lines from $log_file...${NC}"
    tail -n "$lines" "$log_file"
}

# =============================================================================
# SHARED COMMANDS
# =============================================================================

cmd_validate() {
    echo -e "${BLUE}Validating configuration: $DEFAULT_CONFIG${NC}"

    if [ ! -f "$DEFAULT_CONFIG" ]; then
        echo -e "${RED}❌ Config file not found: $DEFAULT_CONFIG${NC}"
        return 1
    fi

    # Run daemon with --validate flag
    if php "$PROJECT_ROOT/daemon/fossibot-bridge.php" \
        --config "$DEFAULT_CONFIG" \
        --validate 2>&1; then
        echo -e "${GREEN}✅ Configuration valid${NC}"
        return 0
    else
        echo -e "${RED}❌ Configuration validation failed${NC}"
        return 1
    fi
}

cmd_health() {
    local health_port="${FOSSIBOT_HEALTH_PORT:-8080}"
    local health_url="http://localhost:$health_port/health"

    echo -e "${BLUE}Checking health endpoint: $health_url${NC}"

    if ! command -v curl &> /dev/null; then
        echo -e "${YELLOW}⚠️  curl not found, cannot check health${NC}"
        return 1
    fi

    local response=$(curl -s -w "\n%{http_code}" "$health_url" 2>/dev/null)
    local http_code=$(echo "$response" | tail -n1)
    local body=$(echo "$response" | head -n-1)

    if [ "$http_code" = "200" ]; then
        echo -e "${GREEN}✅ Health check passed${NC}"
        echo "$body" | jq '.' 2>/dev/null || echo "$body"
        return 0
    else
        echo -e "${RED}❌ Health check failed (HTTP $http_code)${NC}"
        echo "$body"
        return 1
    fi
}

# =============================================================================
# MAIN COMMAND DISPATCHER
# =============================================================================

main() {
    local command="${1:-help}"

    # Check for jq (needed for config parsing)
    if ! command -v jq &> /dev/null; then
        echo -e "${YELLOW}⚠️  Warning: jq not found, some features may not work${NC}"
    fi

    # Detect mode
    local use_systemd=false
    if is_systemd_available; then
        use_systemd=true
        echo -e "${BLUE}Mode: systemd${NC}"
    else
        echo -e "${BLUE}Mode: direct${NC}"
    fi

    case "$command" in
        start)
            if [ "$use_systemd" = true ]; then
                systemd_start
            else
                direct_start
            fi
            ;;
        stop)
            if [ "$use_systemd" = true ]; then
                systemd_stop
            else
                direct_stop
            fi
            ;;
        restart)
            if [ "$use_systemd" = true ]; then
                systemd_restart
            else
                direct_restart
            fi
            ;;
        status)
            if [ "$use_systemd" = true ]; then
                systemd_status
            else
                direct_status
            fi
            ;;
        logs)
            local lines="${2:-50}"
            if [ "$use_systemd" = true ]; then
                systemd_logs "$lines"
            else
                direct_logs "$lines"
            fi
            ;;
        validate)
            cmd_validate
            ;;
        health)
            cmd_health
            ;;
        help|--help|-h)
            print_usage
            ;;
        *)
            echo -e "${RED}❌ Unknown command: $command${NC}"
            echo ""
            print_usage
            exit 1
            ;;
    esac
}

main "$@"
```

**Done when**: Control script exists and has all commands implemented

**Commit**: `feat(bin): add fossibot-bridge-ctl control script`

---

### Step 2: Make Executable and Install (10min)

**Commands**:
```bash
# Make executable
chmod +x bin/fossibot-bridge-ctl

# Test local execution
./bin/fossibot-bridge-ctl --help

# For production: Install to /usr/local/bin
sudo cp bin/fossibot-bridge-ctl /usr/local/bin/
sudo chmod +x /usr/local/bin/fossibot-bridge-ctl

# Now globally available
fossibot-bridge-ctl status
```

**Done when**: Script is executable and can be run from anywhere

**Commit**: `chore(bin): make fossibot-bridge-ctl executable`

---

### Step 3: Test All Commands (10min)

**Test Script**: `tests/test_control_script.sh`

```bash
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
```

**Run**:
```bash
chmod +x tests/test_control_script.sh
./tests/test_control_script.sh
```

**Expected output**:
```
=== Control Script Test ===

--- Test 1: Help ---
✅ Help command works

--- Test 2: Validate ---
✅ Validate command works

--- Test 3: Start ---
✅ Bridge started (PID: 12345)

--- Test 4: Status (running) ---
✅ Status shows running

--- Test 5: Logs ---
✅ Logs command works

--- Test 6: Restart ---
✅ Restart works

--- Test 7: Stop ---
✅ Bridge stopped

--- Test 8: Status (stopped) ---
✅ Status shows stopped

=== ✅ All Control Script Tests Passed ===
```

**Done when**: All control script commands work correctly

**Commit**: `test(bin): add comprehensive control script tests`

---

## Validation Checklist

After completing all steps, verify:

- ✅ `fossibot-bridge-ctl help` shows usage
- ✅ `fossibot-bridge-ctl validate` validates config
- ✅ `fossibot-bridge-ctl start` starts bridge
- ✅ `fossibot-bridge-ctl status` shows running status
- ✅ `fossibot-bridge-ctl logs` displays logs
- ✅ `fossibot-bridge-ctl restart` restarts bridge
- ✅ `fossibot-bridge-ctl stop` stops bridge
- ✅ Auto-detects systemd vs. direct mode
- ✅ Script is globally executable from `/usr/local/bin`

---

## Usage Examples

### Development Workflow

```bash
# Validate config changes
fossibot-bridge-ctl validate

# Start bridge
fossibot-bridge-ctl start

# Check status
fossibot-bridge-ctl status

# Watch logs
fossibot-bridge-ctl logs 100

# Restart after code changes
fossibot-bridge-ctl restart

# Stop bridge
fossibot-bridge-ctl stop
```

### Production Workflow

```bash
# Check health (requires Phase 2)
fossibot-bridge-ctl health

# View systemd logs
fossibot-bridge-ctl logs 500

# Restart after config update
fossibot-bridge-ctl restart

# Quick status check
fossibot-bridge-ctl status
```

---

## Troubleshooting

### "jq not found" warning

**Install jq**:
```bash
# Ubuntu/Debian
sudo apt-get install jq

# macOS
brew install jq
```

### Control script can't find config

**Check** default locations:
```bash
# Production
ls -la /etc/fossibot/config.json

# Development
ls -la config/config.json
```

**Override** with environment variable:
```bash
export FOSSIBOT_CONFIG=/path/to/config.json
fossibot-bridge-ctl start
```

### "Permission denied" when starting

**Development**:
```bash
# Ensure log directory is writable
mkdir -p logs
chmod 755 logs
```

**Production**:
```bash
# Ensure fossibot user owns directories
sudo chown -R fossibot:fossibot /var/log/fossibot
sudo chown -R fossibot:fossibot /var/lib/fossibot
```

### systemd mode not detected

**Check** service exists:
```bash
systemctl list-units --type=service | grep fossibot
```

If not found → runs in direct mode (expected before Phase 6)

---

## Integration with systemd (Phase 6)

In Production, control script automatically uses systemd:

```bash
fossibot-bridge-ctl start
# → sudo systemctl start fossibot-bridge

fossibot-bridge-ctl status
# → systemctl status fossibot-bridge

fossibot-bridge-ctl logs
# → sudo journalctl -u fossibot-bridge
```

---

## Tab Completion (Optional Enhancement)

**File**: `/etc/bash_completion.d/fossibot-bridge-ctl`

```bash
_fossibot_bridge_ctl_completions()
{
    local cur="${COMP_WORDS[COMP_CWORD]}"
    local commands="start stop restart status logs validate health help"

    COMPREPLY=($(compgen -W "$commands" -- "$cur"))
}

complete -F _fossibot_bridge_ctl_completions fossibot-bridge-ctl
```

**Install**:
```bash
sudo cp /path/to/completion /etc/bash_completion.d/fossibot-bridge-ctl
source /etc/bash_completion.d/fossibot-bridge-ctl
```

**Usage**:
```bash
fossibot-bridge-ctl <TAB><TAB>
# Shows: start stop restart status logs validate health help
```

---

## Next Steps

After Phase 4 completion:
- **Phase 2**: Health Check Server (enables `health` command)
- **Phase 5**: Installation Scripts (automated setup)
- **Phase 6**: systemd Service (control script auto-switches to systemd mode)

---

**Phase 4 Complete**: Unified control script operational, works in both development and production modes.
