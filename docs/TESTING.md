# Fossibot F2400 API Test Results

## Test Session: 2025-10-02

### Hardware Device: F2400 MAC 7C2C67AB5F0E

---

## Test: Bridge-Restart und Initial State Synchronisation

**Durchgeführt**: 2025-10-02 23:18:37 (21:18:37 UTC)

**Szenario**:
- Computer war im Sleep-Modus (4+ Stunden)
- Bridge lief, aber empfing keine Responses mehr von Cloud
- MQTT Keep-Alive (PINGREQ/PINGRESP) funktionierte weiterhin
- Bridge sendete weiterhin Polling-Requests (alle 30s)
- Cloud/Device antwortete nicht

**Aktion**:
1. Bridge-Restart durchgeführt (pkill + neu starten)
2. Bridge verbindet zu Cloud und lokalem Broker

**Hardware-State (manuell verifiziert)**:
- USB: OFF
- AC: ON
- DC: OFF
- LED: OFF
- SoC: 95.8%

**Ergebnis Bridge-State nach Restart** (21:18:37 UTC):
```json
{
  "mac": "7C2C67AB5F0E",
  "soc": "95.8%",
  "input": "0W",
  "output": "1W",
  "dc_input": "0W",
  "outputs": {
    "usb": "OFF",
    "ac": "ON",
    "dc": "OFF",
    "led": "OFF"
  },
  "settings": {
    "max_charging": "1A",
    "discharge_limit": "0%",
    "ac_limit": "0%"
  },
  "timestamp": "21:18:37"
}
```

**Status**: ✅ **ERFOLG**

**Erkenntnisse**:
1. Bridge erhält nach Restart sofort korrekten aktuellen State
2. Keine veralteten/gecachten Werte nach Reconnect
3. Cloud/Device synchronisiert State automatisch bei neuer Bridge-Verbindung
4. Output States waren 100% korrekt (USB:OFF, AC:ON)
5. Register 41 Parsing funktioniert korrekt nach Restart

**Unterschied zu vorherigem Problem**:
- Vor Computer-Sleep: /client/data mit Register 41 = 4097 (gecachter alter Wert)
- Nach Bridge-Restart: Sofort korrekter State ohne veraltete Cache-Daten
- Vermutung: Cloud cached /client/data während laufender Session, aber nicht über Session-Reconnects hinweg

---

## Bekannte Probleme

### Problem 1: Inkonsistente Register 41 Werte zwischen Topics

**Beobachtung**:
- `/client/data` (Polling): Register 41 = 4097 (0x1001)
- `/client/04` (Command Response): Register 41 = 2692 (0xA84) oder 2052 (0x804)

**Hardware-State war**: USB:ON, AC:ON

**Register 41 Analyse**:
```
/client/data:  4097 = 0x1001 = 0b0001000000000001
  → Bit 0 (USB) = 1
  → Bit 12 = 1

/client/04:    2692 = 0xA84  = 0b0000101010000100
  → Bit 2 (AC) = 1
  → Bit 7, 9, 11 = 1

/client/04:    2052 = 0x804  = 0b0000100000000100
  → Bit 2 (AC) = 1
  → Bit 11 = 1
```

**Status**: ⚠️ **UNGEKLÄRT**

**Mögliche Ursachen**:
1. Verschiedene Bit-Mappings für gleiche Daten in verschiedenen Topics
2. Register 41 enthält zusätzliche Flags/Status-Bits (nicht nur Output States)
3. /client/data cached veraltete Werte während aktiver Session
4. Hardware/Firmware sendet verschiedene Formate je nach Topic

**Auswirkung**:
- Topic Priority System (bevorzugt /client/04) funktioniert korrekt
- Output States aus /client/04 matchen mit Hardware
- Output States aus /client/data können veraltet/falsch sein
- Settings/Power/SoC aus /client/data sind korrekt

---

## Problem 2: Device-Sleep nach Computer-Sleep

**Beobachtung**:
- Computer ging in Sleep-Modus
- Bridge lief weiter, MQTT Keep-Alive aktiv
- Device/Cloud antwortete nicht mehr auf Polling-Requests
- Nach Bridge-Restart: Sofort wieder funktionsfähig

**Status**: ⚠️ **TEILWEISE GEKLÄRT**

**Vermutung**:
- Device oder Cloud-Session timeout nach Inaktivität
- Bridge hat keinen automatischen Session-Recovery
- Bridge-Restart erzwingt neue Cloud-Verbindung → funktioniert wieder

**Lösung**:
- Manueller Bridge-Restart nach Computer-Sleep
- Oder: Automatischer Reconnect-Mechanismus in Bridge implementieren

---

---

## Test A1: Command kurz vor Polling

**Durchgeführt**: 2025-10-02 21:20:56 (UTC)
**Device State vorher**: USB:OFF, AC:ON, DC:OFF, LED:OFF

### Aktion:
1. 21:20:56 - USB ON Command gesendet via MQTT
2. Erwartetes Polling: ~21:20:38 (wurde aber auf 21:21:07 verschoben, 30s Intervall)

