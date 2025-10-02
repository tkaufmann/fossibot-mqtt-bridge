# Fossibot F2400 API Test Plan

Systematische Analyse aller möglichen Interaktionen zwischen Commands, Polling und manuellen Änderungen.

## Bekanntes Verhalten (Hardware-Verifiziert)

### ✅ Output Commands mit State Change
- **Szenario**: USB OFF Command senden, USB ist aktuell ON
- **Ergebnis**:
  - /client/04 Response innerhalb 1-2 Sekunden
  - Register 41 Bit 0 = 0 (USB OFF)
  - Nachfolgende /client/data werden 35 Sekunden ignoriert

### ✅ Output Commands ohne State Change (Redundant)
- **Szenario**: USB ON Command senden, USB ist bereits ON
- **Ergebnis**:
  - KEINE /client/04 Response
  - Device antwortet nicht auf redundante Commands
  - /client/data Polling läuft normal weiter

### ✅ Periodisches Polling
- **Szenario**: Kein Command, normaler Betrieb
- **Ergebnis**:
  - /client/data alle 30 Sekunden
  - Alle 81 Register inklusive aktueller Output States
  - Verwendet für Power/SoC/Settings Updates

### ✅ Topic Priority System
- **Szenario**: USB OFF Command → /client/04 Response → /client/data 4s später
- **Ergebnis**:
  - /client/04 Update wird sofort angewendet
  - /client/data Output States werden ignoriert (Log: "recent /client/04 update exists")
  - Power/SoC/Settings von /client/data werden trotzdem verwendet

### ✅ Spontane Device Updates
- **Szenario**: Device sendet /client/04 ohne vorheriges Command
- **Ergebnis**:
  - Kann jederzeit passieren (vermutlich lokale Button-Presses)
  - Wird wie normale /client/04 Response behandelt
  - Triggert 35-Sekunden Priority Window

## Unbekanntes Verhalten - Test Szenarien

### 🔬 Kategorie A: Race Conditions zwischen Commands und Polling

#### Test A1: Command kurz vor Polling
**Setup**:
- Warte bis ~28 Sekunden nach letztem /client/data
- Sende USB OFF Command (2 Sekunden vor nächstem Polling)

**Zu beobachten**:
- [ ] Kommt /client/04 Response?
- [ ] Kommt /client/data zur normalen Zeit?
- [ ] Welche Daten enthält /client/data?
- [ ] Wird /client/data korrekt ignoriert?

**Hypothese**: /client/04 kommt zuerst, dann /client/data mit alten Daten, Priority System funktioniert

---

#### Test A2: Command genau während Polling
**Setup**:
- Beobachte /client/data Timing
- Sende Command exakt wenn /client/data erwartet wird

**Zu beobachten**:
- [ ] Verzögert sich /client/04?
- [ ] Verzögert sich /client/data?
- [ ] Kommt beides gleichzeitig?
- [ ] Reihenfolge der MQTT Messages?

**Hypothese**: Device kann beide Topics parallel senden, MQTT Broker garantiert keine Reihenfolge

---

### 🔬 Kategorie B: Manuelle Button-Presses am Device

#### Test B1: Button Press ohne Commands
**Setup**:
- USB ist OFF
- Kein Bridge Command aktiv
- Physischer Button Press am Device: USB ON

**Zu beobachten**:
- [ ] Kommt /client/04 Response?
- [ ] Timing: Sofort oder mit Delay?
- [ ] Welche Register sind im Response enthalten?
- [ ] Unterscheidet sich Format von Command-Response?

**Hypothese**: Button Press triggert /client/04 genau wie Command, identisches Format

---

#### Test B2: Button Press direkt nach Command
**Setup**:
- Sende USB OFF Command via MQTT
- Warte 0.5 Sekunden
- Button Press am Device: USB ON

**Zu beobachten**:
- [ ] Kommen zwei /client/04 Responses?
- [ ] Timing zwischen den Responses?
- [ ] Verarbeitet Bridge beide korrekt?
- [ ] State nach beiden Updates?

**Hypothese**: Zwei separate /client/04, Bridge verarbeitet beide, finaler State = USB ON

---

#### Test B3: Button Press zwischen Command und Response
**Setup**:
- Sende USB OFF Command
- Sofort danach (<0.5s): Button Press USB ON

**Zu beobachten**:
- [ ] Wie viele /client/04 Responses?
- [ ] Welcher State wird final gesetzt?
- [ ] Kann Device zwei State-Changes so schnell verarbeiten?
- [ ] Gibt es "Lost Updates"?

**Hypothese**: Device serialisiert State Changes, beide Commands werden verarbeitet in Reihenfolge

---

#### Test B4: Button Press kurz vor Polling
**Setup**:
- Warte bis ~28 Sekunden nach letztem /client/data
- Button Press am Device: AC OFF

**Zu beobachten**:
- [ ] /client/04 Timing?
- [ ] /client/data kommt zur normalen Zeit?
- [ ] Enthält /client/data bereits neuen State?
- [ ] Priority System funktioniert?

**Hypothese**: /client/04 sofort, /client/data mit neuem State, beide werden korrekt verarbeitet

---

### 🔬 Kategorie C: Settings Commands

#### Test C1: Settings Command Timing
**Setup**:
- Sende Max Charging Current = 15A
- Beobachte alle Topics

**Zu beobachten**:
- [ ] Kommt /client/04 Response? (Sollte NICHT)
- [ ] Wann erscheint neuer Wert in /client/data?
- [ ] Ist es genau beim nächsten Polling oder früher?
- [ ] Gibt es ein anderes Topic für Settings?

**Hypothese**: Keine /client/04, Wert erscheint im nächsten /client/data (bis zu 30s Delay)

---

