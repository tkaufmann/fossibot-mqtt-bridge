# Response Behavior Research

## Aktueller Forschungsstand

### Bekannte Response-Verhalten (aus SYSTEM.md)

#### 1. Output Commands (USB/AC/DC/LED)
- **Commands**: Register 24-27 (USB/DC/AC/LED Output)
- **Expected Response Topic**: `{mac}/device/response/client/04`
- **Response Timing**: Sofort nach Command
- **Response Content**: Register 41 bitfield mit neuen Output States
- **Status**: ✅ VERIFIZIERT durch unsere Tests

#### 2. Settings Commands
- **Commands**: Register 20 (Max Charging), Register 57 (AC Silent), Register 59/60 (Standby Times)
- **Expected Response Topic**: `{mac}/device/response/client/data`
- **Response Timing**: NUR in periodic updates (~30s) oder expliziten Read Commands
- **Response Content**: Unbekannt - noch nicht getestet
- **Status**: ❓ UNGETESTET

### Ungeklärte Fragen

#### READ Commands
- **Command**: ReadRegistersCommand (0x03, start=0, count=80)
- **Expected Response Topic**: ❓ Vermutung: `client/data`
- **Response Timing**: ❓ Sofort oder delayed?
- **Response Content**: ❓ Alle 80 Register?
- **Status**: ❓ UNGETESTET (senden wir, aber hören nicht auf responses)

### Architektur-Entscheidungen (offen)

#### Option A: Command-spezifische Response-Types
```php
enum CommandResponseType {
    case CLIENT_04;     // Output commands -> client/04
    case CLIENT_DATA;   // Settings/Read -> client/data
    case NO_RESPONSE;   // Fire-and-forget
}
```

#### Option B: Connection-wide Response Listening
```php
// QueueManager/Connection hört auf ALLE response topics:
// - {mac}/device/response/client/04
// - {mac}/device/response/client/data
// Commands spezifizieren keine response expectations
```

#### Option C: Connection-level Response Handling
```php
// Connection class übernimmt response handling
// QueueManager delegiert nur commands
// Responses werden von Connection verarbeitet
```

### Nächste Schritte

1. **Settings Commands implementieren** (Register 20, 57, 59, 60)
2. **Test-Framework für Response-Monitoring**
3. **Systematische Tests**:
   - Settings Command → Welcher Topic? Timing?
   - Read Command → Welcher Topic? Content?
   - Output Command → Bestätigung der bisherigen Erkenntnisse
4. **Basierend auf Test-Ergebnissen**: Architektur-Entscheidung treffen

### Test-Plan

#### Settings Commands Test
```php
// Send Max Charging Current Command
$deviceFacade->setMaxChargingCurrent(15); // 15A

// Monitor both topics for 60 seconds:
// - client/04 (should be empty?)
// - client/data (should show update in ~30s?)

// Send explicit read afterwards:
$deviceFacade->readSettings();
// - Erscheint neuer Wert sofort in client/data?
```

#### Read Commands Test
```php
// Send read command while monitoring:
$deviceFacade->readSettings();

// Check:
// - Welcher topic bekommt response?
// - Wie schnell kommt response?
// - Welche Register werden zurückgegeben?
```

### Ziel
Empirische Basis für finale Response-Architektur schaffen, statt auf Vermutungen zu bauen.