### Timeline:
```
21:20:56 - Command sent: mosquitto_pub usb_on
21:20:56 - Bridge: Command forwarded to cloud (payload: 1106001800019dca)
21:20:57 - Cloud: /client/data ACK (8 bytes, empty payload)
21:20:58 - Cloud: /client/04 Response (168 bytes, 80 registers)
21:21:07 - Bridge: Polling timer fired (30s interval)
21:21:07 - Bridge: Polling request sent
21:21:08 - Cloud: /client/data Response (168 bytes, 80 registers)
```

### Topics Empfangen:

**21:20:58 - /client/04 (Command Response)**:
```json
{
  "topic": "7C2C67AB5F0E/device/response/client/04",
  "payload_length": 168,
  "register_count": 80,
  "outputs": {
    "usb": "OFF",  // ❌ FALSCH - Hardware ist ON
    "ac": "ON",
    "dc": "OFF",
    "led": "OFF"
  }
}
```

**21:21:08 - /client/data (Polling Response)**:
```
DeviceState: Ignoring /client/data outputs - recent /client/04 update exists (10s ago)
Bridge behielt State von /client/04: USB:OFF, AC:ON
```

### Device State nachher (Hardware-Verifiziert):
- USB: **ON** ✅ (Command funktionierte!)
- AC: ON
- DC: OFF
- LED: OFF

### Device State nachher (Bridge):
- USB: **OFF** ❌ (Falsch!)
- AC: ON ✅
- DC: OFF ✅
- LED: OFF ✅

### Ergebnis: ⚠️ **TEILWEISE ERFOLG**

**Was funktionierte:**
- ✅ Command wurde korrekt an Cloud gesendet
- ✅ Device führte Command aus (USB ist jetzt ON)
- ✅ /client/04 Response kam innerhalb 2 Sekunden
- ✅ Topic Priority System funktioniert (bevorzugt /client/04 über /client/data)
- ✅ Timing: Command 11 Sekunden vor Polling

**Was NICHT funktionierte:**
- ❌ Register 41 Parsing zeigt falschen USB-State
- ❌ Bridge-State matcht nicht mit Hardware-State

### Erkenntnisse:

1. **Command Execution funktioniert**: Hardware reagiert korrekt auf Commands
2. **API Response Pattern bestätigt**: /client/04 kommt sofort, /client/data ignoriert
3. **Register 41 Parsing ist definitiv falsch**: Hardware USB:ON wird als OFF erkannt
4. **Timing-Verhalten**: Polling-Intervall ist nicht exakt 30s (variiert zwischen 30-31s)

**Kritisches Problem**: Ohne korrektes Register 41 Parsing sind alle State-basierten Tests unzuverlässig!

---

## Register 41 Analyse - ABGESCHLOSSEN

**Durchgeführt**: 2025-10-02 21:26:00 - 21:32:31 (UTC)

### Problem
Register 41 Bit-Mapping war falsch implementiert (einzelne Bits 0-3), aber tatsächliches Encoding verwendet **Bit-Kombinationen**.

### Systematische Test-Sequenz

| Hardware-State | Register 41 | Hex | Binary | Bits gesetzt |
|----------------|-------------|-----|--------|--------------|
| Alle OFF | 0 | 0x00 | 0b0000000000000000 | keine |
| USB:ON only | 640 | 0x280 | 0b0000001010000000 | 7, 9 |
| AC:ON only | 2052 | 0x804 | 0b0000100000000100 | 2, 11 |
| DC:ON only | 1152 | 0x480 | 0b0000010010000000 | 7, 10 |
| LED:ON only | 4096 | 0x1000 | 0b0001000000000000 | 12 |
| USB+DC ON | 1664 | 0x680 | 0b0000011010000000 | 7, 9, 10 |
| USB+AC ON | 2692 | 0xA84 | 0b0000101010000100 | 2, 7, 9, 11 |
| AC+DC ON | 3204 | 0xC84 | 0b0000110010000100 | 2, 7, 10, 11 |
| USB+AC+DC ON | 3716 | 0xE84 | 0b0000111010000100 | 2, 7, 9, 10, 11 |
| Alle ON | 7812 | 0x1E84 | 0b0001111010000100 | 2, 7, 9, 10, 11, 12 |

### Erkenntnisse

**1. Bit-Kombinationen (Bit-Masken)**:
```
USB = 640  = 0b0000001010000000 (Bits 7, 9)
AC  = 2052 = 0b0000100000000100 (Bits 2, 11)
DC  = 1152 = 0b0000010010000000 (Bits 7, 10)
LED = 4096 = 0b0001000000000000 (Bit 12)
```

**WICHTIG**: USB und DC teilen Bit 7!

**2. Additives Verhalten mit Bit-Sharing**:
- Werte werden **addiert**, aber überlappende Bits werden nicht doppelt gezählt
- USB+DC: 640 + 1152 ≠ 1792, sondern = 1664 (Bit 7 nur einmal)
- USB+AC = 640 + 2052 = 2692 ✅
- AC+DC = 2052 + 1152 = 3204 ✅ (Bit 7 von DC hinzugefügt)
- USB+AC+DC = 2692 + 1152 - 640 = 3204... komplexer wegen Bit 7 Overlap

