# Integration Examples

Integrationsbeispiele für verschiedene Home-Automation-Plattformen.

## Verfügbare Beispiele

### Home Assistant
**Datei:** `homeassistant.yaml`

MQTT-Konfiguration für Home Assistant mit:
- Batterie-Sensor
- Output-Schalter (USB, AC, DC, LED)
- Einstellungs-Controls (Ladestrom, Grenzen)
- Verfügbarkeits-Sensor
- Beispiel-Automation

**Installation:**
1. Inhalt in `configuration.yaml` unter `mqtt:` einfügen
2. MAC-Adresse `7C2C67AB5F0E` durch deine Geräte-MAC ersetzen
3. Home Assistant neu starten
4. Entities unter "Fossibot" Device finden

### Node-RED
**Datei:** `nodered.json`

Node-RED Flow mit:
- MQTT State Subscriber mit JSON-Parser
- SoC Gauge Widget
- Command Inject Buttons
- Debug Output

**Installation:**
1. Node-RED öffnen
2. Menu → Import → Clipboard
3. Inhalt von `nodered.json` einfügen
4. MQTT Broker Node konfigurieren (localhost:1883)
5. MAC-Adresse bei Bedarf anpassen
6. Deploy

### IP-Symcon
**Datei:** `ipsymcon.php`

IP-Symcon Modul mit:
- MQTT Message Handler
- Variable Creation für State
- Action Functions für Commands
- Helper Functions

**Installation:**
1. MQTT Client Modul in IP-Symcon installieren
2. Neues Script-Modul erstellen
3. Inhalt von `ipsymcon.php` kopieren
4. `$mqttClientId` und `$deviceMac` anpassen
5. Speichern und ausführen

### Python Client
**Datei:** `python_client.py`

Standalone Python MQTT Client mit:
- Device State Monitoring
- Interaktivem Command-Menü
- Beispiel-Funktionen für alle Commands

**Installation:**
```bash
pip install paho-mqtt
python examples/python_client.py
```

## Geräte-MAC herausfinden

```bash
mosquitto_sub -h localhost -t 'fossibot/bridge/status' -v
```

Suche nach `devices[].id` im JSON-Response.

## Beispiele testen

Alle Beispiele gehen von folgender Umgebung aus:
- Mosquitto läuft auf localhost:1883
- Fossibot Bridge Daemon läuft
- Mindestens ein Gerät verbunden

**Bridge-Status prüfen:**
```bash
systemctl status fossibot-bridge
```

**MQTT-Traffic überwachen:**
```bash
mosquitto_sub -h localhost -t 'fossibot/#' -v
```
