# Phase 6: systemd Service Enhancement

**Time**: 30min
**Priority**: P0
**Dependencies**: Phase 3 (PID), Phase 5 (Install Scripts)

---

## Goal

Verbessere systemd Service-Datei mit:
- **RuntimeDirectory**: Automatische Erstellung von `/var/run/fossibot/`
- **StateDirectory**: Automatische Erstellung von `/var/lib/fossibot/`
- **LogsDirectory**: Automatische Erstellung von `/var/log/fossibot/`
- **Enhanced Security**: Additional hardening options
- **Resource Limits**: Memory/CPU limits
- **Restart Policy**: Improved failure handling

**Result**: Production-ready systemd service mit FHS-Compliance und Security Best Practices.

---

## Steps

### Step 1: Enhanced systemd Service File (20min)

**File**: `daemon/fossibot-bridge.service`
**Lines**: Replace entire file

**Before** (current version from reading):
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

**After** (enhanced version):
```ini
[Unit]
Description=Fossibot MQTT Bridge Daemon
Documentation=https://github.com/youruser/fossibot-php2
After=network-online.target mosquitto.service
Wants=network-online.target mosquitto.service
StartLimitIntervalSec=300
StartLimitBurst=5

[Service]
Type=simple
User=fossibot
Group=fossibot
WorkingDirectory=/opt/fossibot-bridge

# Execution
ExecStart=/usr/bin/php /opt/fossibot-bridge/daemon/fossibot-bridge.php --config /etc/fossibot/config.json
ExecReload=/bin/kill -HUP $MAINPID

# Restart policy
Restart=on-failure
RestartSec=10
TimeoutStartSec=60
TimeoutStopSec=30

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=fossibot-bridge

# Automatic directory management (systemd creates/removes these)
RuntimeDirectory=fossibot
RuntimeDirectoryMode=0755
StateDirectory=fossibot
StateDirectoryMode=0755
LogsDirectory=fossibot
LogsDirectoryMode=0755

# Security hardening - Filesystem
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/log/fossibot /var/lib/fossibot
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
ProtectKernelLogs=true
ProtectClock=true

# Security hardening - Namespacing
PrivateDevices=true
PrivateUsers=false
ProtectHostname=true
RestrictNamespaces=true
RestrictRealtime=true
RestrictSUIDSGID=true
LockPersonality=true

# Security hardening - System calls
SystemCallFilter=@system-service
SystemCallFilter=~@privileged @resources
SystemCallErrorNumber=EPERM
SystemCallArchitectures=native

# Security hardening - Network
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
IPAddressDeny=any
IPAddressAllow=localhost
IPAddressAllow=10.0.0.0/8
IPAddressAllow=172.16.0.0/12
IPAddressAllow=192.168.0.0/16

# Resource limits
LimitNOFILE=65536
MemoryMax=512M
MemoryHigh=384M
CPUQuota=200%
TasksMax=256

# Environment
Environment="PHP_MEMORY_LIMIT=256M"
Environment="TZ=Europe/Berlin"

# Process management
KillMode=mixed
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

**Key Changes**:

1. **Network**: `network-online.target` statt `network.target` (wartet auf vollständige Netzwerk-Konfiguration)
2. **Start Limits**: `StartLimitIntervalSec`/`StartLimitBurst` verhindert Boot-Loops
3. **Reload**: `ExecReload` für SIGHUP-basierte Config-Reloads
4. **Restart**: `on-failure` statt `always` (nur bei Fehler neu starten)
5. **Timeouts**: Explizite Start/Stop-Timeouts
6. **Directories**: `RuntimeDirectory`/`StateDirectory`/`LogsDirectory` - systemd erstellt automatisch
7. **Advanced Security**: Kernel/Syscall/Network-Filtering
8. **Resource Limits**: `MemoryHigh` (Soft Limit), `CPUQuota`, `TasksMax`
9. **Syslog**: `SyslogIdentifier` für besseres Logging

**Done when**: Enhanced service file contains all security hardening options

**Commit**: `feat(systemd): enhance service file with security hardening and auto-directories`

---

### Step 2: Update Installation Script (5min)

**File**: `scripts/install.sh`
**Lines**: Update `step_install_systemd_service()` function

**Replace** the entire function (around line 140) with:

```bash
step_install_systemd_service() {
    print_step "Installing systemd service"

    local service_file="$SYSTEMD_DIR/$SERVICE_NAME.service"

    # Copy from project instead of generating
    cp "$PROJECT_ROOT/daemon/fossibot-bridge.service" "$service_file"
    chmod 644 "$service_file"
    echo "   ✅ Service file installed: $service_file"

    systemctl daemon-reload
    echo "   ✅ systemd reloaded"
}
```

**Reason**: Use project's service file directly instead of generating inline.

**Done when**: install.sh uses service file from project

**Commit**: `refactor(install): use project service file instead of inline generation`

---

### Step 3: Test systemd Service (5min)

**Test Script**: `tests/test_systemd_service.sh`

```bash
#!/bin/bash
# Test systemd service functionality

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
```

**Run**:
```bash
chmod +x tests/test_systemd_service.sh
sudo ./tests/test_systemd_service.sh
```

**Expected output**:
```
=== systemd Service Test ===