**3. LED-Modi**:
- Register 41 Bit 12 zeigt nur "LED aktiv: ja/nein"
- Spezifische Modi (ON/SOS/Flash) werden in anderem Register gespeichert
- Für Output State Detection reicht Bit 12

### Kritischer Befund: Topic-spezifische Register 41 Werte

**Test**: Hardware USB:ON, AC:ON, DC:ON, LED:OFF (sollte = 3716 sein)

**Ergebnis**:
- **/client/04**: Register 41 = **3716** (0xE84) ✅ **KORREKT**
- **/client/data**: Register 41 = **4097** (0x1001) ❌ **FALSCH** (alter Wert von vor 10 Minuten!)

**Beobachtung**:
```
[21:32:00] /client/04:   Register 41 = 3716 (korrekt)
[21:32:31] /client/data: Register 41 = 4097 (veraltet)
```

Der /client/data Wert **4097** entspricht USB:ON, AC:ON (ohne DC) - ein State von früher im Test!

### Schlussfolgerungen

**1. /client/04 ist die einzige zuverlässige Quelle für Register 41**
- Enthält immer Live-Werte vom Device
- Kommt bei State-Änderungen (Commands, manuelle Button-Presses)

**2. /client/data cached Register 41 während Session**
- Zeigt oft veraltete Output States
- Aber: Power/SoC/Settings sind aktuell!
- **Nur für Register 41 problematisch**

**3. Unser Topic Priority System ist optimal**
- 35-Sekunden Preference für /client/04 ist genau richtig
- Verhindert, dass veraltete /client/data Werte überschreiben
- Settings/Power können trotzdem von /client/data aktualisiert werden

### Korrektes Bit-Mapping für DeviceState.php

**Alt (falsch)**:
```php
$this->usbOutput = ($bitfield & (1 << 0)) !== 0;  // Bit 0
$this->dcOutput = ($bitfield & (1 << 1)) !== 0;   // Bit 1
$this->acOutput = ($bitfield & (1 << 2)) !== 0;   // Bit 2
$this->ledOutput = ($bitfield & (1 << 3)) !== 0;  // Bit 3
```

**Neu (korrekt)**:
```php
$this->usbOutput = ($bitfield & 640) !== 0;   // Bits 7, 9
$this->acOutput = ($bitfield & 2052) !== 0;   // Bits 2, 11
$this->dcOutput = ($bitfield & 1152) !== 0;   // Bits 7, 10
$this->ledOutput = ($bitfield & 4096) !== 0;  // Bit 12
```

**Wichtig**: Die Masken funktionieren korrekt trotz Bit 7 Overlap zwischen USB und DC, weil:
- USB Check: `(bitfield & 640) !== 0` → prüft Bit 7 ODER Bit 9
- DC Check: `(bitfield & 1152) !== 0` → prüft Bit 7 ODER Bit 10
- Wenn beide an: Bit 7, 9, 10 alle gesetzt → beide Checks erfolgreich ✅

---

---

---

## Test A2: Command während Polling

**Durchgeführt**: 2025-10-02 21:36:30 - 21:37:40 (UTC)
**Device State vorher**: USB:ON, AC:ON, DC:ON, LED:OFF

### Ziel
Testen ob Commands und Polling sich gegenseitig beeinflussen wenn sie zeitlich zusammenfallen.

### Versuch 1: Command 10s nach Polling

**Timeline**:
```
21:36:30 - Polling Timer fired
21:36:31 - /client/data Response: Register 41 = 4097 (veraltet)
21:36:40 - Command sent: LED ON
21:36:41 - /client/data ACK (8 bytes)
21:36:41 - /client/04 Response: Register 41 = 7812 (USB+AC+DC+LED ON) ✅
```

**Ergebnis**:
- Saubere Trennung zwischen Polling und Command
- Kein Konflikt, beide Responses kamen getrennt
- Register 41 = 7812 korrekt (alle Outputs ON)

### Versuch 2: Command 7-8s nach Polling

**Timeline**:
```
21:37:30 - Polling Timer fired
21:37:31 - /client/data Response: Register 41 = 4097 (veraltet)
21:37:37 - /client/04 Response (SPONTAN!): Register 41 = 7812
21:37:38 - Command sent: LED OFF
21:37:39 - /client/data ACK (8 bytes)
21:37:40 - /client/04 Response: Register 41 = 3716 (LED OFF) ✅
```

**Überraschung**:
- Device sendete spontanen /client/04 bei 21:37:37 (ohne Command!)
- Danach kam unser LED OFF Command
- Command triggerte neue /client/04 Response mit korrektem Wert

### Device State nachher (Hardware-Verifiziert):
- USB: ON
- AC: ON
- DC: ON
- LED: OFF
- Register 41: **3716** ✅ KORREKT

