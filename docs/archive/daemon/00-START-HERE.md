# 🚀 START HERE - Onboarding für Daemon-Implementierung

**Willkommen!** Dieses Dokument ist dein Einstiegspunkt für die Implementierung des Fossibot MQTT Bridge Daemons.

---

## 📖 Schritt 1: Dokumentation lesen (30 Minuten)

Lies diese Dateien **in dieser Reihenfolge**, bevor du mit der Implementierung beginnst:

1. **`/CLAUDE.md`** (Projekt-Root)
   - Coding Standards (PHP 8.4, Typisierung, 1TBS)
   - Architektur-Übersicht
   - Stage-basierte Authentication
   - Testing-Approach (keine Mocks!)

2. **`/SYSTEM.md`** (Projekt-Root)
   - Komplette API-Referenz
   - MQTT-Protokoll Details
   - Request/Response-Strukturen
   - Modbus-Register-Mappings

3. **`docs/daemon/01-ARCHITECTURE.md`**
   - Daemon-Architektur verstehen
   - Event-Loop Konzept (ReactPHP)
   - Komponenten-Übersicht

4. **`docs/daemon/09-TESTING.md`** (Nur Einleitung!)
   - Testing-Philosophie verstehen
   - "No Mocking" Prinzip
   - Test-Kategorien kennenlernen

---

## 🛠️ Schritt 2: Phasen sequentiell abarbeiten

Arbeite die Implementierung **strikt sequentiell** ab:

### Phase 0: Setup & Cleanup
📄 **`docs/daemon/03-PHASE-0-SETUP.md`**

- Räumt Legacy-Code auf
- Installiert ReactPHP Dependencies
- **WICHTIG**: Step 0.6 richtet Test-Infrastruktur ein!
- Mosquitto Broker Setup

### Phase 1: Async Cloud Client
📄 **`docs/daemon/04-PHASE-1-CLIENT.md`**

- Event-basierter MQTT Client (kein Polling!)
- WebSocket Integration
- Custom MQTT Packet Handling

### Phase 2: MQTT Bridge
📄 **`docs/daemon/05-PHASE-2-BRIDGE.md`**

- Orchestrierung von Cloud ↔ Broker
- Multi-Account Support
- Topic Translation

### Phase 3: Reconnect Logic
📄 **`docs/daemon/06-PHASE-3-RECONNECT.md`**

- Three-Tier Reconnect Strategy
- Token Expiry Handling
- Exponential Backoff

### Phase 4: CLI & systemd
📄 **`docs/daemon/07-PHASE-4-CLI.md`**

- CLI Entry Point
- Config Validation
- systemd Service Unit

### Phase 5: Dokumentation
📄 **`docs/daemon/08-PHASE-5-DOCS.md`**

- User Documentation
- Integration Examples
- Troubleshooting Guide

---

## ✅ Fortschritt markieren

**Wichtig**: Markiere deinen Fortschritt **direkt in den Phase-Dokumenten**!

Jedes Phase-Dokument hat am Ende eine **Completion Checklist**. Beispiel:

```markdown
## ✅ Phase 0 Completion Checklist

- [x] Legacy code deleted (Step 0.1)          ← Haken setzen!
- [x] ReactPHP dependencies installed (Step 0.2)
- [ ] Config system created (Step 0.3)        ← Noch offen
- [ ] Mosquitto installed (Step 0.4)
```

**So markierst du:**
1. Öffne das Phase-Dokument in deinem Editor
2. Ersetze `- [ ]` durch `- [x]` für erledigte Schritte
3. Committe die Änderung: `git commit -m "docs: Mark Phase X Step Y as complete"`

---

## 🧪 Testing während der Implementierung

**NICHT erst am Ende testen!** Schreibe Tests **parallel zur Implementierung**:

- **Nach jedem Step**: Führe die Test-Scripts aus dem Step aus
- **Unit Tests**: Schreibe sie direkt nach der Komponente (siehe `09-TESTING.md`)
- **Integration Tests**: Nach jeder Phase die zugehörigen Tests schreiben
- **Nutze TDD**: Red → Green → Refactor

Die Test-Infrastruktur wird in **Phase 0, Step 0.6** eingerichtet.

---

## 📋 Commit-Strategie

Committe **nach jedem Step** (nicht erst am Ende der Phase):

```bash
# Beispiel nach Phase 1, Step 1.1:
git add src/Bridge/AsyncCloudClient.php
git commit -m "feat(client): Implement AsyncCloudClient with event-based MQTT"

# Beispiel nach Phase 2, Step 2.3:
git add src/Bridge/TopicTranslator.php tests/Unit/TopicTranslatorTest.php
git commit -m "feat(bridge): Add TopicTranslator with unit tests"
```

**Format**: `<type>(<scope>): <description>`
- Types: `feat`, `fix`, `test`, `docs`, `refactor`
- Scope: `client`, `bridge`, `reconnect`, `cli`, `docs`

---

## 🆘 Wenn du nicht weiterkommst

### 1. Dokumentation nochmal lesen
- Oft steht die Lösung im entsprechenden Phase-Dokument
- `SYSTEM.md` für API-Details
- `CLAUDE.md` für Architektur-Fragen

### 2. Tests als Referenz nutzen
- `09-TESTING.md` zeigt, wie Komponenten verwendet werden
- Test-Code ist oft selbsterklärend

### 3. Existierenden Code prüfen
- Schau dir `src/Connection.php` an (Stage-basierte Auth)
- `src/Commands/` für Command-Pattern Beispiele
- `src/Device/` für Value Objects

### 4. Tim fragen
- Wenn du länger als 30 Minuten blockiert bist
- Bei Architektur-Entscheidungen
- Bei unklaren Requirements

---

## 🎯 Erfolgskriterien

**Du bist fertig, wenn:**

1. ✅ Alle 5 Phasen abgeschlossen (Checklisten vollständig)
2. ✅ Alle Tests laufen durch (`./run-tests.sh`)
3. ✅ Daemon startet und verbindet zu Cloud + Broker
4. ✅ Manueller E2E Test mit echtem Device erfolgreich
5. ✅ systemd Service läuft stabil
6. ✅ Alle Commits haben aussagekräftige Messages

---

## 🔥 Wichtigste Prinzipien

1. **Event-basiert, nicht Polling**: Nutze ReactPHP Event Loop richtig
2. **No Mocking in Tests**: Teste gegen echte Fossibot API
3. **Sequentielles Vorgehen**: Nicht vorspringen, Phase für Phase
4. **Frequent Commits**: Nach jedem Step committen
5. **Test Parallel**: Nicht erst am Ende testen

---

**Viel Erfolg! 🚀**

Bei Fragen: Tim fragen. Los geht's mit `03-PHASE-0-SETUP.md`!