# Deployment Guide

Complete guide for deploying Fossibot MQTT Bridge in production.

---

## Prerequisites

### System Requirements

- **OS**: Ubuntu 20.04+ or Debian 11+
- **PHP**: 8.1 or higher
- **Memory**: 256MB minimum, 512MB recommended
- **Disk**: 100MB for application + logs

### Required Software

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP and extensions
sudo apt install php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Mosquitto
sudo apt install mosquitto mosquitto-clients
sudo systemctl enable mosquitto
sudo systemctl start mosquitto
```

---

## Installation

### 1. Clone Repository

```bash
cd /opt
sudo git clone https://github.com/youruser/fossibot-php2.git fossibot-bridge
cd fossibot-bridge
```

### 2. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Configure Service

```bash
cd daemon
sudo ./install-systemd.sh
```

### 4. Edit Configuration

```bash
sudo nano /etc/fossibot/config.json
```

Add your Fossibot account credentials:

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
  },
  "daemon": {
    "log_file": "/var/log/fossibot/bridge.log",
    "log_level": "info"
  },
  "bridge": {
    "status_publish_interval": 60,
    "reconnect_delay_min": 5,
    "reconnect_delay_max": 60
  }
}
```

**Secure config file:**
```bash
sudo chmod 600 /etc/fossibot/config.json
```

### 5. Validate Configuration

```bash
sudo -u fossibot php /opt/fossibot-bridge/daemon/fossibot-bridge.php \
  --config /etc/fossibot/config.json --validate
```

Expected output:
```
✅ Config valid
  Accounts: 1
  Mosquitto: localhost:1883
  Log level: info
```

---

## Service Management

### Enable and Start

```bash
sudo systemctl enable fossibot-bridge
sudo systemctl start fossibot-bridge
```

### Check Status

```bash
sudo systemctl status fossibot-bridge
```

Expected output:
```
● fossibot-bridge.service - Fossibot MQTT Bridge Daemon
     Loaded: loaded (/etc/systemd/system/fossibot-bridge.service)
     Active: active (running) since Mon 2025-09-30 12:00:00 UTC
   Main PID: 12345 (php)
      Tasks: 3
     Memory: 45.2M
```

### View Logs

```bash
# Real-time logs
sudo journalctl -u fossibot-bridge -f

# Last 100 lines
sudo journalctl -u fossibot-bridge -n 100

# Logs since today
sudo journalctl -u fossibot-bridge --since today
```

### Stop Service

```bash
sudo systemctl stop fossibot-bridge
```

### Restart Service

```bash
sudo systemctl restart fossibot-bridge
```

---

## Verification

### 1. Check Bridge Status

```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v
```

Expected:
```json
fossibot/bridge/status {"status":"online","version":"2.0.0",...}
```

### 2. Check Device Discovery

```bash
mosquitto_sub -h localhost -t 'fossibot/+/state' -v
```

Should show device states within 30 seconds.

### 3. Test Command

```bash
# Get device MAC from status message
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'
```

Device USB output should turn on.

---

## Troubleshooting

### Service won't start

**Check logs:**
```bash
sudo journalctl -u fossibot-bridge -n 50
```

**Common issues:**
- Config file syntax error → Validate with `--validate` flag
- Mosquitto not running → `sudo systemctl start mosquitto`
- Missing dependencies → `composer install`
- Permission issues → Check `/var/log/fossibot` ownership

### No devices discovered

**Check authentication:**
```bash
sudo journalctl -u fossibot-bridge | grep auth
```

Look for authentication errors (401/403).

**Verify credentials:**
- Test login via web interface
- Check for typos in config.json
- Ensure password is correct (no trailing spaces)

### Bridge keeps reconnecting

**Check MQTT token expiry:**
```bash
sudo journalctl -u fossibot-bridge | grep "token expired"
```

Token should be valid for ~3 days. If expiring immediately, check system clock.

### High memory usage

**Check memory stats:**
```bash
systemctl status fossibot-bridge | grep Memory
```

Normal: 30-80MB per account

High (>200MB): Potential memory leak, restart service:
```bash
sudo systemctl restart fossibot-bridge
```

---

## Updating

### Update Code

```bash
cd /opt/fossibot-bridge
sudo git pull
composer install --no-dev --optimize-autoloader
sudo systemctl restart fossibot-bridge
```

### Update Config

```bash
sudo nano /etc/fossibot/config.json
# Make changes
sudo systemctl restart fossibot-bridge
```

---

## Monitoring

### Setup Log Rotation

Create `/etc/logrotate.d/fossibot`:

```
/var/log/fossibot/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    missingok
    create 0640 fossibot fossibot
}
```

### Monitor with systemd

```bash
# Enable email notifications on failure
sudo systemctl edit fossibot-bridge
```

Add:
```ini
[Unit]
OnFailure=failure-notification@%n.service
```

---

## Security Best Practices

1. **Restrict config file permissions:**
   ```bash
   sudo chmod 600 /etc/fossibot/config.json
   ```

2. **Enable Mosquitto authentication:**
   ```bash
   sudo mosquitto_passwd -c /etc/mosquitto/passwd bridge_user
   ```

   Update `/etc/mosquitto/mosquitto.conf`:
   ```
   password_file /etc/mosquitto/passwd
   ```

3. **Use firewall to restrict MQTT access:**
   ```bash
   sudo ufw allow from 192.168.1.0/24 to any port 1883
   ```

4. **Regular updates:**
   ```bash
   sudo apt update && sudo apt upgrade
   ```

---

## Uninstallation

```bash
# Stop and disable service
sudo systemctl stop fossibot-bridge
sudo systemctl disable fossibot-bridge

# Remove files
sudo rm /etc/systemd/system/fossibot-bridge.service
sudo rm -rf /opt/fossibot-bridge
sudo rm -rf /etc/fossibot
sudo rm -rf /var/log/fossibot

# Remove user
sudo userdel fossibot

# Reload systemd
sudo systemctl daemon-reload
```
