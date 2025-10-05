# Docker Compose Deployment

Vollständige Deployment-Lösung mit Mosquitto MQTT Broker und Fossibot Bridge.

---

## Quick Start

### 1. Repository klonen

```bash
git clone https://github.com/tkaufmann/fossibot-php2.git
cd fossibot-php2
```

### 2. Config erstellen

```bash
cp config/config.docker.json config/config.json
nano config/config.json
```

**Minimal Config:**
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

### 3. Starten

```bash
docker-compose up -d
```

### 4. Verifizieren

```bash
# Services prüfen
docker-compose ps

# Logs anschauen
docker-compose logs -f

# Health Check
curl http://localhost:8080/health | jq '.'

# MQTT Topics
docker exec -it fossibot-mosquitto mosquitto_sub -t 'fossibot/#' -v
```

---

## docker-compose.yml Struktur

```yaml
version: '3.8'

services:
  mosquitto:
    image: eclipse-mosquitto:2
    container_name: fossibot-mosquitto
    restart: unless-stopped
    ports:
      - "1883:1883"    # MQTT
      - "9001:9001"    # WebSocket (optional)
    volumes:
      - ./docker/mosquitto/config:/mosquitto/config:ro
      - mosquitto-data:/mosquitto/data
      - mosquitto-logs:/mosquitto/log
    networks:
      - fossibot-net
    healthcheck:
      test: ["CMD", "mosquitto_sub", "-t", "$$SYS/#", "-C", "1"]
      interval: 30s
      timeout: 10s
      retries: 3

  fossibot-bridge:
    image: tkaufmann/fossibot-bridge:latest
    container_name: fossibot-bridge
    restart: unless-stopped
    depends_on:
      mosquitto:
        condition: service_healthy
    ports:
      - "8080:8080"    # Health endpoint
    volumes:
      - ./config/config.json:/etc/fossibot/config.json:ro
      - fossibot-cache:/var/lib/fossibot
      - fossibot-logs:/var/log/fossibot
    networks:
      - fossibot-net
    environment:
      - TZ=Europe/Berlin
    healthcheck:
      test: ["CMD", "php", "-r", "echo file_get_contents('http://localhost:8080/health') ?: exit(1);"]
      interval: 30s
      timeout: 10s
      start_period: 15s
      retries: 3

networks:
  fossibot-net:
    driver: bridge

volumes:
  mosquitto-data:
  mosquitto-logs:
  fossibot-cache:
  fossibot-logs:
```

---

## Management

### Starten

```bash
# Alle Services
docker-compose up -d

# Nur Bridge
docker-compose up -d fossibot-bridge

# Mit Build
docker-compose up -d --build
```

### Stoppen

```bash
# Alle Services
docker-compose down

# Services stoppen, Volumes behalten
docker-compose stop

# Einzelner Service
docker-compose stop fossibot-bridge
```

### Restart

```bash
# Alle Services
docker-compose restart

# Einzelner Service
docker-compose restart fossibot-bridge
```

### Logs

```bash
# Alle Services live
docker-compose logs -f

# Nur Bridge
docker-compose logs -f fossibot-bridge

# Nur Mosquitto
docker-compose logs -f mosquitto

# Letzte 100 Zeilen
docker-compose logs --tail=100 fossibot-bridge

# Seit heute
docker-compose logs --since "$(date +%Y-%m-%d)" fossibot-bridge
```

### Status

```bash
# Services Status
docker-compose ps

# Resource Usage
docker stats fossibot-bridge fossibot-mosquitto

# Health Status
docker-compose ps | grep -E "healthy|unhealthy"
```

---

## Production Setup

### Override File

Create `docker-compose.prod.yml`:

```yaml
version: '3.8'

services:
  mosquitto:
    volumes:
      - ./docker/mosquitto/config/mosquitto.prod.conf:/mosquitto/config/mosquitto.conf:ro
      - ./docker/mosquitto/config/passwd:/mosquitto/config/passwd:ro
    ports:
      - "127.0.0.1:1883:1883"  # Nur localhost
      - "127.0.0.1:9001:9001"

  fossibot-bridge:
    image: tkaufmann/fossibot-bridge:2.0.0  # Fixed version
    environment:
      - LOG_LEVEL=warning
    ports:
      - "127.0.0.1:8080:8080"  # Nur localhost
    restart: always
    logging:
      driver: "json-file"
      options:
        max-size: "50m"
        max-file: "5"
```

**Starten mit Override:**
```bash
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### Mosquitto Authentication

**1. Erstelle Passwort-Datei:**
```bash
docker exec -it fossibot-mosquitto \
  mosquitto_passwd -c /mosquitto/config/passwd bridge_user