--- Test 1: Service File Validity ---
✅ Service file valid

--- Test 2: Start Service ---
✅ Service started

--- Test 3: Runtime Directories ---
✅ RuntimeDirectory created: /var/run/fossibot
✅ StateDirectory created: /var/lib/fossibot
✅ LogsDirectory created: /var/log/fossibot

--- Test 4: Process Ownership ---
✅ Process running as fossibot user

--- Test 5: Restart Service ---
✅ Service restarted successfully

--- Test 6: Stop Service ---
✅ Service stopped

=== ✅ All systemd Service Tests Passed ===
```

**Done when**: All systemd service tests pass

**Commit**: `test(systemd): add comprehensive service functionality tests`

---

## Validation Checklist

After completing all steps, verify:

- ✅ Service file contains `RuntimeDirectory=fossibot`
- ✅ Service file contains `StateDirectory=fossibot`
- ✅ Service file contains `LogsDirectory=fossibot`
- ✅ Service file contains advanced security hardening
- ✅ Service starts successfully
- ✅ Directories auto-created by systemd
- ✅ Process runs as `fossibot` user
- ✅ Service restarts on failure
- ✅ Service stops gracefully

---

## systemd Directory Management

**Before** (manual creation in install.sh):
```bash
mkdir -p /var/run/fossibot
mkdir -p /var/lib/fossibot
mkdir -p /var/log/fossibot
chown fossibot:fossibot /var/run/fossibot
# etc.
```

**After** (systemd auto-manages):
```ini
RuntimeDirectory=fossibot    # Creates /var/run/fossibot
StateDirectory=fossibot      # Creates /var/lib/fossibot
LogsDirectory=fossibot       # Creates /var/log/fossibot
```

**Benefits**:
- Directories created on service start
- Removed on service stop (RuntimeDirectory only)
- Correct permissions automatically
- No manual cleanup needed

---

## Security Hardening Explanation

### Filesystem Protection

```ini
ProtectSystem=strict         # /usr, /boot, /efi read-only
ProtectHome=true             # No access to /home
ReadWritePaths=/var/log/fossibot /var/lib/fossibot  # Only these writable
ProtectKernelTunables=true   # /proc/sys read-only
ProtectKernelModules=true    # Can't load kernel modules
ProtectControlGroups=true    # /sys/fs/cgroup read-only
```

### Namespace Isolation

```ini
PrivateDevices=true          # No access to /dev (except std I/O)
PrivateUsers=false           # Keep UID mapping (needed for file access)
ProtectHostname=true         # Can't change hostname
RestrictNamespaces=true      # Can't create namespaces
```

### System Call Filtering

```ini
SystemCallFilter=@system-service  # Allow common service syscalls
SystemCallFilter=~@privileged     # Deny privileged syscalls
SystemCallErrorNumber=EPERM       # Return EPERM instead of ENOSYS
```

### Network Restrictions

```ini
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX  # Only TCP/UDP/Unix sockets
IPAddressDeny=any             # Deny all by default
IPAddressAllow=localhost      # Allow localhost
IPAddressAllow=10.0.0.0/8     # Allow private networks
```

---

## Resource Limits

```ini
MemoryMax=512M               # Hard limit - OOM kill if exceeded
MemoryHigh=384M              # Soft limit - throttle if exceeded
CPUQuota=200%                # Max 2 CPU cores
TasksMax=256                 # Max 256 processes/threads
LimitNOFILE=65536            # Max 65k open file descriptors
```

**Monitoring**:
```bash
# Check current resource usage
systemctl status fossibot-bridge