#### Test C2: Settings Command + Output Command
**Setup**:
- Sende Settings Command (Charging Current = 15A)
- 1 Sekunde später: Output Command (USB ON)

**Zu beobachten**:
- [ ] Kommt /client/04 nur für Output Command?
- [ ] Enthält /client/04 auch neuen Settings Value?
- [ ] Wann erscheint Settings Value in /client/data?

**Hypothese**: /client/04 nur mit Output State, Settings erst in /client/data

---

#### Test C3: Mehrere Settings Commands hintereinander
**Setup**:
- Sende Command: Charging Current = 15A
- Sofort danach: Discharge Limit = 30%
- Sofort danach: AC Charging Limit = 80%

**Zu beobachten**:
- [ ] Werden alle Commands verarbeitet?
- [ ] Erscheinen alle Werte im nächsten /client/data?
- [ ] Gibt es Timeouts oder Rate Limits?

**Hypothese**: Alle Commands werden verarbeitet, alle Werte im nächsten /client/data

---

### 🔬 Kategorie D: Extreme Edge Cases

#### Test D1: Command Flood
**Setup**:
- Sende 10 USB ON/OFF Commands in 1 Sekunde

**Zu beobachten**:
- [ ] Wie viele /client/04 Responses?
- [ ] Rate Limiting vom Device?
- [ ] Device State nach Flood?
- [ ] Verarbeitet Device alle oder nur erste/letzte?

**Hypothese**: Device ignoriert redundante Commands, verarbeitet nur State Changes

---

#### Test D2: Gleichzeitige Commands an mehrere Outputs
**Setup**:
- Sende zeitgleich: USB ON, AC ON, DC ON

**Zu beobachten**:
- [ ] Eine /client/04 Response mit allen Changes?
- [ ] Drei separate /client/04 Responses?
- [ ] Timing zwischen Responses?
- [ ] Finaler State korrekt?

**Hypothese**: Eine /client/04 mit allen State Changes im Register 41 Bitfield

---

#### Test D3: Command während Bridge Restart
**Setup**:
- Bridge läuft normal
- Sende Command via mosquitto_pub
- Sofort danach: pkill bridge
- Beobachte Cloud MQTT direkt

**Zu beobachten**:
- [ ] Wird Command noch gesendet bevor Bridge stirbt?
- [ ] Kommt /client/04 Response trotzdem?
- [ ] Was passiert mit Response wenn Bridge offline?
- [ ] Nach Bridge Restart: State korrekt?

**Hypothese**: Command wird gesendet, Response geht verloren, nächster /client/data restored State

---

## Test Execution Plan

### Phase 1: Race Conditions (Kategorie A)
**Dauer**: ~30 Minuten
**Ziel**: Verstehen der Timing-Interaktionen zwischen Commands und Polling

### Phase 2: Button Presses (Kategorie B)
**Dauer**: ~45 Minuten
**Ziel**: Verifizieren manuelle Änderungen triggern /client/04

### Phase 3: Settings Commands (Kategorie C)
**Dauer**: ~30 Minuten
**Ziel**: Dokumentieren Settings Response Behavior

### Phase 4: Edge Cases (Kategorie D)
**Dauer**: ~30 Minuten
**Ziel**: Grenzen des Systems ausloten

## Test Tools

### MQTT Monitor Terminal 1
```bash
mosquitto_sub -h localhost -t 'fossibot/+/state' -v | \
    while read line; do
        echo "[$(date +%H:%M:%S.%3N)] $line"
    done
```

### MQTT Monitor Terminal 2
```bash
tail -f bridge-debug.log | grep -E "(Register 41|Outputs -|Device state updated|client/04|client/data)" --line-buffered
```

### Command Sender
```bash
# Output Commands
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' -m '{"action":"usb_on"}'
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' -m '{"action":"usb_off"}'
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' -m '{"action":"ac_on"}'
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' -m '{"action":"ac_off"}'
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' -m '{"action":"dc_on"}'
mosquitto_pub -h localhost -t 'fossibot/7C2C67AB5F0E/command' -m '{"action":"dc_off"}'

# Settings Commands (via PHP script)
php test_settings_command.php max_charging 15
php test_settings_command.php discharge_limit 30.0
php test_settings_command.php ac_limit 80.0
```

### Timing Helper
```bash
# Zeige Zeit seit letztem /client/data
watch -n 1 'tail -1 bridge-debug.log | grep "client/data" | while read -r line; do echo "Last: $line"; done'
```

## Test Result Template

Für jeden Test:
```markdown
### Test [ID]: [Name]

**Durchgeführt**: [Datum] [Uhrzeit]
**Device State vorher**: USB:[ON/OFF] AC:[ON/OFF] DC:[ON/OFF] LED:[ON/OFF]

**Aktion**:
1. [Schritt 1]
2. [Schritt 2]

**Beobachtung**:
- [Timestamp] [Event]
- [Timestamp] [Event]

**Topics Empfangen**:
- [Timestamp] /client/04: Register 41 = [value] → USB:[X] AC:[X] DC:[X] LED:[X]
- [Timestamp] /client/data: Register 41 = [value] → USB:[X] AC:[X] DC:[X] LED:[X]

**Device State nachher**: USB:[ON/OFF] AC:[ON/OFF] DC:[ON/OFF] LED:[ON/OFF]

**Ergebnis**: ✅ / ❌ / ⚠️

**Erkenntnisse**:
- [Learning 1]
- [Learning 2]
```

## Nächste Schritte

1. **Aktuellen Device State dokumentieren** (Baseline für Tests)
2. **Test A1 durchführen** (einfachster Fall)
3. **Erkenntnisse in SYSTEM.md übertragen**
4. **Nächsten Test basierend auf Learnings auswählen**
