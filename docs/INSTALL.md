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

**Note**: Devices are considered online if they sent updates within the last 6 minutes. The status is updated every 60 seconds.

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