# Enter password when prompted
```

**2. Erstelle Production Config:**
```bash
cat > docker/mosquitto/config/mosquitto.prod.conf << 'EOF'
listener 1883
protocol mqtt

listener 9001
protocol websockets

persistence true
persistence_location /mosquitto/data/

log_dest file /mosquitto/log/mosquitto.log
log_dest stdout
log_type error
log_type warning

allow_anonymous false
password_file /mosquitto/config/passwd

max_inflight_messages 20
max_queued_messages 1000
max_connections -1
EOF
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
docker-compose -f docker-compose.yml -f docker-compose.prod.yml restart
```

---

## Environment Variables

### Via docker-compose.yml

```yaml
services:
  fossibot-bridge:
    environment:
      - FOSSIBOT_EMAIL=user@example.com
      - FOSSIBOT_PASSWORD=secret
      - MOSQUITTO_HOST=mosquitto
      - LOG_LEVEL=debug
```

### Via .env File

Create `.env`:
```bash
FOSSIBOT_EMAIL=user@example.com
FOSSIBOT_PASSWORD=secret
MOSQUITTO_HOST=mosquitto
LOG_LEVEL=info
TZ=Europe/Berlin
```

Update `docker-compose.yml`:
```yaml
services:
  fossibot-bridge:
    env_file:
      - .env
```

---

## External Services

### Externe Mosquitto Instanz

```yaml
services:
  fossibot-bridge:
    # Remove mosquitto from depends_on
    environment:
      - MOSQUITTO_HOST=mqtt.example.com
      - MOSQUITTO_PORT=1883
      - MOSQUITTO_USERNAME=bridge
      - MOSQUITTO_PASSWORD=secret
```

**Nur Bridge starten:**
```bash
docker-compose up -d fossibot-bridge
```

### Externe Datenbank (optional, für Token-Cache)

Aktuell verwendet Bridge File-Cache. Für Multi-Instance Setup:

```yaml
services:
  redis:
    image: redis:alpine
    networks:
      - fossibot-net

  fossibot-bridge:
    environment:
      - CACHE_DRIVER=redis
      - REDIS_HOST=redis
```

---

## Volumes

### Named Volumes (Default)

```yaml
volumes:
  mosquitto-data:
  mosquitto-logs:
  fossibot-cache:
  fossibot-logs:
```

**Inspect:**
```bash
docker volume ls | grep fossibot
docker volume inspect fossibot-php2_fossibot-cache
```

**Backup:**
```bash
docker run --rm \
  -v fossibot-php2_fossibot-cache:/data \
  -v $(pwd)/backup:/backup \
  alpine tar czf /backup/cache-$(date +%Y%m%d).tar.gz -C /data .
```

**Restore:**
```bash
docker run --rm \
  -v fossibot-php2_fossibot-cache:/data \
  -v $(pwd)/backup:/backup \
  alpine tar xzf /backup/cache-20251005.tar.gz -C /data
```

### Bind Mounts

```yaml
services:
  mosquitto:
    volumes:
      - /opt/mosquitto/data:/mosquitto/data
      - /opt/mosquitto/logs:/mosquitto/log

  fossibot-bridge:
    volumes:
      - /opt/fossibot/cache:/var/lib/fossibot
      - /opt/fossibot/logs:/var/log/fossibot
```

---

## Monitoring

### Prometheus Integration

**1. Add to docker-compose.yml:**
```yaml
services:
  prometheus:
    image: prom/prometheus
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus-data:/prometheus
    ports:
      - "9090:9090"
    networks:
      - fossibot-net

volumes:
  prometheus-data:
```

**2. Create prometheus.yml:**
```yaml
global:
  scrape_interval: 30s

scrape_configs:
  - job_name: 'fossibot'
    static_configs:
      - targets: ['fossibot-bridge:8080']
    metrics_path: '/health'
```

### Grafana Dashboard

```yaml
services:
  grafana:
    image: grafana/grafana
    ports:
      - "3000:3000"
    volumes:
      - grafana-data:/var/lib/grafana
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=secret
    networks:
      - fossibot-net

volumes:
  grafana-data:
```

### Health Checks

```bash
# Docker native
docker-compose ps

# HTTP endpoint
curl http://localhost:8080/health | jq '.'

# MQTT broker
docker exec fossibot-mosquitto mosquitto_sub -t '$SYS/broker/uptime' -C 1
```

---

## Reverse Proxy

### Nginx

**1. Add to docker-compose.yml:**
```yaml
services:
  nginx:
    image: nginx:alpine
    ports:
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - /etc/letsencrypt:/etc/letsencrypt:ro
    networks:
      - fossibot-net
    depends_on:
      - fossibot-bridge
