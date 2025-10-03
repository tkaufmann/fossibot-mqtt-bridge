# Onboarding Guide - Fossibot MQTT Bridge

**Purpose**: Complete context restoration after memory loss
**Time to read**: ~15 Minuten
**Last Updated**: 2025-10-03

---

## 🎯 Schnellstart (60 Sekunden)

**Projekt**: Fossibot MQTT Bridge - Verbindet Fossibot Cloud mit lokalem MQTT Broker

**Aktueller Status**:
- ✅ Bridge funktioniert (AsyncMqttClient Migration abgeschlossen)
- ✅ Device State Management komplett
- ✅ Command System funktioniert (USB/AC/DC/LED + Settings)
- 📋 Nächstes Ziel: Production Deployment (7 Phasen dokumentiert)

**Deine Rolle**: Senior PHP Developer, implementiert Production Deployment

---

## 📚 Pflichtlektüre (in dieser Reihenfolge)

### 1. Project Standards & Architecture (5min)

**Datei**: `CLAUDE.md`

**Was du wissen musst**:
- PHP 8.4 Standards (typed params, 1TBS)
- Stage-basierte Authentication (S1/S2/S3/S4)
- ✅ Command System - ABGESCHLOSSEN
- ✅ DeviceFacade - ABGESCHLOSSEN
- ✅ Settings Commands - ABGESCHLOSSEN
- ✅ Device State Management - ABGESCHLOSSEN
- 📋 Cache-Optimierung - IN PLANUNG

**Kritische Info**:
```
Token TTLs:
- S1 (Anonymous): 10 Minuten
- S2 (Login): ~14 Jahre
- S3 (MQTT): ~3 Tage

→ Cache soll serverseitige TTLs nutzen (keine hardcoded values)
```

---

### 2. API Reference (5min)

**Datei**: `SYSTEM.md`

**Was du wissen musst**:
- API Endpoint: `https://api.next.bspapp.com/client`
- 3-Stage Auth: Anonymous → Login → MQTT Token
- MQTT WebSocket: `mqtt.sydpower.com:8083/mqtt`
- Request Signing: HMAC-MD5 mit CLIENT_SECRET
- MQTT Topics: `{mac}/device/response/client/04` vs `/client/data`

**Nur überfliegen, Details bei Bedarf nachschlagen**

---

### 3. Deployment Plan Overview (3min)

**Datei**: `docs/deployment/00_OVERVIEW.md`

**Was du wissen musst**:
- 7 Phasen: Cache → Health → PID → Control → Install → systemd → Docs
- Aktuell: Development Setup (~/Code/fossibot-php2)
- Ziel: Production Setup (/opt/fossibot-bridge, systemd service)
- Empfohlene Reihenfolge: Phase 3 (PID) als Quick Win

**Kritische Architektur-Entscheidung**:
```
❌ FALSCH: Cache in src/Connection.php (Legacy!)
✅ RICHTIG: Cache in src/Bridge/AsyncCloudClient.php
```

---

### 4. Cache Edge Cases (2min - nur Zusammenfassung)

**Datei**: `docs/deployment/CACHE_EDGE_CASES.md`

**Was du wissen musst**:
- Edge Case 1: Token Expiry während Runtime → handleDisconnect() muss isAuthenticated() prüfen
- Edge Case 2: App-Login invalidiert Tokens → Force Re-Auth bei MQTT Auth Failure
- Edge Case 3: Stale Cache beim Start → TTL-Check mit Safety Margin (300s)

**Nur Übersicht lesen, Details in Phase 1 wenn relevant**

---

## 🗺️ Codebase-Orientierung (bei Bedarf)

### Wichtigste Dateien

| Datei | Zweck | Wann lesen? |
|-------|-------|-------------|
| `daemon/fossibot-bridge.php` | CLI Entry Point | Bei Deployment-Tasks |
| `src/Bridge/MqttBridge.php` | Main Bridge Logic | Bei Integration-Tasks |
| `src/Bridge/AsyncCloudClient.php` | Cloud Connection | **Bei Cache-Implementation** |
| `src/Device/DeviceState.php` | Device State Model | Bei State-Management-Tasks |
| `config/example.json` | Config Template | Bei Config-Changes |

### Architektur-Übersicht

