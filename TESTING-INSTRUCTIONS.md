# Testing Instructions (für externen Claude Code)

Anleitung zum Testen der Fossibot MQTT Bridge ohne Kenntnis der Implementierung.

---

## Setup

### 1. Neues Test-Projekt erstellen

```bash
mkdir fossibot-bridge-test
cd fossibot-bridge-test
```

### 2. composer.json erstellen

```json
{
  "name": "test/fossibot-bridge",
  "require": {
    "php": ">=8.2"
  },
  "repositories": [
    {
      "type": "path",
      "url": "/Users/tim/Code/fossibot-php2",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "tkaufmann/fossibot-php": "@dev"
  }
}
```

### 3. Dependencies installieren

```bash
composer install
```

Die Bridge-Dateien befinden sich dann in `vendor/tkaufmann/fossibot-php/`.

### 4. Config erstellen

```bash
mkdir config
cp vendor/tkaufmann/fossibot-php/config/example.json config/config.json
nano config/config.json
```

Trage deine Fossibot-Credentials ein.

### 5. Bridge starten

```bash
php vendor/tkaufmann/fossibot-php/daemon/fossibot-bridge.php --config config/config.json
```

---

## Test-Aufgaben

### Dokumentation lesen

Lies folgende Dokumente in dieser Reihenfolge:

1. `vendor/tkaufmann/fossibot-php/README.md` - Übersicht
2. `vendor/tkaufmann/fossibot-php/QUICKSTART.md` - Setup-Anleitung
3. `vendor/tkaufmann/fossibot-php/docs/daemon/02-TOPICS-MESSAGES.md` - MQTT-Protokoll
4. `vendor/tkaufmann/fossibot-php/examples/README.md` - Integration-Beispiele

### Funktions-Tests durchführen

**1. Bridge-Start verifizieren**
- Bridge startet ohne Fehler
- Verbindet zu Mosquitto
- Authentifiziert gegen Fossibot Cloud
- Findet Geräte

**2. MQTT Topics prüfen**
```bash
# State Messages empfangen
mosquitto_sub -h localhost -t 'fossibot/+/state' -v

# Bridge Status prüfen
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v

# Availability prüfen
mosquitto_sub -h localhost -t 'fossibot/+/availability' -v
```

**3. Commands testen**
```bash
# USB einschalten
mosquitto_pub -h localhost -t 'fossibot/DEINE_MAC/command' \
  -m '{"action":"usb_on"}'

# USB ausschalten
mosquitto_pub -h localhost -t 'fossibot/DEINE_MAC/command' \
  -m '{"action":"usb_off"}'

# Settings ändern
mosquitto_pub -h localhost -t 'fossibot/DEINE_MAC/command' \
  -m '{"action":"set_charging_current","amperes":10}'
```

**4. Integration-Beispiel testen**

Wähle eines der Beispiele aus `vendor/tkaufmann/fossibot-php/examples/`:
- Python Client ausführen
- Home Assistant Config testen (falls HA vorhanden)
- Node-RED Flow importieren (falls Node-RED vorhanden)

### Fehlerszenarien testen

**1. Falsche Credentials**
- Config mit falschen Credentials → sollte 401 Error geben

**2. Config Validation**
```bash
php vendor/tkaufmann/fossibot-php/daemon/fossibot-bridge.php \
  --config config/config.json --validate
```

**3. Troubleshooting Guide durchgehen**

Lies `vendor/tkaufmann/fossibot-php/docs/daemon/TROUBLESHOOTING.md` und prüfe:
- Sind die Lösungen verständlich?
- Sind die Befehle korrekt?
- Fehlen wichtige Szenarien?

---

## Test-Ergebnis dokumentieren

Erstelle `TEST-REPORT.md` mit folgenden Informationen:

### ✅ Funktioniert

- Liste was funktioniert hat
- Screenshots/Logs von erfolgreichen Tests

### ❌ Probleme gefunden

- Beschreibung des Problems
- Erwartetes Verhalten
- Tatsächliches Verhalten
- Logs/Fehlermeldungen

### 📝 Dokumentations-Feedback

- Unklare Stellen
- Fehlende Informationen
- Verbesserungsvorschläge

### 🎯 Gesamtbewertung

- Kann die Bridge produktiv eingesetzt werden?
- Ist die Dokumentation ausreichend?
- Welche kritischen Issues müssen behoben werden?

---

## Hinweise

- Du kennst den Implementierungs-Code NICHT
- Nutze ausschließlich die bereitgestellte Dokumentation
- Teste wie ein externer Benutzer
- Melde alles was unklar oder fehlerhaft ist