```

**2. Create nginx.conf:**
```nginx
events {}

http {
    server {
        listen 443 ssl http2;
        server_name fossibot.example.com;

        ssl_certificate /etc/letsencrypt/live/fossibot.example.com/fullchain.pem;
        ssl_certificate_key /etc/letsencrypt/live/fossibot.example.com/privkey.pem;

        location /health {
            proxy_pass http://fossibot-bridge:8080/health;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
        }
    }
}
```

### Traefik

```yaml
services:
  traefik:
    image: traefik:v2.10
    command:
      - --providers.docker=true
      - --entrypoints.web.address=:80
      - --entrypoints.websecure.address=:443
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
    networks:
      - fossibot-net

  fossibot-bridge:
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.fossibot.rule=Host(`fossibot.example.com`)"
      - "traefik.http.routers.fossibot.entrypoints=websecure"
      - "traefik.http.routers.fossibot.tls=true"
      - "traefik.http.services.fossibot.loadbalancer.server.port=8080"
```

---

## Multi-Instance Setup

Für mehrere Bridge-Instanzen (z.B. verschiedene Accounts):

**1. Create docker-compose.multi.yml:**
```yaml
version: '3.8'

services:
  mosquitto:
    image: eclipse-mosquitto:2
    ports:
      - "1883:1883"
    networks:
      - fossibot-net

  fossibot-bridge-1:
    image: tkaufmann/fossibot-bridge:latest
    volumes:
      - ./config/account1.json:/etc/fossibot/config.json:ro
      - fossibot-cache-1:/var/lib/fossibot
    networks:
      - fossibot-net

  fossibot-bridge-2:
    image: tkaufmann/fossibot-bridge:latest
    volumes:
      - ./config/account2.json:/etc/fossibot/config.json:ro
      - fossibot-cache-2:/var/lib/fossibot
    networks:
      - fossibot-net

networks:
  fossibot-net:

volumes:
  fossibot-cache-1:
  fossibot-cache-2:
```

**2. Start:**
```bash
docker-compose -f docker-compose.multi.yml up -d
```

---

## Updates

### Update Images

```bash
# Pull neueste Images
docker-compose pull

# Restart mit neuen Images
docker-compose up -d

# Remove alte Images
docker image prune -f
```

### Update Config

```bash
# Config bearbeiten
nano config/config.json

# Restart Bridge
docker-compose restart fossibot-bridge
```

### Update Repository

```bash
git pull
docker-compose down
docker-compose up -d --build
```

---

## Troubleshooting

### Services starten nicht

```bash
# Logs aller Services
docker-compose logs

# Validation
docker-compose config

# Einzelner Service
docker-compose up fossibot-bridge
```

### Network Issues

```bash
# Network prüfen
docker network inspect fossibot-php2_fossibot-net

# DNS Test
docker-compose exec fossibot-bridge ping mosquitto

# Port Bindings
docker-compose port mosquitto 1883
```

### Volume Permissions

```bash
# Inspect volumes
docker volume inspect fossibot-php2_fossibot-cache

# Fix permissions (if needed)
docker-compose exec fossibot-bridge chown -R fossibot:fossibot /var/lib/fossibot
```

---

## Cleanup

### Stop und Remove

```bash
# Stop alle Services
docker-compose down

# Remove volumes (DATA LOSS!)
docker-compose down -v

# Remove images
docker-compose down --rmi all
```

### Selective Cleanup

```bash
# Nur Container
docker-compose rm -f

# Nur unused volumes
docker volume prune

# Nur unused images
docker image prune -a
```

---

## Integration Examples

### Home Assistant

```yaml
# configuration.yaml
mqtt:
  broker: <docker-host-ip>
  port: 1883
  # Optional: Authentication
  username: bridge_user
  password: secret

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

### Node-RED

**1. Add Node-RED to compose:**
```yaml
services:
  node-red:
    image: nodered/node-red
    ports:
      - "1880:1880"
    volumes:
      - nodered-data:/data
    networks:
      - fossibot-net
    environment:
      - TZ=Europe/Berlin

volumes:
  nodered-data:
```

**2. MQTT Config in Node-RED:**
- Server: `mosquitto`
- Port: `1883`
- Topics: `fossibot/#`

---

## Weitere Dokumentation

- [docker run Deployment](DOCKER_RUN.md)
- [Vollständige Docker Doku](DOCKER.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [Installation Guide](INSTALL.md)
