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
