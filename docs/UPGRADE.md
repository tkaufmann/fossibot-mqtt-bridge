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
