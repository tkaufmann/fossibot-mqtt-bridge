# Troubleshooting Guide

Häufige Probleme und Lösungen für Fossibot MQTT Bridge.

---

## Bridge Startup Issues

### Config-Validierung schlägt fehl

**Error:** `❌ Config validation failed`

**Ursachen:**
- Ungültige JSON-Syntax
- Fehlende Pflichtfelder
- Falsche Datentypen

**Lösung:**
```bash
# Config validieren
php daemon/fossibot-bridge.php --config config/config.json --validate

# JSON-Syntax prüfen
cat config/config.json | json_pp
```

Häufige Fehler:
- Trailing Comma in JSON
- Unquoted Strings
- Fehlende schließende Klammern

---

### Permission Denied Errors

**Error:** `Failed to read config file: Permission denied`

**Lösung:**
```bash
# File Permissions prüfen
ls -la config/config.json

# Permissions korrigieren
chmod 600 config/config.json

# Für systemd Service:
sudo chown fossibot:fossibot /etc/fossibot/config.json
sudo chmod 600 /etc/fossibot/config.json
```

---

### Verbindung zu Mosquitto schlägt fehl

**Error:** `Failed to connect to local broker: Connection refused`

**Ursachen:**
- Mosquitto läuft nicht
- Falsche Host/Port in Config
- Firewall blockiert Verbindung

**Lösung:**
```bash
# Mosquitto Status prüfen
systemctl status mosquitto

# Mosquitto starten
sudo systemctl start mosquitto

# Verbindung testen
mosquitto_pub -h localhost -t 'test' -m 'test'

# Port prüfen (sollte 1883 zeigen)
sudo netstat -tlnp | grep 1883
```

---

## Authentication Issues

### Login schlägt fehl (401 Unauthorized)

**Error:** `Stage 2 authentication failed: 401`

**Ursachen:**
- Falsche Email/Passwort
- Account gesperrt
- Tippfehler in Credentials

**Lösung:**
1. Credentials in Fossibot Mobile App testen
2. Extra-Leerzeichen in config.json prüfen
3. Sonderzeichen im Passwort korrekt escapen
4. Passwort-Reset auf Fossibot-Website versuchen

---

### MQTT Auth schlägt fehl (CONNACK code 5)

**Error:** `MQTT authentication failed, code 5`

**Ursachen:**
- MQTT Token abgelaufen
- Token Parsing fehlgeschlagen
- Systemzeit falsch

**Lösung:**
```bash
# Systemzeit prüfen
date

# Zeit synchronisieren falls nötig
sudo ntpdate pool.ntp.org

# Logs nach Token Expiry durchsuchen
tail -f logs/bridge.log | grep token

# Reconnect erzwingen (Bridge re-authentifiziert automatisch)
sudo systemctl restart fossibot-bridge
```

---

## Connection Issues

### WebSocket-Verbindung bricht häufig ab

**Symptome:**
- Häufige Disconnects in Logs
- Bridge reconnected ständig
- Exponential Backoff Delays

**Ursachen:**
- Netzwerk-Instabilität
- Firewall/NAT Timeout
- ISP blockiert WebSocket

**Lösung:**
```bash
# Reconnect-Pattern in Logs prüfen
grep "reconnect" logs/bridge.log

# Netzwerk-Stabilität testen
ping -c 100 mqtt.sydpower.com

# MTU Size prüfen (WebSocket Frames könnten fragmentiert sein)
ip link show | grep mtu

# Anderes Netzwerk versuchen falls persistent
```

---

### Bridge verliert Verbindung nach Stunden

**Symptome:**
- Funktioniert initial, stoppt nach 3-6 Stunden
- Token Expiry Messages in Logs

**Ursachen:**
- MQTT Token abgelaufen (~3 Tage Gültigkeit)
- Keep-Alive Timeout
- Memory Leak

**Lösung:**
```bash
# Token Expiry Tracking prüfen
grep "token" logs/bridge.log

# Reconnect-Logik verifizieren
tail -f logs/bridge.log

# Memory Usage prüfen
systemctl status fossibot-bridge | grep Memory

# Bei Memory Leak Verdacht:
sudo systemctl restart fossibot-bridge
```

---

## Device Discovery Issues

### Keine Geräte gefunden

**Error:** `Discovered 0 devices`

**Ursachen:**
- Account hat keine Geräte
- Geräte offline
- API Response Format geändert

**Lösung:**
```bash
# Account in Mobile App prüfen
# Geräte online verifizieren

# Debug Logging aktivieren
# Edit config: "log_level": "debug"
sudo systemctl restart fossibot-bridge

# Stage 4 Device Discovery prüfen
grep "Stage 4" logs/bridge.log
```

---

### Geräte erscheinen offline

**Symptome:**
- Gerät in Bridge Status vorhanden
- Keine State Messages publiziert
- Availability zeigt "offline"

**Ursachen:**
- Gerät tatsächlich offline (Mobile App prüfen)
- Falsche MAC-Adresse subscribed
- Gerät sendet keine Messages

