# Mosquitto Custom Image

Erweitert das offizielle `eclipse-mosquitto:2` Image mit einer Default-Konfiguration.

## Standard-Verhalten

Das Image enthält eine funktionierende Default-Config (`mosquitto.conf`):
- MQTT auf Port 1883
- WebSocket auf Port 9001  
- Anonymous Access aktiviert (nur für lokales Netzwerk!)
- Persistence aktiviert
- Logging nach stdout

## Config anpassen

### Option 1: Config im Image ändern (empfohlen für Deployment)

```bash
# 1. Bearbeite config/mosquitto.conf
nano docker/mosquitto/config/mosquitto.conf

# 2. Rebuild Image
docker compose build mosquitto

# 3. Restart
docker compose up -d mosquitto
```

### Option 2: Config per Volume Mount überschreiben

In `docker-compose.yml` aktivieren:
```yaml
volumes:
  - ./docker/mosquitto/config/mosquitto.conf:/mosquitto/config/mosquitto.conf:ro
```

Dann:
```bash
docker compose up -d
```

## Production: Authentication aktivieren

1. Password-Datei erstellen:
```bash
docker compose exec mosquitto mosquitto_passwd -c /mosquitto/config/passwd username
```

2. Config anpassen:
```conf
allow_anonymous false
password_file /mosquitto/config/passwd
```

3. In docker-compose.yml passwd-File mounten:
```yaml
volumes:
  - ./docker/mosquitto/config/passwd:/mosquitto/config/passwd:ro
```

4. Restart:
```bash
docker compose restart mosquitto
```
