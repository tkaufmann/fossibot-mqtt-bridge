# Docker Deployment Guide

Complete guide for running Fossibot MQTT Bridge in Docker.

---

## Quick Start

### 1. Prepare Configuration

```bash
# Copy example config
cp config/config.docker.json config/config.json

# Edit with your credentials
nano config/config.json
```

**Minimal config.json:**
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
    "host": "mosquitto",
    "port": 1883
  }
}
```

### 2. Start Services

```bash
docker-compose up -d
```

### 3. Verify

```bash
# Check service status
docker-compose ps

# View logs
docker-compose logs -f fossibot-bridge

# Test MQTT
docker exec -it fossibot-mosquitto mosquitto_sub -t 'fossibot/#' -v
```

---

## Architecture

```
┌─────────────────────────────────────┐
│         Docker Compose              │
│                                     │
│  ┌─────────────┐  ┌──────────────┐ │
│  │  Mosquitto  │  │   Fossibot   │ │
│  │    MQTT     │◄─┤    Bridge    │ │
│  │   Broker    │  │              │ │
│  └─────────────┘  └──────────────┘ │
│       :1883            :8080        │
└─────────────────────────────────────┘
         │                 │
    MQTT Topics      Health Endpoint
```

**Networks:**
- `fossibot-net` (bridge): Internal communication

**Volumes:**
- `mosquitto-data`: MQTT persistence
- `mosquitto-logs`: MQTT logs
- `fossibot-cache`: Token cache
- `fossibot-logs`: Bridge logs

---

## Building

### Build Image Manually

```bash
docker build -t fossibot-bridge:latest .
```

**Build with version tag:**
```bash
docker build -t fossibot-bridge:2.0.0 .
```

### Multi-platform Build

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t fossibot-bridge:latest \
  --push .
```

---

## Configuration

### Environment Variables

Override config via environment (optional):

```yaml
# docker-compose.yml
services:
  fossibot-bridge:
    environment:
      - FOSSIBOT_EMAIL=user@example.com
      - FOSSIBOT_PASSWORD=secret
      - MOSQUITTO_HOST=mosquitto
      - LOG_LEVEL=debug
```

### Volume Mounts

**Config file (read-only):**
```yaml
volumes:
  - ./config/config.json:/etc/fossibot/config.json:ro
```

**Cache directory (persistent):**
```yaml
volumes:
  - fossibot-cache:/var/lib/fossibot
```

**Logs (persistent or bind mount for analysis):**
```yaml
volumes:
  - ./logs:/var/log/fossibot  # Bind mount
  # OR
  - fossibot-logs:/var/log/fossibot  # Named volume
```

---

## Service Management

### Start Services

```bash
docker-compose up -d
```

### Stop Services

```bash
docker-compose down
```

### Restart Bridge

```bash
docker-compose restart fossibot-bridge
```

### View Logs

```bash
# All services
docker-compose logs -f

# Bridge only
docker-compose logs -f fossibot-bridge

# Last 100 lines
docker-compose logs --tail=100 fossibot-bridge
```

### Exec into Container

```bash
docker exec -it fossibot-bridge sh
```

---

## Monitoring

### Health Check

```bash
# Via Docker
docker-compose ps

# Via HTTP
curl http://localhost:8080/health | jq '.'
```

**Expected response:**
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

### MQTT Topics

```bash
# Subscribe to all topics
docker exec -it fossibot-mosquitto \
  mosquitto_sub -t 'fossibot/#' -v

# Subscribe to specific device
docker exec -it fossibot-mosquitto \
  mosquitto_sub -t 'fossibot/7C2C67AB5F0E/state' -v
```

### Send Commands

```bash
# Turn USB on
docker exec -it fossibot-mosquitto \
  mosquitto_pub -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb","value":true}'
```

---

## Production Deployment

### Docker Compose Override

Create `docker-compose.prod.yml`:

```yaml
version: '3.8'

services:
  mosquitto:
    volumes:
      - ./docker/mosquitto/config/mosquitto.prod.conf:/mosquitto/config/mosquitto.conf:ro
      - ./docker/mosquitto/config/passwd:/mosquitto/config/passwd:ro
    ports:
      - "127.0.0.1:1883:1883"  # Only localhost

  fossibot-bridge:
    image: fossibot-bridge:2.0.0  # Use specific version
    environment:
      - LOG_LEVEL=warning  # Less verbose
    restart: always
    logging:
      driver: "json-file"
      options:
        max-size: "50m"
        max-file: "5"
```