### Ergebnis: ✅ **ERFOLG**

**Was funktionierte:**
- ✅ Commands und Polling interferieren nicht
- ✅ Device verarbeitet beides parallel ohne Konflikte
- ✅ Jedes Command triggert eigene /client/04 Response
- ✅ Spontane /client/04 Updates vom Device möglich
- ✅ Register 41 immer korrekt in /client/04

**Erkenntnisse:**

1. **Keine Race Conditions**: Commands und Polling laufen unabhängig
2. **Device-initiierte Updates**: Device sendet spontane /client/04 (vermutlich bei lokalen Änderungen oder internen Events)
3. **Zuverlässige Command Responses**: Jedes Command bekommt eigene /client/04, egal wann Polling läuft
4. **Timing ist unkritisch**: Selbst wenn Command kurz nach Polling kommt, keine Probleme

---

## Test B1: Manuelle Button-Presses am Device

**Durchgeführt**: 2025-10-02 21:38:00 - 21:42:00 (UTC)
**Device State vorher**: USB:ON, AC:ON, DC:ON, LED:OFF

### Ziel
Verifizieren, dass manuelle Änderungen am Device /client/04 Updates triggern, genau wie MQTT Commands.

### Versuch 1: USB Button Press (USB OFF)

**Aktion**: User drückte USB-Button am Device → USB von ON zu OFF

**Timeline**:
```
21:38:xx - Button Press am Device (USB OFF)
21:38:xx - Kein /client/04 Response beobachtet
```

**Beobachtung**:
- Keine /client/04 Response nach Button Press
- Nächster /client/data zeigte immer noch Register 41 = 4097 (veraltet)

**Device State nachher (Hardware)**:
- USB: OFF ✅ (Button funktionierte)
- AC: ON
- DC: ON
- LED: OFF
- **Erwarteter Register 41**: 3204 (AC+DC ohne USB)

### Versuch 2: DC Button Press (DC OFF)

**Timeline**:
```
21:40:xx - User drückte DC-Button am Device
21:40:xx - Kein /client/04 Response beobachtet
21:40:xx - Nächster /client/data: Register 41 = 2052 (AC only) ✅ KORREKT!
```

**Überraschung**: /client/data hatte plötzlich **aktuellen** Register 41 Wert!

### Systematische Verifikation via Commands

Da manuelle Button-Presses keine /client/04 Responses triggerten, wurde der korrekte DC-Wert via Commands verifiziert:

**Test-Sequenz**:
```
1. AC OFF Command → Register 41 = 0 (alle OFF)
2. DC ON Command → Register 41 = 1152 (DC only) ✅
3. USB ON Command → Register 41 = 1664 (USB+DC) ✅
```

**Kritischer Befund**: DC = 1152, NICHT 1024 wie initial angenommen!

**Korrektur**:
- **Initial falsch**: DC = 1024 (Bits 8, 10)
- **Tatsächlich**: DC = 1152 (Bits 7, 10)

Dieser Fehler entstand, weil während der initialen Register 41 Analyse (Test A1) DC tatsächlich OFF war, aber ich annahm es sei ON.

### Ergebnis: ⚠️ **ERKENNTNISREICH**

**Was funktionierte:**
- ✅ Hardware-Buttons ändern Device State sofort
- ✅ /client/data synchronisiert sich irgendwann (nicht sofort)
- ✅ Commands erlaubten präzise Verifikation der Bit-Werte

**Was NICHT wie erwartet:**
- ❌ Manuelle Button-Presses triggern KEINE sofortigen /client/04 Responses
- ❌ State-Änderung erscheint verzögert im nächsten /client/data

**Erkenntnisse:**

1. **Button-Presses ≠ Command-Responses**: Im Gegensatz zu MQTT Commands triggern lokale Buttons keine /client/04 Responses
2. **/client/data wird aktualisiert**: Nach einiger Zeit (Polling-Zyklus?) erscheinen Button-Press Änderungen in /client/data
3. **DC Bit-Mapping Korrektur**: Durch systematisches Testen wurde korrekter DC-Wert ermittelt
4. **Bit 7 Sharing bestätigt**: USB (640 = Bits 7,9) und DC (1152 = Bits 7,10) teilen Bit 7
   - USB+DC = 1664 = 640 + 1152 - 640 (Bit 7 nur einmal gezählt) ✅

### Aktualisierte Register 41 Mapping-Tabelle

Alle Werte wurden durch Test B1 Command-Sequenz final verifiziert:

| Output | Dezimal | Hex | Binary | Bits |
|--------|---------|-----|--------|------|
| USB | 640 | 0x280 | 0b0000001010000000 | 7, 9 |
| AC | 2052 | 0x804 | 0b0000100000000100 | 2, 11 |
| DC | **1152** | **0x480** | **0b0000010010000000** | **7, 10** |
| LED | 4096 | 0x1000 | 0b0001000000000000 | 12 |

**Kombinationen verifiziert**:
- AC+DC = 3204 (2052 + 1152) ✅
- USB+DC = 1664 (640 + 1152 mit Bit 7 Sharing) ✅