```
Bridge Startup Flow:
1. daemon/fossibot-bridge.php
   ↓
2. MqttBridge::__construct()
   ↓
3. AsyncCloudClient::connect() (pro Account)
   ↓
4. authenticate() → S1, S2, S3
   ↓
5. discoverDevices()
   ↓
6. connectMqtt()
   ↓
7. subscribeToDevices()
```

**Nur bei Debugging oder Architektur-Fragen vertiefen!**

---

## 🎯 Typische Aufgaben & Workflow

### Task 1: "Implementiere Phase X"

```bash
# 1. Lies Phasen-Dokument
cat docs/deployment/0X_PHASE_*.md

# 2. Befolge Steps sequentiell
#    - Jeder Step hat: File, Lines, Code, Test, Commit

# 3. Teste nach jedem Step
#    - Steht im Dokument unter "Test:"

# 4. Commit nach erfolgreichem Test
#    - Commit Message steht im Dokument

# 5. Erst dann: Nächster Step
```

**Du musst KEINE anderen Dateien lesen** - alles steht im Phasen-Dokument!

---

### Task 2: "Debugge Problem X"

```bash
# 1. Lies CLAUDE.md → Finde relevante Section
#    Beispiel: "Device Status Parser" bei State-Problemen

# 2. Lies betroffene Datei
#    Line-Numbers stehen oft in CLAUDE.md
#    Beispiel: "DeviceState.php Lines 99-102"

# 3. Lies SYSTEM.md bei API-Fragen
#    Beispiel: "Was ist Register 41?"

# 4. Lies TEST-RESULTS.md bei Hardware-Fragen
#    Beispiel: "Wie verhalten sich Commands?"
```

---

### Task 3: "Was ist der aktuelle Stand?"

```bash
# 1. Lies CLAUDE.md → Section "Todos"
#    → Zeigt ✅ Abgeschlossen vs ⏸️ Pending

# 2. git log --oneline -20
#    → Zeigt letzte Commits

# 3. git status
#    → Zeigt Work-in-Progress

# 4. docs/deployment/00_OVERVIEW.md
#    → Zeigt Deployment-Plan-Status
```

---

## 🚨 Kritische Wissens-Nuggets

### 1. AsyncCloudClient vs. Connection

```php
// ❌ FALSCH - Legacy!
Connection.php // Alte synchrone Klasse

// ✅ RICHTIG - Aktuell!
AsyncCloudClient.php // Neue async Klasse (ReactPHP)
```

**Bridge verwendet NUR AsyncCloudClient!**

---

### 2. MQTT Topics

```php
// Immediate Response (Live-Daten)
"{mac}/device/response/client/04"
- Register 41: Live Output States ✅
- Settings: Leer/veraltet ❌

// Polling Response (30s Intervall)
"{mac}/device/response/client/data"
- Register 41: Gecached/veraltet ❌
- Settings: Aktuell ✅

→ Topic Priority System: /client/04 hat Vorrang für 35 Sekunden
```

---

### 3. Register 41 Bit-Masken

```php
// ❌ FALSCH - Single Bits
$this->usbOutput = ($bitfield & (1 << 7)) !== 0;

// ✅ RICHTIG - Bit-Masken
$this->usbOutput = ($bitfield & 640) !== 0;  // 0x280, Bits 7+9
$this->dcOutput = ($bitfield & 1152) !== 0;  // 0x480, Bits 7+10
$this->acOutput = ($bitfield & 2052) !== 0;  // 0x804, Bits 2+11
$this->ledOutput = ($bitfield & 4096) !== 0; // 0x1000, Bit 12

→ USB und DC teilen Bit 7!
```

---

### 4. Token Safety Margin

```php
// Token läuft in 4 Minuten ab
$expiresAt = time() + 240;

// ❌ FALSCH - Zu knapp!
if ($expiresAt <= time()) { /* expired */ }

// ✅ RICHTIG - Safety Margin
if ($expiresAt <= (time() + 300)) { /* treat as expired */ }

→ 5 Minuten Safety Margin verhindert Race Conditions
```

---

## 📋 Vollständige Datei-Übersicht