**Lösung:**
```bash
# Cloud Topics direkt monitoren
mosquitto_sub -h localhost -t '#' -v | grep 7C2C67AB5F0E

# Device Last-Seen Timestamp prüfen
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v

# Gerät power cyclen
```

---

## Command Issues

### Commands funktionieren nicht

**Symptome:**
- Command publishen, nichts passiert
- Keine Error Messages
- Device State ändert sich nicht

**Ursachen:**
- Falsches Topic-Format
- Ungültige JSON-Payload
- Falsche Device MAC-Adresse
- Gerät busy/offline

**Lösung:**
```bash
# Topic Format verifizieren
mosquitto_sub -h localhost -t 'fossibot/+/command' -v

# Command manuell testen
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"usb_on"}'

# Command Translation in Logs beobachten (Debug Level)
tail -f logs/bridge.log | grep command

# Gerät online verifizieren
mosquitto_sub -h localhost -t 'fossibot/7C2C67AB5F0E/availability'
```

---

### Settings Commands verzögert

**Symptome:**
- Output Commands funktionieren sofort
- Settings Commands dauern 5-10 Sekunden
- Werden manchmal nicht angewendet

**Erklärung:** Erwartetes Verhalten. Settings Commands verwenden `CommandResponseType::DELAYED` und benötigen Device Settings Refresh.

**Workaround:**
```bash
# Nach Settings Command, read_settings senden
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"set_charging_current","amperes":15}'

# 2 Sekunden warten
sleep 2

# Settings Refresh anfordern
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' \
  -m '{"action":"read_settings"}'
```

---

## Performance Issues

### Hohe CPU-Auslastung

**Normal:** 1-5% CPU pro Account
**Hoch:** >20% CPU

**Ursachen:**
- Event Loop blockiert auf I/O
- Zu häufiges Status Publishing
- Log Level auf Debug

**Lösung:**
```bash
# CPU Usage prüfen
top -p $(pgrep -f fossibot-bridge)

# Status Interval reduzieren (config.json)
"status_publish_interval": 120  # war 60

# Log Level ändern auf "info" oder "warning"
"log_level": "info"

# Blocking Operations in Logs suchen
grep "blocked" logs/bridge.log
```

---

### Hohe Memory-Auslastung

**Normal:** 30-80MB pro Account
**Hoch:** >200MB

**Ursachen:**
- Memory Leak
- Große Message Backlog
- Zu viele Log Handler

**Lösung:**
```bash
# Memory über Zeit monitoren
watch -n 60 'systemctl status fossibot-bridge | grep Memory'

# Memory Limit in systemd setzen
sudo systemctl edit fossibot-bridge
# Add: MemoryMax=256M

# Service neu starten
sudo systemctl restart fossibot-bridge

# Memory Leak mit Logs melden
```

---

## systemd Service Issues

### Service startet nicht

**Error:** `systemd[1]: fossibot-bridge.service: Failed`

**Lösung:**
```bash
# Service Status prüfen
sudo systemctl status fossibot-bridge -l

# Vollständige Logs ansehen
sudo journalctl -u fossibot-bridge -n 100

# Manuell testen
sudo -u fossibot php /opt/fossibot-bridge/daemon/fossibot-bridge.php \
  --config /etc/fossibot/config.json
```

---

### Service startet ständig neu

**Symptome:**
- `Restart=always` verursacht Restart Loop
- Bridge crashed sofort

**Lösung:**
```bash
# Crash-Grund prüfen
sudo journalctl -u fossibot-bridge -n 200

# Auto-Restart temporär deaktivieren
sudo systemctl edit fossibot-bridge
# Add: Restart=no

# Manuellen Start testen
sudo systemctl start fossibot-bridge
sudo systemctl status fossibot-bridge
```

---

## Logging Issues

### Log-File wird nicht erstellt

**Error:** `Failed to open log file`

**Lösung:**
```bash
# Log-Verzeichnis erstellen
sudo mkdir -p /var/log/fossibot
sudo chown fossibot:fossibot /var/log/fossibot
sudo chmod 755 /var/log/fossibot

# Für systemd: In Service File hinzufügen
ReadWritePaths=/var/log/fossibot
```

---

### Logs zu verbose

**Problem:** Log-File wächst auf GB-Größe

**Lösung:**
```bash
# Log Level ändern
# Edit config: "log_level": "warning"

# Logrotate einrichten
sudo nano /etc/logrotate.d/fossibot

# Hinzufügen:
/var/log/fossibot/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
}

# Rotation testen
sudo logrotate -f /etc/logrotate.d/fossibot
```

---

## Hilfe erhalten

Falls Problem weiterhin besteht:

**1. Debug Logging aktivieren:**
```json
"log_level": "debug"
```

**2. Logs sammeln:**
```bash
tail -n 500 logs/bridge.log > debug.log
```

**3. Versionen prüfen:**
```bash
php --version
composer show | grep react
mosquitto -h | head -n 1
```

**4. GitHub Issue öffnen** mit:
- Debug-Log-Auszug
- Config (Passwörter entfernen!)
- PHP Version
- OS Version
- Reproduktions-Schritte

---

**Immer noch nicht gelöst?** Existierende Issues durchsuchen oder neues Issue auf GitHub öffnen.