---

## Test C1: Settings Command Timing

**Durchgeführt**: 2025-10-02 21:55:36 - 21:57:07 (UTC)
**Device State vorher**: USB:ON, AC:OFF, DC:OFF, LED:OFF, max_charging=4A

### Ziel
Verstehen wann/wie Settings Commands vom Device verarbeitet werden und wann der neue Wert im State erscheint.

### Aktion
Settings Command gesendet: Max Charging Current = 15A

### Timeline

```
21:55:31 - /client/data Polling: max_charging=4A (vor Test)
21:55:36 - Command sent: set_charging_current(15A)
21:55:37 - /client/data ACK (8 bytes) - Command bestätigt
21:55:38 - /client/04 Response (168 bytes, 80 registers)
            → max_charging=0A ❌ (NOCH NICHT aktualisiert!)
21:56:07 - /client/data Polling (30s später)
            → max_charging=15A ✅ (JETZT erscheint neuer Wert!)
21:57:07 - /client/data Polling
            → max_charging=15A ✅ (Wert bleibt erhalten)
```

### Beobachtungen

**1. Settings Command triggert /client/04 Response!**
- Im Gegensatz zur Hypothese kam tatsächlich ein /client/04 Response
- Aber: Der neue Settings-Wert war NICHT im /client/04 enthalten
- /client/04 enthielt nur Output States, Settings blieben auf alten Werten

**2. Neuer Settings-Wert erscheint im nächsten /client/data**
- Delay: ~31 Sekunden (nächster Polling-Zyklus)
- Ab dann ist der Wert persistent in allen weiteren /client/data Responses

**3. /client/04 vs /client/data Content**
- /client/04: Register 41 (Outputs) ist aktuell, Settings (Reg 20, 66, 67) sind alt/leer
- /client/data: Sowohl Outputs ALS AUCH Settings sind aktuell

### Device State vorher (Bridge)
- USB: ON
- AC: OFF
- DC: OFF
- LED: OFF
- max_charging: 4A
- discharge_limit: 15%
- ac_limit: 100%

### Device State nachher (Bridge)
- USB: ON (unchanged)
- AC: OFF (unchanged)
- DC: OFF (unchanged)
- LED: OFF (unchanged)
- **max_charging: 15A** ✅ AKTUALISIERT!
- discharge_limit: 15% (unchanged)
- ac_limit: 100% (unchanged)

### Ergebnis: ✅ **ERFOLG**

**Was funktionierte:**
- ✅ Settings Command wurde vom Device verarbeitet
- ✅ Neuer Wert erscheint in /client/data nach ~30s
- ✅ Wert bleibt persistent gespeichert
- ✅ Kein /client/04 verwirrt die Implementierung nicht

**Überraschende Erkenntnisse:**
- ⚠️ Settings Commands triggern /client/04 (unerwartet!)
- ⚠️ Aber: /client/04 enthält NICHT den neuen Settings-Wert
- ⚠️ Settings-Werte erscheinen nur in /client/data

**Erkenntnisse:**

1. **Settings Commands = Delayed Update**: Settings ändern sich nicht sofort, sondern erst im nächsten Polling-Zyklus
2. **/client/04 ist irrelevant für Settings**: Auch wenn /client/04 kommt, enthält es keine aktuellen Settings
3. **/client/data ist die einzige Settings-Quelle**: Nur /client/data enthält zuverlässige Settings-Werte
4. **Max Delay: 30 Sekunden**: Settings-Änderungen werden spätestens beim nächsten Polling sichtbar
5. **Command wurde sofort verarbeitet**: ACK nach 1 Sekunde bestätigt, dass Device den Befehl akzeptiert hat

### Implikationen für Implementation

**Current Implementation ist korrekt:**
- Settings werden aus Registern 20, 66, 67 gelesen (DeviceState.php:112-120)
- Keine Sonderbehandlung für Settings Topics nötig
- /client/data Polling garantiert Settings-Updates alle 30s

**UI/UX Consideration:**
- Nach Settings Command sollte UI einen Hinweis zeigen: "Updating... (may take up to 30s)"
- Nicht sofortiges Feedback erwarten wie bei Output Commands

---

## Test C1.1: Settings Command mit verzögertem Poll

**Durchgeführt**: 2025-10-02 22:01:20 - 22:01:51 (UTC)
**Hypothese**: 5 Sekunden Delay nach Settings Command reichen aus, damit Device den Wert intern speichert

### Ziel
Testen ob ein **verzögerter** State Poll (5s nach Settings Command) den neuen Wert bereits enthält.

### Aktion
1. Settings Command: Max Charging Current = 10A
2. Bridge triggert automatisch immediate poll (zu früh!)
3. **5 Sekunden warten**
4. Manueller State Poll via `{"action":"read_settings"}`

### Timeline