# Detailed resource info
systemd-cgtop

# Memory pressure
systemctl show fossibot-bridge -p MemoryCurrent
```

---

## Restart Policy

```ini
Restart=on-failure           # Only restart on non-zero exit code
RestartSec=10                # Wait 10s before restart
StartLimitIntervalSec=300    # Track restarts over 5 minutes
StartLimitBurst=5            # Max 5 restarts in interval
```

**Behavior**:
- Normal exit (0): No restart
- Crash/error: Restart after 10s
- 5 crashes in 5 minutes: Give up, enter failed state
- Manual stop: No restart

**View restart history**:
```bash
systemctl status fossibot-bridge
# Look for "Active: failed (Result: start-limit-hit)"

# Reset failure counter
systemctl reset-failed fossibot-bridge
```

---

## Troubleshooting

### Service fails with "Permission denied"

**Check** security settings:
```bash
# Temporarily disable hardening to identify issue
sudo systemctl edit fossibot-bridge

# Add:
[Service]
NoNewPrivileges=false
ProtectSystem=false
ProtectHome=false

# Restart
sudo systemctl daemon-reload
sudo systemctl restart fossibot-bridge
```

If it works → re-enable security options one by one to find culprit.

### Directories not created

**Check** service file loaded:
```bash
systemctl daemon-reload
systemctl cat fossibot-bridge | grep -E "(Runtime|State|Logs)Directory"
```

**Manual verification**:
```bash
# Start service
sudo systemctl start fossibot-bridge

# Check directories
ls -la /var/run/fossibot
ls -la /var/lib/fossibot
ls -la /var/log/fossibot
```

### Network restrictions too strict

**Symptom**: Can't connect to MQTT broker

**Fix**: Adjust IP whitelist:
```ini
IPAddressAllow=192.168.1.100  # Add broker IP
```

Or disable network filtering:
```bash
# In service file, remove:
# IPAddressDeny=any
# IPAddressAllow=...
```

### Memory limit too low

**Symptom**: OOM kills in journalctl

**Check**:
```bash
journalctl -u fossibot-bridge | grep -i "memory"
```

**Fix**: Increase limits in service file:
```ini
MemoryMax=1G
MemoryHigh=768M
```

---

## Manual Testing

```bash
# Install enhanced service
sudo scripts/install.sh

# Check service status
systemctl status fossibot-bridge

# Check auto-created directories
ls -la /var/run/fossibot
ls -la /var/lib/fossibot
ls -la /var/log/fossibot

# Verify permissions
stat /var/run/fossibot

# Check security options
systemd-analyze security fossibot-bridge

# Start service
sudo systemctl start fossibot-bridge

# Check resource usage
systemctl show fossibot-bridge -p MemoryCurrent -p CPUUsageNSec

# Trigger crash (for restart test)
sudo kill -SEGV $(systemctl show -p MainPID --value fossibot-bridge)

# Wait 10s, verify auto-restart
sleep 10
systemctl is-active fossibot-bridge  # Should be "active"

# Check restart count
systemctl show fossibot-bridge -p NRestarts

# Stop service
sudo systemctl stop fossibot-bridge
```

---

## systemd Security Score

**Check** security score:
```bash
systemd-analyze security fossibot-bridge
```

**Expected score**: ~7.5/10 (MEDIUM)

**Sample output**:
```
MEDIUM  ✓ PrivateDevices=true
MEDIUM  ✓ ProtectSystem=strict
HIGH    ✓ ProtectHome=true
MEDIUM  ✓ NoNewPrivileges=true
...
Overall exposure level: MEDIUM (7.5)
```

---

## Comparison: Before vs. After

| Feature | Before | After |
|---------|--------|-------|
| Directory Management | Manual in install.sh | Auto via systemd |
| Security Hardening | Basic | Advanced (syscall filtering) |
| Resource Limits | Memory only | Memory + CPU + Tasks |
| Restart Policy | Always restart | Smart (on-failure only) |
| Network Filtering | None | IP whitelist |
| Namespace Isolation | Minimal | Extensive |
| Logging | Basic | Syslog identifier |

---

## Next Steps

After Phase 6 completion:
- **Phase 7**: Documentation (systemd operations guide)
- **Production**: Deploy with enhanced service file

---

**Phase 6 Complete**: Production-ready systemd service with security hardening and automatic directory management.
