# Phase 7: User Documentation

**Time**: 1h 0min
**Priority**: P0
**Dependencies**: All previous phases

---

## Goal

Erstelle vollständige User/Admin-Dokumentation:
- **INSTALL.md**: Installation Guide für Production
- **UPGRADE.md**: Upgrade-Prozess mit Downtime-Minimierung
- **TROUBLESHOOTING.md**: Häufige Probleme und Lösungen
- **OPERATIONS.md**: Daily Operations Guide

**Zielgruppe**: System Administrators, nicht Entwickler.

---

## Steps

### Step 1: INSTALL.md - Installation Guide (20min)

**File**: `docs/INSTALL.md`
**Lines**: New file

```markdown
# Installation Guide

Fossibot MQTT Bridge - Production Installation auf Ubuntu 24.04 LTS

---

## Prerequisites

### System Requirements

- Ubuntu 24.04 LTS (empfohlen) oder Debian 12+
- PHP 8.2 oder höher
- Mosquitto MQTT Broker (lokal oder remote)
- 512 MB RAM (empfohlen: 1 GB)
- 100 MB Festplattenspeicher

### Software Dependencies

```bash
sudo apt-get update
sudo apt-get install -y \
    php8.2-cli \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-curl \
    composer \
    git \
    jq \
    mosquitto \
    mosquitto-clients
```

### Fossibot Account

- Aktiver Fossibot Account (Smartphone App)
- Email und Passwort
- Mindestens ein registriertes Gerät (F2400, F3000, etc.)

---

## Installation

### Method 1: Automated Install (Recommended)

```bash
# 1. Clone Repository
cd /opt
sudo git clone https://github.com/youruser/fossibot-php2.git
cd fossibot-php2

# 2. Install Dependencies
composer install --no-dev --optimize-autoloader

# 3. Run Installer
sudo scripts/install.sh
```

**Der Installer erstellt**:
- System-User `fossibot` (no shell, no home)
- Verzeichnisse: `/etc/fossibot`, `/var/log/fossibot`, `/var/lib/fossibot`
- systemd Service: `fossibot-bridge.service`
- Control Script: `/usr/local/bin/fossibot-bridge-ctl`

### Method 2: Manual Install

Siehe [docs/deployment/05_PHASE_INSTALL.md](deployment/05_PHASE_INSTALL.md) für Details.

---

## Configuration

### Edit Config File

```bash
sudo nano /etc/fossibot/config.json
```

**Minimal Config**:
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
  }
}
```

**Multi-Account Setup**:
```json
{
  "accounts": [
    {
      "email": "account1@example.com",
      "password": "password1",
      "enabled": true
    },
    {
      "email": "account2@example.com",
      "password": "password2",
      "enabled": false
    }
  ]
}
```

### Validate Configuration

```bash
fossibot-bridge-ctl validate
```

**Expected output**:
```
✅ Validation complete
```

---

## Start Service

### Enable Auto-Start

```bash
sudo systemctl enable fossibot-bridge
```

### Start Service

```bash
fossibot-bridge-ctl start
```

Or directly via systemd:
```bash
sudo systemctl start fossibot-bridge
```

### Verify Running

```bash
fossibot-bridge-ctl status
```

**Expected output**:
```
✅ fossibot-bridge is running
   PID: 12345
   Started: Mon 2025-10-03 10:30:00 CEST
```

---

## Verify MQTT Communication

### Subscribe to Topics

```bash
# In Terminal 1: Subscribe to all Fossibot topics
mosquitto_sub -h localhost -t 'fossibot/#' -v

# Expected output (every 30s):
# fossibot/7C2C67AB5F0E/state {"soc":85.4,"inputWatts":0,"outputWatts":45,...}
```

### Send Command

```bash
# In Terminal 2: Turn USB output on
mosquitto_pub -h localhost \
    -t 'fossibot/7C2C67AB5F0E/command' \
    -m '{"action":"usb","value":true}'

# Check Terminal 1 for state update
```

---

## Check Logs

```bash
# Last 50 lines
fossibot-bridge-ctl logs

# Live tail
fossibot-bridge-ctl logs 100 | tail -f

# Or via journalctl
sudo journalctl -u fossibot-bridge -f
```

---

## Health Check

```bash
fossibot-bridge-ctl health
```

