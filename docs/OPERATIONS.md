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
  "token_ttl_safety_margin": 300,     // 5min safety margin
  "max_token_ttl": 86400              // 24h max (caps unrealistic JWT expiry)
}
```

**Important**: `max_token_ttl` caps token cache TTL regardless of JWT expiry. Fossibot's S2 login token claims 10-year expiry but is invalidated server-side much sooner. Default 1 day prevents stale token issues.

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