**Start with override:**
```bash
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### Enable Authentication

**1. Create Mosquitto password file:**
```bash
docker exec -it fossibot-mosquitto \
  mosquitto_passwd -c /mosquitto/config/passwd bridge_user
```

**2. Update mosquitto config:**
```conf
# docker/mosquitto/config/mosquitto.prod.conf
allow_anonymous false
password_file /mosquitto/config/passwd
```

**3. Update bridge config:**
```json
{
  "mosquitto": {
    "host": "mosquitto",
    "port": 1883,
    "username": "bridge_user",
    "password": "secret_password"
  }
}
```

**4. Restart:**
```bash
docker-compose restart
```

### Reverse Proxy (Nginx)

Expose health endpoint via HTTPS:

```nginx
# /etc/nginx/sites-available/fossibot
server {
    listen 443 ssl http2;
    server_name fossibot.example.com;

    ssl_certificate /etc/letsencrypt/live/fossibot.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/fossibot.example.com/privkey.pem;

    location /health {
        proxy_pass http://localhost:8080/health;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

---

## Troubleshooting

### Container Won't Start

**Check logs:**
```bash
docker-compose logs fossibot-bridge
```

**Common issues:**
- Config file missing → Mount `config/config.json`
- Invalid JSON → Validate with `jq . config/config.json`
- Permission denied → Check file ownership

### No MQTT Connection

**Verify Mosquitto is running:**
```bash
docker-compose ps mosquitto
```

**Test MQTT locally:**
```bash
docker exec -it fossibot-mosquitto \
  mosquitto_sub -t '$SYS/#' -C 1
```

**Check bridge config:**
```bash
docker exec -it fossibot-bridge \
  cat /etc/fossibot/config.json | jq '.mosquitto'
```

### High Memory Usage

**Check stats:**
```bash
docker stats fossibot-bridge
```

**Normal:** 30-80MB
**High (>200MB):** Restart container

```bash
docker-compose restart fossibot-bridge
```

### Auth Errors

**View detailed logs:**
```bash
docker-compose logs fossibot-bridge | grep -i auth
```

**Test credentials manually:**
```bash
docker exec -it fossibot-bridge \
  php -r "var_dump(json_decode(file_get_contents('/etc/fossibot/config.json'))->accounts);"
```

---

## Updates

### Update to Latest Version

```bash
# Pull latest code
git pull

# Rebuild image
docker-compose build

# Restart services
docker-compose down
docker-compose up -d
```

### Backup Before Update

```bash
# Backup volumes
docker run --rm \
  -v fossibot-cache:/data \
  -v $(pwd)/backup:/backup \
  alpine tar czf /backup/fossibot-cache-$(date +%Y%m%d).tar.gz -C /data .

# Backup config
cp config/config.json config/config.json.bak
```

### Rollback

```bash
# Stop services
docker-compose down

# Restore backup
tar xzf backup/fossibot-cache-20251005.tar.gz -C /var/lib/docker/volumes/fossibot-cache/_data/

# Use previous image
docker-compose up -d
```

---

## Integration Examples

### Home Assistant

Add to `configuration.yaml`:

```yaml
mqtt:
  broker: localhost  # Or Docker host IP
  port: 1883

  sensor:
    - name: "Fossibot Battery"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.soc }}"
      unit_of_measurement: "%"
      device_class: battery

  switch:
    - name: "Fossibot USB"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      payload_on: '{"action":"usb","value":true}'
      payload_off: '{"action":"usb","value":false}'
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.usbOutput }}"
```

### Prometheus Monitoring

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'fossibot'
    static_configs:
      - targets: ['localhost:8080']
    metrics_path: '/health'
```

### Grafana Dashboard

Import health metrics via JSON API datasource:
```
URL: http://localhost:8080/health
```

---

## Uninstallation

### Remove Everything

```bash
# Stop and remove containers
docker-compose down

# Remove volumes (DATA LOSS!)
docker volume rm fossibot-php2_mosquitto-data
docker volume rm fossibot-php2_mosquitto-logs
docker volume rm fossibot-php2_fossibot-cache
docker volume rm fossibot-php2_fossibot-logs

# Remove image
docker rmi fossibot-bridge:latest
```

### Keep Data, Remove Containers

```bash
# Just stop and remove containers
docker-compose down

# Volumes persist, restart with:
docker-compose up -d
```

---

## Support

**Documentation:**
- [INSTALL.md](INSTALL.md) - Installation guide
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Problem solving

**Issues:**
https://github.com/youruser/fossibot-php2/issues