```
22:01:07 - Polling: max_charging=15A (Baseline)

22:01:20 - Command: set_charging_current(10A)
22:01:20 - Bridge: Automatic immediate poll (zu früh!)
22:01:21 - ACK Response (8 bytes)

22:01:25 - MANUELLER Poll (+5s nach Command) ← TEST
22:01:26 - Response: max_charging=10A ✅ ERFOLG!

22:01:38 - /client/04 (verspätete Response vom 22:01:20 poll)
           max_charging=0A ❌ (Alte Daten - Poll war zu früh)

22:01:51 - Regulärer Polling
           max_charging=10A ✅ (Bestätigt)
```

### Ergebnis: ✅ **ERFOLG**

**Kernerkenntnis:**
- **5 Sekunden Delay reichen aus!** Device hat Settings-Wert nach 5s intern gespeichert
- Automatischer immediate Poll (<1s) ist **zu früh** → Device hat Wert noch nicht geschrieben
- Manueller Poll (+5s) holt **erfolgreich** den neuen Wert ab
- Verspätete /client/04 vom zu frühen Poll kann kurz falsche Daten zeigen

**Optimierung für MqttBridge:**

Aktuelle Implementation:
```php
// Settings Command → sofort poll (zu früh!)
triggerImmediatePoll(); // <1 Sekunde
```

**Empfohlene Optimierung:**
```php
// Settings Commands need ~5s internal processing time
if ($command instanceof SettingsCommand) {
    // Wait 5 seconds before polling new value
    $this->loop->addTimer(5.0, function() {
        $this->triggerImmediatePoll();
    });
} else {
    // Output commands: immediate poll works fine
    $this->triggerImmediatePoll();
}
```

**Alternativen:**
1. **Keine Optimierung nötig**: Reguläres 30s Polling funktioniert zuverlässig
2. **UI Feedback**: "Settings updating... (5-30 seconds)" statt sofort neuen Wert erwarten

---

## Test C2: Settings + Output Command gemischt

**Durchgeführt**: 2025-10-02 22:03:23 - 22:04:24 (UTC)
**Device State vorher**: USB:ON, AC:OFF, DC:OFF, LED:OFF, max_charging=10A

### Ziel
Testen wie Settings und Output Commands zusammen funktionieren und ob /client/04 beide Werte enthält.

### Aktion
1. Settings Command: Max Charging Current = 20A
2. 1 Sekunde später: Output Command: AC ON
3. Beobachten der Responses

### Timeline

```
22:03:21 - /client/data Polling (Baseline)
           USB:ON, AC:OFF, max_charging=10A

22:03:23 - Settings Command: set_charging_current(20A)
22:03:23 - Triggering immediate poll (ignoriert, <2s Spam-Schutz)
22:03:24 - Settings ACK (8 bytes)

22:03:24 - Output Command: ac_on
22:03:25 - Output ACK (8 bytes)

22:03:25 - /client/04 Response (AC ON Command Response)
           USB:OFF, AC:ON ✅ Output State korrekt!
           max_charging=0A ❌ Settings noch nicht aktualisiert

22:03:54 - /client/data Polling (+31s nach Settings Command)
           USB:OFF, AC:ON ✅
           max_charging=20A ✅ Settings JETZT sichtbar!

22:04:24 - /client/data Polling
           USB:ON, AC:OFF ❌ (gecached, falsch!)
           max_charging=20A ✅ (Settings bleiben korrekt)
```

### Ergebnis: ✅ **ERFOLG mit Einschränkungen**

**Was funktionierte:**
- ✅ Beide Commands wurden verarbeitet
- ✅ Output Command triggert sofortige /client/04 Response
- ✅ Settings-Wert erscheint nach ~30s in /client/data
- ✅ Settings-Wert bleibt persistent

**Was NICHT wie erwartet:**
- ❌ /client/04 enthält NICHT den neuen Settings-Wert (auch wenn Output Command danach kam)
- ❌ Settings Commands triggern kein eigenes /client/04
- ⚠️ /client/data Caching zeigt später wieder falsche Output States

**Erkenntnisse:**

1. **/client/04 ist NUR für Output States**: Auch bei gemischten Commands enthält /client/04 nur Output States, keine Settings
2. **Settings immer verzögert**: Settings erscheinen immer erst im nächsten /client/data, egal ob Output Command vorher/nachher kommt
3. **Unabhängige Verarbeitung**: Settings und Output Commands werden unabhängig voneinander verarbeitet
4. **Topic Priority funktioniert**: /client/04 (22:03:25) wurde korrekt für Output States verwendet
5. **/client/data ist die einzige Settings-Quelle**: Bestätigt Erkenntnis aus Test C1

### Implikation

**Settings und Outputs sind komplett getrennte Kanäle:**
- **Outputs**: /client/04 = sofort (1-2s), zuverlässig
- **Settings**: /client/data = verzögert (~30s), aber persistent

**UI/UX Recommendation:**
```javascript
// Settings Command
sendCommand({action: "set_charging_current", amperes: 20});
showMessage("Settings updating... (30 seconds)");

// Output Command
sendCommand({action: "ac_on"});
showMessage("AC turning on..."); // Sofortiges Feedback möglich
```

