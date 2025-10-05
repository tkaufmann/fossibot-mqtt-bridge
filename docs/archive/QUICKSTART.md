# Quick Start Guide

Fossibot MQTT Bridge in 5 Minuten einrichten.

---

## Voraussetzungen

- Ubuntu/Debian Linux (oder Raspberry Pi)
- PHP 8.1+ mit cli, mbstring, xml, curl Extensions
- Mosquitto MQTT Broker
- Fossibot-Account Zugangsdaten

---

## Installation

### 1. System-Dependencies installieren

```bash
sudo apt update
sudo apt install php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl composer mosquitto -y
sudo systemctl enable mosquitto
sudo systemctl start mosquitto
```

### 2. Repository klonen

```bash
git clone https://github.com/youruser/fossibot-php2.git
cd fossibot-php2
```

### 3. PHP Dependencies installieren

```bash
composer install --no-dev
```

### 4. Konfigurieren

```bash
cp config/example.json config/config.json
nano config/config.json
```

**Minimale Konfiguration:**
```json
{
  "accounts": [
    {
      "email": "DEINE_EMAIL@example.com",
      "password": "DEIN_PASSWORD",
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
    "log_file": "logs/bridge.log",
    "log_level": "info"
  },
  "bridge": {
    "status_publish_interval": 60,
    "reconnect_delay_min": 5,
    "reconnect_delay_max": 60
  }
}
```

**Anpassen:** `DEINE_EMAIL@example.com` und `DEIN_PASSWORD`

### 5. Config validieren

```bash
php daemon/fossibot-bridge.php --config config/config.json --validate
```

Erwartet: `✅ Config valid`

### 6. Bridge starten

```bash
php daemon/fossibot-bridge.php --config config/config.json
```

Erwartete Ausgabe:
```
Starting bridge (press Ctrl+C to stop)...
═══════════════════════════════════════

[2025-09-30 12:00:00] fossibot_bridge.INFO: Fossibot MQTT Bridge starting
[2025-09-30 12:00:01] fossibot_bridge.INFO: Connected to local MQTT broker
[2025-09-30 12:00:02] fossibot_bridge.INFO: Cloud client connected {"email":"your@email.com"}
[2025-09-30 12:00:03] fossibot_bridge.INFO: Discovered 1 devices
```

---

## Verifikation

### Device Discovery prüfen

Neues Terminal öffnen:

```bash
mosquitto_sub -h localhost -t 'fossibot/+/state' -v
```

Nach max. 30 Sekunden sollten Device-State-Messages erscheinen:
```json
fossibot/7C2C67AB5F0E/state {"soc":85.5,"usbOutput":true,...}
```

### Command testen

```bash
# MAC durch deine Geräte-MAC von oben ersetzen
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'
```

USB-Ausgang sollte sich einschalten!

---

## Als System Service installieren (Optional)

Für Production-Einsatz als systemd Service:

```bash
cd daemon
sudo ./install-systemd.sh

# Config editieren
sudo nano /etc/fossibot/config.json

# Service starten
sudo systemctl enable fossibot-bridge
sudo systemctl start fossibot-bridge

# Status prüfen
sudo systemctl status fossibot-bridge
```

---

## Integration

Smart-Home-Plattform anbinden:

- **Home Assistant:** `examples/homeassistant.yaml` in Config kopieren
- **Node-RED:** `examples/nodered.json` importieren
- **Python:** `python examples/python_client.py` ausführen

Details: `examples/README.md`

---

## Troubleshooting

### Bridge startet nicht

**Config-Syntax prüfen:**
```bash
php daemon/fossibot-bridge.php --config config/config.json --validate
```

**Mosquitto prüfen:**
```bash
sudo systemctl status mosquitto
```

### Keine Geräte gefunden

**Credentials prüfen:**
- Email/Passwort korrekt?
- Login in Fossibot Mobile App testen

**Logs prüfen:**
```bash
tail -f logs/bridge.log
```

Suche nach Authentication-Errors (401/403).

### Gerät reagiert nicht auf Commands

**MQTT-Traffic überwachen:**
```bash
mosquitto_sub -h localhost -t 'fossibot/#' -v
```

Du solltest State-Messages und Command-Echos sehen.

**Geräte-MAC verifizieren:**
```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v
```

---

## Nächste Schritte

- Vollständige Dokumentation: `docs/daemon/`
- Production-Deployment: `daemon/DEPLOYMENT.md`
- MQTT-API erkunden: `docs/daemon/02-TOPICS-MESSAGES.md`

---

**Hilfe benötigt?** GitHub Issue öffnen mit:
- Output von `--validate` Command
- Log-Auszug mit Error
- OS und PHP Version