### Must-Read (immer)
1. ✅ `CLAUDE.md` - Standards & Architecture
2. ✅ `docs/deployment/00_OVERVIEW.md` - Deployment Overview
3. ✅ Spezifisches Phasen-Dokument (z.B. `01_PHASE_CACHE.md`)

### Reference (bei Bedarf)
4. 📖 `SYSTEM.md` - API Details
5. 📖 `docs/deployment/CACHE_EDGE_CASES.md` - Cache Design
6. 📖 `TEST-RESULTS.md` - Hardware Test Results
7. 📖 Source Files (nur wenn in Task referenziert)

### Ignore (veraltet/irrelevant)
- ❌ `docs/deployment/DEPLOYMENT_PLAN_OLD.md` - Veraltet
- ❌ `.env` - Nur für Test-Scripts (Bridge nutzt config.json)

---

## 🎓 Beispiel-Briefing

**User sagt**: "Implementiere Token Cache"

**Dein Workflow**:

```bash
# 1. Context laden (5min)
cat CLAUDE.md           # Standards
cat docs/deployment/00_OVERVIEW.md  # Übersicht
cat docs/deployment/01_PHASE_CACHE.md  # Detailplan

# 2. Implementieren
# Befolge Steps in 01_PHASE_CACHE.md sequentiell
# Jeder Step = read, implement, test, commit

# 3. Bei Unsicherheiten
cat docs/deployment/CACHE_EDGE_CASES.md  # Design-Fragen
grep -n "authenticate" src/Bridge/AsyncCloudClient.php  # Code-Fragen
```

**Ergebnis**: Du hast 100% Context in 5 Minuten!

---

## ✅ Onboarding-Checkliste

Nach dem Lesen solltest du wissen:

- [ ] Projekt-Zweck: Fossibot Cloud → Local MQTT Bridge
- [ ] Aktueller Stand: Bridge funktioniert, Deployment in Planung
- [ ] Architektur: AsyncCloudClient (nicht Connection!)
- [ ] Token TTLs: 10min / ~14 Jahre / ~3 Tage
- [ ] MQTT Topics: /client/04 (live) vs /client/data (cached)
- [ ] Register 41: Bit-Masken (nicht Single-Bits)
- [ ] Deployment-Phasen: 7 Phasen, Start mit Phase 3 (PID)
- [ ] Workflow: Lies Phasen-Dokument → Befolge Steps → Test → Commit

**Falls Nein bei irgendeinem Punkt**: Lies entsprechende Section nochmal!

---

## 🚀 Quick Commands

```bash
# Zeige alle Deployment-Docs
ls docs/deployment/*.md

# Zeige aktuellen Git-Status
git log --oneline -10 && git status

# Starte Bridge (Development)
./start-debug-bridge.sh

# Teste Config
./daemon/fossibot-bridge.php --config config/config.json --validate

# Zeige laufende Background-Prozesse
ps aux | grep fossibot
```

---

## 📞 Wenn du stuck bist

1. **Lies CLAUDE.md** → Oft steht die Antwort in "Todos" oder fertig implementierten Sections
2. **Lies Phasen-Dokument** → Hat Troubleshooting-Section am Ende
3. **Grep Source Code** → `grep -rn "ClassName" src/`
4. **Frage User** → "Ich habe X gelesen, bin unsicher bei Y"

---

**Ready!** Du kannst jetzt mit 3-5 Dateien (~2000 Zeilen) komplett onboarden. 🎉

---

## 🧪 Self-Test

**Frage 1**: Wo wird Token Cache integriert?
<details>
<summary>Antwort</summary>

✅ `src/Bridge/AsyncCloudClient.php` (Lines 468, 511)
❌ Nicht in `src/Connection.php` (Legacy!)
</details>

**Frage 2**: Welches Topic hat Live Register 41 Daten?
<details>
<summary>Antwort</summary>

`{mac}/device/response/client/04` (Immediate Response)
</details>

**Frage 3**: Wie lang ist MQTT Token gültig?
<details>
<summary>Antwort</summary>

~3 Tage (~259200 Sekunden)
</details>

**Frage 4**: Welche Phase hat höchste Priorität?
<details>
<summary>Antwort</summary>

Phase 3 (PID File) - P0, Quick Win, 30min
</details>

**Alle richtig?** → Du bist onboarded! 🚀
