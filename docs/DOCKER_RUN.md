# Docker Run Deployment

Deployment mit `docker run` - für minimale Setups ohne docker-compose.

---

## Voraussetzungen

- Docker installiert
- Mosquitto MQTT Broker (lokal oder remote)

---

## Quick Start

### 1. Image pullen

```bash
docker pull tkaufmann/fossibot-bridge:latest
```

### 2. Config-Datei erstellen

```bash
mkdir -p /opt/fossibot/config
cat > /opt/fossibot/config/config.json << 'EOF'
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
  "health": {
    "enabled": true,
    "port": 8080
  },
  "cache": {
    "directory": "/var/lib/fossibot"
  }
}
EOF
```

### 3. Container starten

```bash
docker run -d \
  --name fossibot-bridge \
  --restart unless-stopped \
  --network host \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  -v fossibot-cache:/var/lib/fossibot \
  -v fossibot-logs:/var/log/fossibot \
  tkaufmann/fossibot-bridge:latest
```

**Hinweis:** `--network host` ermöglicht einfachen Zugriff auf lokalen Mosquitto auf `localhost:1883`.

---

## Mit externer Mosquitto Instanz

### Container ohne --network host

```bash
docker run -d \
  --name fossibot-bridge \
  --restart unless-stopped \
  -p 8080:8080 \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  -v fossibot-cache:/var/lib/fossibot \
  -v fossibot-logs:/var/log/fossibot \
  tkaufmann/fossibot-bridge:latest
```

**Config anpassen** (`/opt/fossibot/config/config.json`):
```json
{
  "mosquitto": {
    "host": "192.168.1.100",
    "port": 1883
  }
}
```

---

## Mit eigenem Mosquitto Container

### 1. Mosquitto starten

```bash
docker run -d \
  --name mosquitto \
  --restart unless-stopped \
  -p 1883:1883 \
  -p 9001:9001 \
  -v mosquitto-data:/mosquitto/data \
  -v mosquitto-logs:/mosquitto/log \
  eclipse-mosquitto:2
```

### 2. Fossibot Bridge mit Link

```bash
docker run -d \
  --name fossibot-bridge \
  --restart unless-stopped \
  --link mosquitto:mosquitto \
  -p 8080:8080 \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  -v fossibot-cache:/var/lib/fossibot \
  -v fossibot-logs:/var/log/fossibot \
  tkaufmann/fossibot-bridge:latest
```

**Config:**
```json
{
  "mosquitto": {
    "host": "mosquitto",
    "port": 1883
  }
}
```

---

## Environment Variables (Optional)

Überschreibe Config-Werte via ENV:

```bash
docker run -d \
  --name fossibot-bridge \
  --restart unless-stopped \
  --network host \
  -e FOSSIBOT_EMAIL=user@example.com \
  -e FOSSIBOT_PASSWORD=secret \
  -e MOSQUITTO_HOST=localhost \
  -e LOG_LEVEL=debug \
  -v fossibot-cache:/var/lib/fossibot \
  tkaufmann/fossibot-bridge:latest
```

**Unterstützte ENV Variablen:**
- `FOSSIBOT_EMAIL` - Account Email
- `FOSSIBOT_PASSWORD` - Account Passwort
- `MOSQUITTO_HOST` - MQTT Broker Host
- `MOSQUITTO_PORT` - MQTT Broker Port (default: 1883)
- `MOSQUITTO_USERNAME` - MQTT Username (optional)
- `MOSQUITTO_PASSWORD` - MQTT Passwort (optional)
- `LOG_LEVEL` - Log Level (debug, info, warning, error)

---

## Volumes

### Named Volumes (empfohlen)

```bash
docker volume create fossibot-cache
docker volume create fossibot-logs

docker run -d \
  --name fossibot-bridge \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  -v fossibot-cache:/var/lib/fossibot \
  -v fossibot-logs:/var/log/fossibot \
  tkaufmann/fossibot-bridge:latest
```

### Bind Mounts

```bash
mkdir -p /opt/fossibot/{cache,logs}

docker run -d \
  --name fossibot-bridge \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  -v /opt/fossibot/cache:/var/lib/fossibot \
  -v /opt/fossibot/logs:/var/log/fossibot \
  tkaufmann/fossibot-bridge:latest
```

---

## Management

### Logs anschauen

```bash
# Live tail
docker logs -f fossibot-bridge

# Letzte 100 Zeilen
docker logs --tail 100 fossibot-bridge

# Seit heute
docker logs --since "$(date +%Y-%m-%d)" fossibot-bridge
```

### Container Status

```bash
docker ps -f name=fossibot-bridge
docker stats fossibot-bridge
```

### Container neu starten

```bash
docker restart fossibot-bridge
```

### Container stoppen

```bash
docker stop fossibot-bridge
docker rm fossibot-bridge
```

### In Container exec

```bash
docker exec -it fossibot-bridge sh
```

### Health Check

```bash
# Via Docker
docker inspect fossibot-bridge | jq '.[0].State.Health'

# Via HTTP
curl http://localhost:8080/health | jq '.'
```

---

## MQTT Testing

### Subscribe zu Topics

