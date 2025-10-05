# Fossibot MQTT Bridge

![Docker Pulls](https://img.shields.io/docker/pulls/tkaufmann/fossibot-bridge)
![Docker Image Size](https://img.shields.io/docker/image-size/tkaufmann/fossibot-bridge/latest)

MQTT Bridge für Fossibot Power Stations (F2400, F3000, etc.)

Verbindet Fossibot Cloud API mit lokalem MQTT Broker für Home Assistant, Node-RED, ioBroker und andere Smart Home Systeme.

## Features

✅ **Multi-Account Support** - Mehrere Fossibot-Accounts gleichzeitig
✅ **Real-time Device State** - Live Status-Updates über MQTT
✅ **Device Control** - USB, AC, DC, LED Outputs steuern
✅ **Settings Management** - Ladestrom, Lade-/Entladegrenzen konfigurieren
✅ **Auto-Reconnect** - Automatische Wiederverbindung bei Verbindungsabbruch
✅ **Health Monitoring** - HTTP Health Endpoint für Überwachung
✅ **Multi-Architecture** - Unterstützt amd64, arm64, armv7 (Raspberry Pi!)

## Quick Start

### 1. Config erstellen

```bash
mkdir -p /opt/fossibot
cat > /opt/fossibot/config.json << 'EOF'
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
    "port": 1883
  }
}
EOF
```

### 2. Container starten

```bash
docker run -d \
  --name fossibot-bridge \
  --restart unless-stopped \
  --network host \
  -v /opt/fossibot/config.json:/etc/fossibot/config.json:ro \
  -v fossibot-cache:/var/lib/fossibot \
  tkaufmann/fossibot-bridge:latest
```

### 3. Verifizieren

```bash
# Logs
docker logs -f fossibot-bridge

# Health Check
curl http://localhost:8080/health

# MQTT Topics (Mosquitto CLI benötigt)
mosquitto_sub -h localhost -t 'fossibot/#' -v
```

## Docker Compose

```yaml
version: '3.8'

services:
  mosquitto:
    image: eclipse-mosquitto:2
    ports:
      - "1883:1883"
    volumes:
      - mosquitto-data:/mosquitto/data

  fossibot-bridge:
    image: tkaufmann/fossibot-bridge:latest
    depends_on:
      - mosquitto
    volumes:
      - ./config.json:/etc/fossibot/config.json:ro
      - fossibot-cache:/var/lib/fossibot

volumes:
  mosquitto-data:
  fossibot-cache:
```

**Starten:**
```bash
docker-compose up -d
```

## MQTT Topics

### Device State (Published by Bridge)

```
fossibot/{MAC}/state
```

**Payload:**
```json
{
  "soc": 85.5,
  "inputWatts": 450,
  "outputWatts": 120,
  "dcInputWatts": 0,
  "usbOutput": true,
  "acOutput": false,
  "dcOutput": true,
  "ledOutput": false,
  "maxChargingCurrent": 15,
  "dischargeLowerLimit": 20.0,
  "acChargingUpperLimit": 80.0
}
```

### Device Commands (Subscribed by Bridge)

```
fossibot/{MAC}/command
```

**Payloads:**
```json
{"action": "usb", "value": true}      // USB on
{"action": "usb", "value": false}     // USB off
{"action": "ac", "value": true}       // AC on
{"action": "dc", "value": true}       // DC on
{"action": "led", "value": true}      // LED on
{"action": "max_charging_current", "value": 15}
{"action": "discharge_lower_limit", "value": 20.0}
{"action": "ac_charging_upper_limit", "value": 80.0}
```

## Home Assistant Integration

### Sensor

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

### Switch

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

## Configuration

### Minimal Config

```json
{
  "accounts": [
    {
      "email": "user@example.com",
      "password": "password",
      "enabled": true
    }
  ],
  "mosquitto": {
    "host": "localhost",
    "port": 1883
  }
}
```

### Full Config

```json
{
  "accounts": [
    {
      "email": "user@example.com",
      "password": "password",
      "enabled": true
    }
  ],
  "mosquitto": {
    "host": "mosquitto",
    "port": 1883,
    "username": "bridge_user",
    "password": "secret",
    "client_id": "fossibot_bridge"
  },
  "daemon": {
    "log_level": "info"
  },
  "health": {
    "enabled": true,
    "port": 8080
  },
  "bridge": {
    "status_publish_interval": 60,
    "device_poll_interval": 30
  }
}
```

### Environment Variables (Optional)

```bash
docker run -d \
  -e FOSSIBOT_EMAIL=user@example.com \
  -e FOSSIBOT_PASSWORD=secret \
  -e MOSQUITTO_HOST=localhost \
  -e LOG_LEVEL=debug \
  tkaufmann/fossibot-bridge:latest
```

## Health Check

**Endpoint:** `http://localhost:8080/health`

**Response:**
```json
{
  "status": "healthy",
  "uptime": 3600,
  "accounts": {
    "total": 1,
    "connected": 1
  },
  "devices": {
    "total": 2,
    "online": 2
  }
}
```

## Supported Platforms

- `linux/amd64` - Intel/AMD 64-bit
- `linux/arm64` - ARM 64-bit (Raspberry Pi 4, Apple Silicon)
- `linux/arm/v7` - ARM 32-bit (Raspberry Pi 3)

Docker wählt automatisch die richtige Platform.

## Volumes

| Path | Zweck |
|------|-------|
| `/etc/fossibot/config.json` | Config (read-only mount) |
| `/var/lib/fossibot` | Token Cache (persistent) |
| `/var/log/fossibot` | Logs (optional) |

## Ports

| Port | Zweck |
|------|-------|
| 8080 | Health Check Endpoint |

## Security

- Container läuft als **non-root user** (`fossibot`, UID 1000)
- Config sollte **read-only** gemountet werden (`:ro`)
- Für Production: MQTT Authentication aktivieren
- Health Endpoint nur auf `127.0.0.1` binden: `-p 127.0.0.1:8080:8080`

## Troubleshooting

### Container startet nicht

```bash
# Logs prüfen
docker logs fossibot-bridge

# Config validieren
cat /opt/fossibot/config.json | jq .
```

### Keine MQTT Verbindung

```bash
# Mosquitto erreichbar?
docker exec fossibot-bridge ping mosquitto

# MQTT Broker testen
mosquitto_sub -h localhost -t '$SYS/#' -C 1
```

### Auth Fehler

```bash
# Debug Logs aktivieren
docker run -d \
  -e LOG_LEVEL=debug \
  tkaufmann/fossibot-bridge:latest

docker logs -f fossibot-bridge
```

## Links

- **GitHub:** https://github.com/tkaufmann/fossibot-php2
- **Dokumentation:** https://github.com/tkaufmann/fossibot-php2/tree/main/docs
- **Issues:** https://github.com/tkaufmann/fossibot-php2/issues

## License

MIT
