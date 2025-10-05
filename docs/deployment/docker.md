# Docker Quick Start

Einfachste Methode zum Deployen der Fossibot MQTT Bridge.

## 🚀 Quick Start (3 Schritte)

### 1. Config erstellen

```bash
cp config/config.docker.json config/config.json
nano config/config.json
```

**Minimum Config:**
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

### 2. Starten

```bash
# Automatisch
./docker-start.sh

# Oder manuell
docker-compose up -d
```

### 3. Testen

```bash
# Logs anschauen
docker-compose logs -f fossibot-bridge

# MQTT Topics abonnieren
docker exec -it fossibot-mosquitto mosquitto_sub -t 'fossibot/#' -v

# Health Check
curl http://localhost:8080/health | jq '.'
```

## 📦 Was wird deployed?

- **Mosquitto MQTT Broker** (Port 1883, 9001)
- **Fossibot Bridge** (Port 8080 für Health-Endpoint)
- **Volumes** für Persistence (Cache, Logs, MQTT Data)
- **Health Checks** für beide Services

## 🔧 Management

```bash
# Status
docker-compose ps

# Logs
docker-compose logs -f

# Restart
docker-compose restart fossibot-bridge

# Stop
docker-compose down

# Update
git pull && docker-compose up -d --build
```

## 🏠 Home Assistant Integration

```yaml
mqtt:
  broker: <docker-host-ip>
  port: 1883

  sensor:
    - name: "Fossibot Battery"
      state_topic: "fossibot/7C2C67AB5F0E/state"
      value_template: "{{ value_json.soc }}"
      unit_of_measurement: "%"

  switch:
    - name: "Fossibot USB"
      command_topic: "fossibot/7C2C67AB5F0E/command"
      payload_on: '{"action":"usb","value":true}'
      payload_off: '{"action":"usb","value":false}'
```

## 🔒 Production Setup

### Mit Authentication

```bash
# Mosquitto Passwort setzen
docker exec -it fossibot-mosquitto \
  mosquitto_passwd -c /mosquitto/config/passwd bridge_user

# mosquitto.conf anpassen
echo "allow_anonymous false" >> docker/mosquitto/config/mosquitto.conf
echo "password_file /mosquitto/config/passwd" >> docker/mosquitto/config/mosquitto.conf

# config.json anpassen
nano config/config.json  # mosquitto.username + password setzen

# Restart
docker-compose restart
```

### Externe Mosquitto Instanz

```json
{
  "mosquitto": {
    "host": "mqtt.example.com",
    "port": 1883,
    "username": "bridge",
    "password": "secret"
  }
}
```

Dann nur Bridge starten:
```bash
docker-compose up -d fossibot-bridge
```

## 📊 Monitoring

```bash
# Container Stats
docker stats fossibot-bridge

# Health Endpoint
curl http://localhost:8080/health | jq '.'

# MQTT Stats
docker exec -it fossibot-mosquitto mosquitto_sub -t '$SYS/#' -C 10
```

## 🐛 Troubleshooting

### Container startet nicht

```bash
docker-compose logs fossibot-bridge
```

Häufige Probleme:
- Config-Datei fehlt → `cp config/config.docker.json config/config.json`
- Ungültiges JSON → `jq . config/config.json`

### Keine MQTT-Verbindung

```bash
# Mosquitto läuft?
docker-compose ps mosquitto

# Test lokal
docker exec -it fossibot-mosquitto mosquitto_sub -t '$SYS/#' -C 1

# Bridge Config prüfen
docker exec -it fossibot-bridge cat /etc/fossibot/config.json | jq '.mosquitto'
```

### Auth Fehler

```bash
# Detaillierte Logs
docker-compose logs fossibot-bridge | grep -i auth

# Credentials prüfen
docker exec -it fossibot-bridge \
  php -r "print_r(json_decode(file_get_contents('/etc/fossibot/config.json'))->accounts);"
```

## 📚 Vollständige Dokumentation

Siehe [docs/DOCKER.md](docs/DOCKER.md) für:
- Multi-Platform Builds
- Reverse Proxy Setup (Nginx)
- Backup & Restore
- Production Best Practices
- Integration Examples (Prometheus, Grafana)

## 🆘 Support

- **Docs**: [docs/](docs/)
- **Issues**: https://github.com/youruser/fossibot-php2/issues