**Expected output**:
```json
{
  "status": "healthy",
  "uptime": 3600,
  "accounts": {
    "total": 1,
    "connected": 1,
    "disconnected": 0
  },
  "devices": {
    "total": 2,
    "online": 2,
    "offline": 0
  }
}
```

---

## Security Considerations

### File Permissions

```bash
# Config (contains credentials)
sudo chmod 640 /etc/fossibot/config.json
sudo chown root:fossibot /etc/fossibot/config.json

# Verify
ls -la /etc/fossibot/config.json
# Should show: -rw-r----- 1 root fossibot
```

### Firewall (if exposing health endpoint)

```bash
# Only allow from monitoring server
sudo ufw allow from 192.168.1.100 to any port 8080
```

### Mosquitto Security

See [Mosquitto Documentation](https://mosquitto.org/documentation/authentication-methods/) for:
- Password authentication
- TLS encryption
- Access Control Lists (ACL)

---

## Integration with Home Assistant

### MQTT Discovery

Bridge publishes device states to:
```
fossibot/{MAC}/state
```

**Home Assistant Config** (`configuration.yaml`):
```yaml
mqtt:
  sensor:
    - name: "Fossibot Battery"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.soc }}"
      unit_of_measurement: "%"
      device_class: battery

    - name: "Fossibot Output Power"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.outputWatts }}"
      unit_of_measurement: "W"
      device_class: power
```

**Command Switches**:
```yaml
mqtt:
  switch:
    - name: "Fossibot USB"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      payload_on: '{"action":"usb","value":true}'
      payload_off: '{"action":"usb","value":false}'
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.usbOutput }}"
```

---

## Monitoring Integration

### Prometheus

**Health Endpoint**: `http://localhost:8080/health`

**Node Exporter Textfile Collector**:
```bash
# Create exporter script
cat > /usr/local/bin/fossibot-metrics.sh << 'EOF'
#!/bin/bash
curl -s http://localhost:8080/health | jq -r '
  "fossibot_uptime_seconds \(.uptime)",
  "fossibot_accounts_total \(.accounts.total)",
  "fossibot_accounts_connected \(.accounts.connected)",
  "fossibot_devices_total \(.devices.total)",
  "fossibot_devices_online \(.devices.online)",
  "fossibot_memory_usage_mb \(.memory.usage_mb)"
' > /var/lib/node_exporter/textfile_collector/fossibot.prom
EOF

chmod +x /usr/local/bin/fossibot-metrics.sh

# Add to cron
echo "* * * * * /usr/local/bin/fossibot-metrics.sh" | sudo crontab -
```

### Nagios/Icinga

```bash
# Install check plugin
sudo cp docs/examples/check_fossibot_health.sh /usr/lib/nagios/plugins/
sudo chmod +x /usr/lib/nagios/plugins/check_fossibot_health.sh

# Add service check
# (See your Nagios/Icinga documentation)
```

---

## Troubleshooting

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for detailed solutions.

**Quick Checks**:

```bash
# Service not starting?
sudo journalctl -u fossibot-bridge -n 50

# Config validation failed?
fossibot-bridge-ctl validate

# MQTT not working?
mosquitto_sub -h localhost -t 'fossibot/#' -v

# Health check failing?
curl http://localhost:8080/health | jq '.'
```

---

## Uninstallation

```bash
# Stop service
fossibot-bridge-ctl stop

# Uninstall (keep config and logs)
sudo scripts/uninstall.sh --preserve-config --preserve-logs

# Complete removal
sudo scripts/uninstall.sh
```

---

## Next Steps

- [UPGRADE.md](UPGRADE.md) - Upgrading to newer versions
- [OPERATIONS.md](OPERATIONS.md) - Daily operations guide
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Problem solving

---

**Support**: GitHub Issues - https://github.com/youruser/fossibot-php2/issues
```

**Done when**: INSTALL.md provides complete installation guide

**Commit**: `docs: add production installation guide`

---

### Step 2: UPGRADE.md - Upgrade Guide (15min)

**File**: `docs/UPGRADE.md`
**Lines**: New file

```markdown
# Upgrade Guide

Fossibot MQTT Bridge - In-Place Upgrade mit minimaler Downtime

---

## Before Upgrading

### 1. Check Current Version

```bash
cd /opt/fossibot-php2
git log -1 --oneline
# Note the commit hash
```

### 2. Backup Configuration

```bash
# Automatic backup in upgrade script, but manual backup recommended
sudo cp /etc/fossibot/config.json /etc/fossibot/config.json.backup
```

### 3. Check Service Status

```bash
fossibot-bridge-ctl status
# Ensure it's running before upgrade
```

---

## Upgrade Process

### Method 1: Automated Upgrade (Recommended)

```bash
# 1. Navigate to installation
cd /opt/fossibot-php2

# 2. Pull latest code
sudo git pull origin main

# 3. Update dependencies
sudo composer install --no-dev --optimize-autoloader

# 4. Run upgrade script
sudo scripts/upgrade.sh
```

**The upgrade script will**:
1. Stop the service
2. Create backup in `/tmp/fossibot-backup-TIMESTAMP/`
3. Show config diff (new/removed options)
4. Update application files
5. Update systemd service
6. Validate configuration
7. Restart service

**Expected downtime**: ~10 seconds

### Method 2: Manual Upgrade

```bash
# 1. Stop service
fossibot-bridge-ctl stop

# 2. Backup
sudo cp -r /opt/fossibot-bridge /tmp/fossibot-backup

# 3. Update code
cd /opt/fossibot-php2
sudo git pull

# 4. Update dependencies
sudo composer install --no-dev

# 5. Copy to installation
sudo cp -r src daemon vendor /opt/fossibot-bridge/

# 6. Update service file
sudo cp daemon/fossibot-bridge.service /etc/systemd/system/
sudo systemctl daemon-reload

# 7. Start service
fossibot-bridge-ctl start
```

---

## Config Changes

### Viewing Config Diff

During upgrade, the script shows:

```
New config options available:
  + cache.directory = /var/lib/fossibot
  + cache.token_ttl_safety_margin = 300
  + health.enabled = true
  + health.port = 8080
```

### Merging Config Changes

**Option 1**: Automatic merge (upgrade.sh handles this)

**Option 2**: Manual merge
```bash
# Compare configs
diff -u /etc/fossibot/config.json config/example.json

# Edit config
sudo nano /etc/fossibot/config.json

# Add new options from example.json
```

---

## Version-Specific Upgrade Notes

### Upgrading to v2.0 (Cache System)

**New config required**:
```json
"cache": {
  "directory": "/var/lib/fossibot",
  "token_ttl_safety_margin": 300,
  "device_list_ttl": 86400,
  "device_refresh_interval": 86400
}
```

**Migration**: No data migration needed, cache builds automatically.

### Upgrading to v2.1 (Health Check)

**New config required**:
```json
"health": {
  "enabled": true,
  "port": 8080
}
```

**Migration**: None. Disable health check if port conflicts:
```json
"health": {
  "enabled": false
}
```

---

## Rollback

### If Upgrade Fails

```bash
# 1. Restore from backup
BACKUP_DIR=$(ls -td /tmp/fossibot-backup-* | head -1)
sudo cp -r $BACKUP_DIR/install/* /opt/fossibot-bridge/

# 2. Restore config (if modified)
sudo cp $BACKUP_DIR/config/config.json /etc/fossibot/

# 3. Reload systemd
sudo systemctl daemon-reload

# 4. Restart service
fossibot-bridge-ctl restart
```

### Git Rollback

```bash
cd /opt/fossibot-php2
sudo git log --oneline  # Find previous commit
sudo git reset --hard <commit-hash>
sudo composer install --no-dev
sudo scripts/upgrade.sh
```

---

## Post-Upgrade Checks

### 1. Service Running

```bash
fossibot-bridge-ctl status
```

### 2. Logs Clean

```bash
fossibot-bridge-ctl logs 50 | grep -i error
# Should be empty or only expected warnings
```

### 3. MQTT Working

```bash
mosquitto_sub -h localhost -t 'fossibot/#' -v -C 1
# Should receive state update
```

### 4. Health Check

```bash
fossibot-bridge-ctl health
# Should return "healthy"
```

---

## Upgrade Frequency

**Recommended**: Monthly check for updates

```bash
# Add to cron (weekly check)
cat > /etc/cron.weekly/check-fossibot-updates << 'EOF'
#!/bin/bash
cd /opt/fossibot-php2
git fetch origin
LOCAL=$(git rev-parse @)
REMOTE=$(git rev-parse origin/main)

if [ "$LOCAL" != "$REMOTE" ]; then
    echo "Fossibot Bridge update available!"
    echo "Run: sudo scripts/upgrade.sh"
fi
EOF

chmod +x /etc/cron.weekly/check-fossibot-updates
```

---

## Breaking Changes Policy

- **Major version** (1.x → 2.x): May require config changes
- **Minor version** (2.0 → 2.1): Backwards compatible, new features
- **Patch version** (2.0.0 → 2.0.1): Bug fixes only

---

## Support

- **GitHub Releases**: https://github.com/youruser/fossibot-php2/releases
- **Changelog**: https://github.com/youruser/fossibot-php2/blob/main/CHANGELOG.md
- **Issues**: https://github.com/youruser/fossibot-php2/issues
```

**Done when**: UPGRADE.md provides complete upgrade process

**Commit**: `docs: add upgrade guide with rollback procedures`

---

### Step 3: TROUBLESHOOTING.md - Problem Solutions (15min)

**File**: `docs/TROUBLESHOOTING.md`
**Lines**: New file

```markdown
# Troubleshooting Guide

Fossibot MQTT Bridge - Häufige Probleme und Lösungen

---

## Service Won't Start

### Symptom
```bash
fossibot-bridge-ctl start
# ❌ Bridge failed to start
```

### Solution 1: Check Logs
```bash
sudo journalctl -u fossibot-bridge -n 50
```

**Common errors**:

#### "Config file not found"
```bash
# Verify config exists
ls -la /etc/fossibot/config.json

# If missing, create from example
sudo cp config/example.json /etc/fossibot/config.json
sudo chmod 640 /etc/fossibot/config.json
```

#### "Permission denied"
```bash
# Check file ownership
ls -la /etc/fossibot/config.json
# Should be: -rw-r----- 1 root fossibot

# Fix permissions
sudo chown root:fossibot /etc/fossibot/config.json
sudo chmod 640 /etc/fossibot/config.json
```

#### "Bind failed: Address already in use"
```bash
# Another bridge instance running?
ps aux | grep fossibot-bridge

# Kill stale process
sudo pkill -f fossibot-bridge

# Or remove stale PID file
sudo rm -f /var/run/fossibot/bridge.pid
```

### Solution 2: Validate Config
```bash
fossibot-bridge-ctl validate

# Common validation errors:
# - Invalid JSON syntax
# - Missing required fields (accounts, mosquitto)
# - Invalid email/password format
```

---

## MQTT Not Working

### Symptom
```bash
mosquitto_sub -h localhost -t 'fossibot/#' -v
# (no output)
```

### Solution 1: Check Local Broker
```bash
# Is Mosquitto running?
systemctl status mosquitto

# Start if stopped
sudo systemctl start mosquitto

# Test connection
mosquitto_sub -h localhost -t '$SYS/#' -C 5
# Should show system messages
```

### Solution 2: Check Bridge Connection
```bash
# Bridge logs
fossibot-bridge-ctl logs 100 | grep -i mqtt

# Look for:
# ✅ "Local broker connected"
# ❌ "Failed to connect to local broker"
```

### Solution 3: Network Issues
```bash
# Can bridge reach Fossibot Cloud?
ping app.fossibot.com

# DNS working?
nslookup mqtt.fossibot.com

# Firewall blocking?
sudo ufw status
sudo ufw allow out to any port 443  # MQTT over WebSocket
```

---

## Authentication Errors

### Symptom
```
[ERROR] Stage 2 failed: HTTP 401 Unauthorized
```

### Solution 1: Wrong Credentials
```bash
# Verify email/password in config
sudo nano /etc/fossibot/config.json

# Test login via Smartphone App
# If App login fails → password wrong
```

### Solution 2: Account Locked
```
Multiple login failures may temporarily lock account.
Wait 15 minutes, then retry.
```

### Solution 3: Token Invalidation
```
[WARNING] Tokens expired during runtime, invalidating cache
```

This is **normal** - Bridge auto-refreshes tokens.

If happens repeatedly:
```bash
# Clear token cache
sudo rm -rf /var/lib/fossibot/tokens_*.json

# Restart bridge
fossibot-bridge-ctl restart
```

---

## Devices Not Discovered

### Symptom
```
[INFO] Device list cached: 0 devices
```

### Solution 1: Check Fossibot App
```
Open Fossibot App → Devices Tab
Ensure at least one device registered and online.
```

### Solution 2: Force Refresh
```bash
# Invalidate device cache
sudo rm -rf /var/lib/fossibot/devices_*.json

# Restart bridge
fossibot-bridge-ctl restart

# Check logs
fossibot-bridge-ctl logs 50 | grep -i "device"
```

### Solution 3: Account Not Enabled
```json
{
  "accounts": [
    {
      "email": "user@example.com",
      "password": "...",
      "enabled": false  // ← Must be true!
    }
  ]
}
```

---

## High Memory Usage

### Symptom
```bash
systemctl status fossibot-bridge
# Memory: 600M (limit: 512M)
```

### Solution 1: Increase systemd Limit
```bash
sudo systemctl edit fossibot-bridge

# Add:
[Service]
MemoryMax=1G
MemoryHigh=768M

# Reload
sudo systemctl daemon-reload
sudo systemctl restart fossibot-bridge
```

### Solution 2: Check for Memory Leak
```bash
# Monitor memory over time
watch -n 5 'systemctl show fossibot-bridge -p MemoryCurrent'

# If constantly growing → report issue on GitHub
```

---

## Cache Issues

### Symptom
```
[DEBUG] Cached token expired or expiring soon
```

(Every restart, despite tokens being valid)

### Solution: Adjust Safety Margin
```json
"cache": {
  "token_ttl_safety_margin": 60  // Reduce from 300 (5min) to 60 (1min)
}
```

---

## Health Check Fails

### Symptom
```bash
fossibot-bridge-ctl health
# ❌ Health check failed (HTTP 000)
```

### Solution 1: Port Not Open
```bash
# Check if server listening
sudo netstat -tulpn | grep 8080

# If not → check config
jq '.health' /etc/fossibot/config.json

# Expected:
{
  "enabled": true,
  "port": 8080
}
```

### Solution 2: Port Conflict
```bash
# Another service on port 8080?
sudo lsof -i :8080

# Change health port
sudo nano /etc/fossibot/config.json
# "port": 8081

fossibot-bridge-ctl restart
```

---

## Commands Not Working

### Symptom
```bash
mosquitto_pub -h localhost \
    -t 'fossibot/7C2C67AB5F0E/command' \
    -m '{"action":"usb","value":true}'

# (USB doesn't turn on)
```

### Solution 1: Check MAC Address
```bash
# List devices
mosquitto_sub -h localhost -t 'fossibot/+/state' -v -C 1

# Use exact MAC from topic
# ✅ fossibot/7C2C67AB5F0E/state
# ❌ fossibot/7c2c67ab5f0e/state (lowercase won't work)
```

### Solution 2: Invalid Command Format
```bash
# Valid commands:
{"action":"usb","value":true}   # USB on
{"action":"usb","value":false}  # USB off
{"action":"ac","value":true}    # AC on
{"action":"dc","value":true}    # DC on
{"action":"led","value":true}   # LED on

# Invalid:
{"action":"usb"}  # Missing value
{"usb":true}      # Wrong format
```

### Solution 3: Device Offline
```bash
# Check device state
mosquitto_sub -h localhost -t 'fossibot/7C2C67AB5F0E/state' -C 1

# If no response → device offline in Fossibot Cloud
# Check device status in Smartphone App
```

---

## systemd Issues

### Symptom
```
Job for fossibot-bridge.service failed
```

### Solution 1: Check Failure Reason
```bash
systemctl status fossibot-bridge

# Look for:
# - "code=exited, status=1" → Check logs
# - "start-limit-hit" → Too many restarts
# - "signal=SIGKILL" → OOM kill (memory)
```

### Solution 2: Reset Failure Counter
```bash
sudo systemctl reset-failed fossibot-bridge
sudo systemctl start fossibot-bridge
```

### Solution 3: Temporarily Disable Security
```bash
sudo systemctl edit fossibot-bridge

# Add (for testing only!):
[Service]
NoNewPrivileges=false
ProtectSystem=false
PrivateDevices=false

sudo systemctl daemon-reload
sudo systemctl restart fossibot-bridge

# If it works → security hardening too strict
# Re-enable security options one by one to find culprit
```

---

## Debug Logging

### Enable Debug Mode

```json
"daemon": {
  "log_level": "debug"  // Change from "info"
}
```

```bash
fossibot-bridge-ctl restart
```

### Useful Log Patterns

```bash
# Authentication flow
fossibot-bridge-ctl logs 500 | grep -i "stage"

# MQTT messages
fossibot-bridge-ctl logs 500 | grep -i "mqtt"

# Device states
fossibot-bridge-ctl logs 500 | grep -i "device state"

# Errors only
fossibot-bridge-ctl logs 500 | grep -i "error"
```

---

## Getting Help

### 1. Collect Debug Info

```bash
# System info
uname -a
php -v

# Service status
systemctl status fossibot-bridge

# Recent logs
fossibot-bridge-ctl logs 100

# Config (REMOVE CREDENTIALS!)
jq 'del(.accounts[].password)' /etc/fossibot/config.json

# Health
fossibot-bridge-ctl health
```

### 2. Create GitHub Issue

https://github.com/youruser/fossibot-php2/issues

**Include**:
- Output from debug info above
- Steps to reproduce
- Expected vs. actual behavior

---

## Emergency Recovery

### Complete Reset

```bash
# 1. Stop service
fossibot-bridge-ctl stop

# 2. Clear cache
sudo rm -rf /var/lib/fossibot/*

# 3. Clear logs
sudo rm -f /var/log/fossibot/bridge.log

# 4. Reset config to example
sudo cp /opt/fossibot-php2/config/example.json /etc/fossibot/config.json
sudo nano /etc/fossibot/config.json  # Add credentials

# 5. Validate
fossibot-bridge-ctl validate

# 6. Start fresh
fossibot-bridge-ctl start
```

---

**Still stuck?** → GitHub Issues
```

**Done when**: TROUBLESHOOTING.md covers common problems

**Commit**: `docs: add comprehensive troubleshooting guide`

---

### Step 4: OPERATIONS.md - Daily Operations (10min)

**File**: `docs/OPERATIONS.md`
**Lines**: New file

```markdown
# Operations Guide

Fossibot MQTT Bridge - Daily Operations & Maintenance

---

## Daily Operations

### Check Service Status

```bash
# Quick status
fossibot-bridge-ctl status

# Detailed info
systemctl status fossibot-bridge

# Health check
fossibot-bridge-ctl health
```

### View Logs

```bash
# Recent logs
fossibot-bridge-ctl logs

# Live tail
fossibot-bridge-ctl logs 100 | tail -f

# Via journalctl
sudo journalctl -u fossibot-bridge -f
```

### Restart Service

```bash
# Graceful restart
fossibot-bridge-ctl restart

# Force restart
sudo systemctl restart fossibot-bridge
```

---

## Common Tasks

### Add New Device

```
1. Register device in Fossibot Smartphone App
2. Wait 5 minutes for auto-discovery
3. Verify device appears in MQTT topics:
   mosquitto_sub -h localhost -t 'fossibot/#' -v
```

**Force immediate discovery**:
```bash
# Invalidate device cache
sudo rm -rf /var/lib/fossibot/devices_*.json

# Restart bridge
fossibot-bridge-ctl restart
```

### Add New Account

```bash
# 1. Edit config
sudo nano /etc/fossibot/config.json

# Add account:
{
  "email": "newuser@example.com",
  "password": "password",
  "enabled": true
}

# 2. Validate
fossibot-bridge-ctl validate

# 3. Restart
fossibot-bridge-ctl restart
```

### Disable Account Temporarily

```json
{
  "email": "user@example.com",
  "password": "...",
  "enabled": false  // ← Set to false
}
```

```bash
fossibot-bridge-ctl restart
```

---

## Monitoring

### Resource Usage

```bash
# Memory
systemctl show fossibot-bridge -p MemoryCurrent

# CPU
systemctl show fossibot-bridge -p CPUUsageNSec

# All resources
systemd-cgtop
```

### Connection Status

```bash
# MQTT topics activity
mosquitto_sub -h localhost -t 'fossibot/+/state' -v

# Expected: Updates every 30s
```

---

## Maintenance

### Log Rotation

**Automatic** (systemd journal):
```bash
# Check journal size
journalctl --disk-usage

# Vacuum old logs (keep last 7 days)
sudo journalctl --vacuum-time=7d
```

**Manual log file** (if using `log_file`):
```bash
# Setup logrotate
sudo nano /etc/logrotate.d/fossibot-bridge

# Add:
/var/log/fossibot/*.log {
    daily
    rotate 7
    compress
    delaycompress
    notifempty
    missingok
    postrotate
        systemctl reload fossibot-bridge
    endscript
}
```

### Cache Cleanup

**Automatic**: Cache expires automatically (TTL-based)

**Manual** (if needed):
```bash
# Clear all caches
sudo rm -rf /var/lib/fossibot/*

# Restart to rebuild
fossibot-bridge-ctl restart
```

### Update Check

```bash
cd /opt/fossibot-php2
sudo git fetch origin

# Check for updates
git log --oneline HEAD..origin/main

# If updates available
sudo scripts/upgrade.sh
```

---

## Backup & Restore

### Backup

```bash
#!/bin/bash
# backup-fossibot.sh

BACKUP_DIR="/backup/fossibot/$(date +%Y%m%d)"
mkdir -p "$BACKUP_DIR"

# Config
cp -r /etc/fossibot "$BACKUP_DIR/"

# Cache (optional)
cp -r /var/lib/fossibot "$BACKUP_DIR/"

# Logs (optional)
cp -r /var/log/fossibot "$BACKUP_DIR/"

echo "Backup completed: $BACKUP_DIR"
```

### Restore

```bash
# Stop service
fossibot-bridge-ctl stop

# Restore config
sudo cp -r /backup/fossibot/20251003/fossibot /etc/

# Restore cache (optional)
sudo cp -r /backup/fossibot/20251003/fossibot /var/lib/

# Start service
fossibot-bridge-ctl start
```

---

## Security Operations

### Rotate Credentials

```bash
# 1. Change password in Fossibot App
# 2. Update config
sudo nano /etc/fossibot/config.json

# 3. Validate
fossibot-bridge-ctl validate

# 4. Clear token cache (force re-auth)
sudo rm -rf /var/lib/fossibot/tokens_*.json

# 5. Restart
fossibot-bridge-ctl restart
```

### Audit Logs

```bash
# Login attempts
fossibot-bridge-ctl logs 1000 | grep -i "stage 2"

# Connection issues
fossibot-bridge-ctl logs 1000 | grep -i "disconnect"

# Errors
fossibot-bridge-ctl logs 1000 | grep -i "error"
```

---

## Performance Tuning

### Adjust Polling Interval

```json
"bridge": {
  "device_poll_interval": 30  // Default: 30s, increase to reduce load
}
```

### Cache Tuning

```json
"cache": {
  "device_list_ttl": 86400,           // 24h (default)
  "device_refresh_interval": 86400,   // 24h refresh
  "token_ttl_safety_margin": 300      // 5min safety margin
}
```

### Resource Limits

```bash
# Increase memory limit
sudo systemctl edit fossibot-bridge

[Service]
MemoryMax=1G
MemoryHigh=768M

sudo systemctl daemon-reload
sudo systemctl restart fossibot-bridge
```

---

## Troubleshooting Quick Reference

| Problem | Quick Fix |
|---------|-----------|
| Service won't start | `fossibot-bridge-ctl validate` |
| No MQTT messages | `systemctl status mosquitto` |
| Auth errors | Check credentials in config |
| High memory | Increase `MemoryMax` in service |
| Devices missing | Clear device cache, restart |

**Full guide**: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

---

## Contact & Support

- **GitHub Issues**: https://github.com/youruser/fossibot-php2/issues
- **Documentation**: https://github.com/youruser/fossibot-php2/tree/main/docs
```

**Done when**: OPERATIONS.md provides daily operations guide

**Commit**: `docs: add operations guide for daily maintenance`

---

## Validation Checklist

After completing all steps, verify:

- ✅ INSTALL.md covers installation from scratch
- ✅ UPGRADE.md explains upgrade process with rollback
- ✅ TROUBLESHOOTING.md addresses common problems
- ✅ OPERATIONS.md documents daily tasks
- ✅ All docs are self-contained and understandable for sysadmins
- ✅ Examples are copy-paste ready
- ✅ Links between docs work

---

## Documentation Structure

```
docs/
├── INSTALL.md           # New users start here
├── UPGRADE.md           # Existing users upgrading
├── OPERATIONS.md        # Daily operations reference
├── TROUBLESHOOTING.md   # Problem solving
└── deployment/          # Implementation guides
    ├── 00_OVERVIEW.md
    ├── 01_PHASE_CACHE.md
    ├── 02_PHASE_HEALTH.md
    ├── 03_PHASE_PID.md
    ├── 04_PHASE_CONTROL.md
    ├── 05_PHASE_INSTALL.md
    ├── 06_PHASE_SYSTEMD.md
    └── 07_PHASE_DOCS.md
```

---

## Next Steps

After Phase 7 completion:
- All deployment phases documented
- Ready for production rollout
- Documentation published on GitHub

---

**Phase 7 Complete**: Complete user and admin documentation available for production deployment.