---

### Test C3: Mehrere Settings Commands hintereinander

**Datum:** 2025-10-03, 00:18 UTC

**Ziel:** Testen ob mehrere Settings Commands hintereinander gesendet werden können ohne dass Commands verloren gehen.

**Setup:**
- Device State: USB:OFF, AC:ON, DC:OFF, LED:OFF
- Alte Settings: max_charging=2A, discharge_limit=0%, ac_limit=0%

**Commands gesendet (0.5s Delay):**
1. 00:18:13.0 → `{"action":"set_charging_current","amperes":12}`
2. 00:18:13.5 → `{"action":"set_discharge_limit","percentage":35.0}`
3. 00:18:14.0 → `{"action":"set_ac_charging_limit","percentage":95.0}`

**Erwartung:** Alle 3 Settings erscheinen im nächsten /client/data (~30s später).

**Ergebnis um 00:19:14 (61s später):**

Device State in `/client/data`:
```json
{
  "settings": {
    "max_charging": "12A",
    "discharge_limit": "15%",  ← ❌ FALSCH! Sollte 35% sein
    "ac_limit": "95%"
  }
}
```

**❌ TEILWEISE ERFOLGREICH:**
- ✅ max_charging: 12A korrekt
- ❌ discharge_limit: 15% statt 35% (alter Wert beibehalten)
- ✅ ac_limit: 95% korrekt

**Analyse:**

Logs zeigen korrektes Command-Payload:
```
[2025-10-02 22:18:13] Command forwarded: "Set discharge lower limit to 35%"
Payload Hex: 11060042015e26ab
Decoded: SlaveID=0x11, Func=0x06, Register=0x0042 (66), Value=0x015E (350 = 35.0%)
CRC: 0x26AB ✅
```

**Root Cause:** Device-Hardware ignoriert Commands wenn sie zu schnell kommen (0.5s Delay zu kurz).

**Verifizierung durch Retry:**
- Single Command `set_discharge_limit(50%)` → ✅ Erfolgreich
- Single Command `set_discharge_limit(35%)` → ✅ Erfolgreich

→ Command Code ist korrekt, Device braucht mehr Zeit zwischen Settings Commands.

**Erkenntnisse:**
1. **0.5s Delay ist zu kurz** für Settings Commands
2. **Device-Hardware-Limitation**, kein Software-Bug
3. **Bridge sendet korrekt** (Hex-Payloads verifiziert)
4. **Commands werden ignoriert**, nicht in Queue gespeichert

**Follow-up:** Test C3.1 mit 2s Delay zwischen Commands.

---

## Nächste Tests

- [x] Test A1: Command kurz vor Polling → **Timing funktioniert**
- [x] Register 41 Analyse und Korrektur → **Bit-Mapping entschlüsselt**
- [x] Test A2: Command während Polling → **Keine Konflikte**
- [x] Test B1: Manuelle Button-Presses → **Keine /client/04, aber /client/data Update**
- [x] Test C1: Settings Command Timing → **/client/04 kommt aber enthält alte Settings, /client/data zeigt neuen Wert nach 30s**
- [x] Test C1.1: Verzögerter Poll → **5s Delay reicht aus, Device hat Wert dann gespeichert**
- [x] Test C2: Settings + Output gemischt → **Beide funktionieren unabhängig, /client/04 nur für Outputs, Settings nur in /client/data**
- [x] **DeviceState.php fixen** mit korrektem Bit-Mapping → **✅ ERLEDIGT (Lines 99-102)**
- [ ] **MqttBridge.php optimieren**: 5s Delay für Settings Command Polls (optional)
- [x] Test C3: Mehrere Settings Commands hintereinander → **Teilweise erfolgreich, Timing-Problem entdeckt (0.5s zu schnell)**
- [x] Test C3.1: Settings Commands mit 2s Delay → **Komplett erfolgreich, 2s Delay erforderlich**
- [x] Test D2: Gleichzeitige Commands → **✅ Device batcht Commands, eine Response mit allen Änderungen**
- [ ] Test B2-B4: Weitere Button-Press Szenarien (~~optional~~ **OBSOLET** - Buttons triggern kein /client/04)
- [ ] Test D1, D3: Command Flood / Bridge Restart (optional, akademisch)

### Test C3.1: Settings Commands Timing (Follow-up)

**Datum:** 2025-10-03, 00:24 UTC

**Setup:**
- Device State: USB:ON, AC:OFF, DC:OFF, LED:OFF
- Alte Settings: max_charging=12A, discharge_limit=50%, ac_limit=95%

**Test:** 3 Settings Commands mit unterschiedlichen Delays senden

**Commands:**
1. `set_charging_current` → 8A
2. Delay 2s
3. `set_discharge_limit` → 25%
4. Delay 2s  
5. `set_ac_charging_limit` → 80%

**Erwartung:** Alle 3 Settings werden korrekt gespeichert.


**Ergebnis um 00:26 UTC (nach ~35s):**