```bash
# Von Host aus (Mosquitto CLI benötigt)
mosquitto_sub -h localhost -t 'fossibot/#' -v

# Mit Docker Mosquitto
docker exec mosquitto mosquitto_sub -t 'fossibot/#' -v
```

### Commands senden

```bash
# USB einschalten
mosquitto_pub -h localhost \
  -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb","value":true}'

# Mit Docker Mosquitto
docker exec mosquitto mosquitto_pub \
  -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb","value":true}'
```

---

## Troubleshooting

### Container startet nicht

```bash
# Logs prüfen
docker logs fossibot-bridge

# Config validieren
docker run --rm \
  -v /opt/fossibot/config/config.json:/tmp/config.json:ro \
  alpine sh -c 'cat /tmp/config.json | jq .'
```

### Keine MQTT Verbindung

```bash
# Mosquitto erreichbar?
docker exec fossibot-bridge ping -c 3 mosquitto

# Config prüfen
docker exec fossibot-bridge cat /etc/fossibot/config.json | jq '.mosquitto'

# Mosquitto Logs (wenn Container)
docker logs mosquitto
```

### Auth Fehler

```bash
# Credentials prüfen
docker exec fossibot-bridge \
  php -r "print_r(json_decode(file_get_contents('/etc/fossibot/config.json'))->accounts);"

# Mit debug logs neu starten
docker stop fossibot-bridge
docker run -d \
  --name fossibot-bridge \
  -e LOG_LEVEL=debug \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  tkaufmann/fossibot-bridge:latest
docker logs -f fossibot-bridge
```

### Hoher Memory-Verbrauch

```bash
# Stats
docker stats fossibot-bridge

# Restart bei >200MB
docker restart fossibot-bridge
```

---

## Updates

### Neues Image pullen

```bash
docker pull tkaufmann/fossibot-bridge:latest
```

### Container aktualisieren

```bash
# Stop & remove old
docker stop fossibot-bridge
docker rm fossibot-bridge

# Start mit neuem Image
docker run -d \
  --name fossibot-bridge \
  --restart unless-stopped \
  --network host \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  -v fossibot-cache:/var/lib/fossibot \
  -v fossibot-logs:/var/log/fossibot \
  tkaufmann/fossibot-bridge:latest
```

### Rollback

```bash
# Spezifische Version
docker pull tkaufmann/fossibot-bridge:2.0.0

# Container mit alter Version
docker run -d \
  --name fossibot-bridge \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  tkaufmann/fossibot-bridge:2.0.0
```

---

## Systemd Integration

### Service File erstellen

```bash
sudo nano /etc/systemd/system/fossibot-bridge.service
```

```ini
[Unit]
Description=Fossibot MQTT Bridge Container
Requires=docker.service
After=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStartPre=-/usr/bin/docker stop fossibot-bridge
ExecStartPre=-/usr/bin/docker rm fossibot-bridge
ExecStart=/usr/bin/docker run --rm --name fossibot-bridge \
  --network host \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  -v fossibot-cache:/var/lib/fossibot \
  -v fossibot-logs:/var/log/fossibot \
  tkaufmann/fossibot-bridge:latest
ExecStop=/usr/bin/docker stop fossibot-bridge

[Install]
WantedBy=multi-user.target
```

### Service aktivieren

```bash
sudo systemctl daemon-reload
sudo systemctl enable fossibot-bridge
sudo systemctl start fossibot-bridge
sudo systemctl status fossibot-bridge
```

---

## Security Best Practices

### Read-only Config

```bash
# Config schützen
sudo chown root:root /opt/fossibot/config/config.json
sudo chmod 600 /opt/fossibot/config/config.json

# Mount read-only
docker run -d \
  -v /opt/fossibot/config/config.json:/etc/fossibot/config.json:ro \
  tkaufmann/fossibot-bridge:latest
```

### Non-root Container

Image läuft bereits als non-root User (`fossibot`, UID 1000).

Verifizieren:
```bash
docker exec fossibot-bridge id
# Output: uid=1000(fossibot) gid=1000(fossibot) groups=1000(fossibot)
```

### Network Isolation

Für Production: Eigenes Docker Network verwenden

```bash
docker network create fossibot-net

docker run -d --name mosquitto \
  --network fossibot-net \
  eclipse-mosquitto:2

docker run -d --name fossibot-bridge \
  --network fossibot-net \
  -p 127.0.0.1:8080:8080 \
  tkaufmann/fossibot-bridge:latest
```

---

## Multi-Architecture Support

Image verfügbar für:
- `linux/amd64` (Intel/AMD x86_64)
- `linux/arm64` (ARM 64-bit, z.B. Raspberry Pi 4)
- `linux/arm/v7` (ARM 32-bit, z.B. Raspberry Pi 3)

Docker wählt automatisch die richtige Architektur:

```bash
# Funktioniert auf allen Plattformen
docker pull tkaufmann/fossibot-bridge:latest
```

---

## Weitere Dokumentation

- [docker-compose Setup](DOCKER_COMPOSE.md)
- [Vollständige Docker Doku](DOCKER.md)
- [Installation Guide](INSTALL.md)
- [Troubleshooting](TROUBLESHOOTING.md)