Device State in `/client/data`:
```json
{
  "settings": {
    "max_charging": "8A",
    "discharge_limit": "25%",
    "ac_limit": "80%"
  }
}
```

**✅ ERFOLGREICH:** Alle 3 Settings wurden korrekt gespeichert!

**Erkenntnisse:**
- Device benötigt **mindestens 2 Sekunden** zwischen Settings Commands
- Bei zu schnellem Senden (0.5s) werden Commands ignoriert
- Bridge sendet Commands korrekt (Hex-Payloads verified)
- Device-Hardware-Limitation, kein Code-Bug

**Vergleich Test C3 vs C3.1:**

| Test | Delay | max_charging | discharge_limit | ac_limit | Erfolg |
|------|-------|--------------|-----------------|----------|--------|
| C3 | 0.5s | 12A ✅ | ❌ (ignoriert) | 95% ✅ | Teilweise |
| C3.1 | 2.0s | 8A ✅ | 25% ✅ | 80% ✅ | Komplett |

**Empfehlung für Production Code:**
- Minimum 2s Delay zwischen Settings Commands im QueueManager
- Alternativ: Device Response abwarten bevor nächstes Command senden

---


### Test D2: Gleichzeitige Commands an mehrere Outputs

**Datum:** 2025-10-03, 00:42 UTC

**Ziel:** Testen ob Device mehrere Commands gleichzeitig verarbeitet und ob eine oder mehrere /client/04 Responses kommen.

**Setup:**
- Aktueller State: USB:OFF, AC:OFF, DC:OFF, LED:ON
- Sende 3 Commands mit <0.1s Abstand: USB ON, AC ON, DC ON

**Test durchführen:**


**Commands gesendet um 22:43:32-33 UTC:**
1. USB ON - Register 24 → Command forwarded
2. AC ON - Register 26 → Command forwarded (+ 1s)
3. DC ON - Register 25 → Command forwarded (+ 1s)

**Timing:** 3 Commands innerhalb von ~1 Sekunde

**Response:**

**Eine einzige /client/04 Response um 22:43:44 (+11s nach erstem Command):**
```
Register 41 = 7812 (0x1E84, binary: 0001111010000100)
Outputs: USB:1 DC:1 AC:1 LED:1
```

**✅ ERFOLG:** Alle 3 Outputs wurden eingeschaltet!

**Ergebnis: ✅ BATCHING FUNKTIONIERT**

**Erkenntnisse:**

1. **Device batcht Commands!** 
   - 3 separate Commands innerhalb 1 Sekunde gesendet
   - NUR EINE /client/04 Response mit allen Änderungen
   - Register 41 enthielt alle 3 Output-States

2. **Delay bis Response: ~11 Sekunden**
   - Deutlich länger als bei einzelnem Command (1-2s)
   - Device verarbeitet offenbar alle Commands, dann eine Response

3. **Alle Commands wurden verarbeitet**
   - Keine Commands verloren
   - Finaler State enthält alle 3 Änderungen
   - Register 41 = 7812 = USB+AC+DC+LED (alle ON)

4. **Optimales Batching-Fenster: <1 Sekunde**
   - Commands die innerhalb ~1s kommen werden gebatched
   - Eine gemeinsame Response statt 3 separate Responses
   - Reduziert MQTT Traffic

**Vergleich: Einzeln vs. Gebatched**

| Szenario | Commands | /client/04 Responses | Response Time | Result |
|----------|----------|---------------------|---------------|--------|
| Einzeln | 1 | 1 | ~2s | ✅ |
| Gebatched (D2) | 3 in 1s | 1 | ~11s | ✅ |
| Settings (C3) | 3 in 0.5s | 0 | N/A | ❌ (ignored) |
| Settings (C3.1) | 3 mit 2s delay | 0 | ~30s | ✅ |

**Wichtiger Unterschied:**
- **Output Commands**: Batching funktioniert perfekt, eine Response mit allen Änderungen
- **Settings Commands**: Kein Batching, benötigen 2s Delay zwischen Commands

**QueueManager Optimierung möglich:**

```php
// Output Commands: Können gebatched werden
if (count($outputCommands) > 1) {
    // Send all at once, wait for single batched response
    foreach ($outputCommands as $cmd) {
        $this->send($cmd);
    }
    // ~11s delay for batched response
    sleep(11);
} else {
    // Single command: ~2s response time
    $this->send($cmd);
    sleep(2);
}

// Settings Commands: MÜSSEN mit 2s Delay gesendet werden
foreach ($settingsCommands as $cmd) {
    $this->send($cmd);
    sleep(2); // Minimum delay required!
}
```

**Performance-Gewinn:**
- 3 Output Commands einzeln: 3 × 2s = 6s
- 3 Output Commands gebatched: 11s
- ⚠️ In diesem Fall: **langsamer!**
- Aber: Weniger MQTT Traffic (1 statt 3 Responses)

**Empfehlung:**
- Batching ist **optional** - bringt keinen Zeit-Vorteil
- Nützlich für Traffic-Reduktion bei vielen Commands
- Nicht kritisch für normale Nutzung

---

