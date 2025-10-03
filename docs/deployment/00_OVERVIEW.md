# Deployment Plan Overview

**Status**: ✅ Ready for Implementation
**Target**: Ubuntu 24.04 LTS Production Deployment
**Last Updated**: 2025-10-03 01:55 CEST

---

## 📋 Gesamtübersicht

Transformation von Development Setup → Production-Ready systemd Service

### Aktueller Stand (Development)

```
~/Code/fossibot-php2/
├── daemon/fossibot-bridge.php
├── src/
├── config/config.json (mit Credentials)
├── bridge-debug.log
└── start-debug-bridge.sh

Start: ./start-debug-bridge.sh
User: tim (login user)
```

### Ziel (Production)

```
/opt/fossibot-bridge/          # Application
/etc/fossibot/config.json      # Configuration
/var/log/fossibot/bridge.log   # Logs
/var/lib/fossibot/             # Cache

Start: systemctl start fossibot-bridge
User: fossibot (system user, no shell)
```

---

## 🎯 Implementierungsphasen

| Phase | Files | Priority | Effort | Beschreibung |
|-------|-------|----------|--------|--------------|
| **Phase 1** | Cache Classes | P1 | 2h | Token/Device Cache Persistence |
| **Phase 2** | Health Check | P1 | 1h | HTTP Health Endpoint |
| **Phase 3** | PID Management | P0 | 30min | Process ID File Handling |
| **Phase 4** | Control Script | P0 | 1h | CLI Wrapper für systemctl |
| **Phase 5** | Installation | P0 | 2h | install.sh, uninstall.sh, upgrade.sh |
| **Phase 6** | systemd Service | P0 | 30min | Enhanced Unit File |
| **Phase 7** | Documentation | P0 | 1h | User/Admin Docs |

**Total**: ~8-10 Stunden

---

## 📂 Dokumentationsstruktur

```
docs/deployment/
├── 00_OVERVIEW.md          # Diese Datei
├── 01_PHASE_CACHE.md       # Phase 1: Cache Implementation
├── 02_PHASE_HEALTH.md      # Phase 2: Health Check
├── 03_PHASE_PID.md         # Phase 3: PID File
├── 04_PHASE_CONTROL.md     # Phase 4: Control Script
├── 05_PHASE_INSTALL.md     # Phase 5: Installation Scripts
├── 06_PHASE_SYSTEMD.md     # Phase 6: systemd Enhancement
├── 07_PHASE_DOCS.md        # Phase 7: Documentation
└── CACHE_EDGE_CASES.md     # Cache Design Document
```

---

## 🔍 Kritische Architektur-Entscheidungen

### 1. Cache Integration Point

❌ **FALSCH**: Integration in `src/Connection.php` (alte synchrone Klasse)
✅ **RICHTIG**: Integration in `src/Bridge/AsyncCloudClient.php`

**Grund**: Bridge verwendet nur AsyncCloudClient, Connection.php ist Legacy!

**Betroffene Methoden**:
- `AsyncCloudClient::authenticate()` - Line 468
- `AsyncCloudClient::discoverDevices()` - Line 511

### 2. Token TTL & Safety Margin

| Token | TTL | Safety Margin | Cache? |
|-------|-----|---------------|--------|
| S1 (Anonymous) | 10min | 60s | ✅ Ja (Quick Restart) |
| S2 (Login) | ~14 Jahre | 300s | ✅ Ja |
| S3 (MQTT) | ~3 Tage | 300s | ✅ Ja |

**Safety Margin Rationale**: Token wird 5min VOR Ablauf als "expired" behandelt → verhindert Race Conditions

### 3. Device Cache Strategy

- **TTL**: 24 Stunden (Devices ändern sich selten)
- **Refresh**: Periodic Timer (86400s) in MqttBridge
- **Manual**: MQTT Command `{"action":"refresh_devices"}`

### 4. Filesystem Hierarchy Standard (FHS)

| Type | Development | Production |
|------|-------------|------------|
| Application | `~/Code/fossibot-php2/` | `/opt/fossibot-bridge/` |
| Config | `./config/config.json` | `/etc/fossibot/config.json` |
| Logs | `./bridge-debug.log` | `/var/log/fossibot/bridge.log` |
| Cache | none | `/var/lib/fossibot/` |
| PID | `./bridge.pid` | `/var/run/fossibot/bridge.pid` |
| Control | `./start-debug-bridge.sh` | `fossibot-bridge-ctl` |

---

## 🚨 Kritische Edge Cases (siehe CACHE_EDGE_CASES.md)

### Edge Case 1: Token Expiry während Runtime
**Problem**: Bridge läuft 4+ Tage → MQTT Token (~3 Tage) läuft ab
**Lösung**: `handleDisconnect()` prüft `isAuthenticated()` → Cache-Invalidierung + Re-Auth

### Edge Case 2: App-Login invalidiert Tokens
**Problem**: User loggt sich mit App ein → Bridge-Tokens ungültig
**Lösung**: MQTT Auth-Failure Detection → Force Re-Auth

### Edge Case 3: Stale Cache beim Start
**Problem**: Cache enthält 1 Woche alte Tokens
**Lösung**: TTL-Check mit Safety Margin beim Cache-Read

---

## ✅ Prerequisites

### Development Machine
- PHP 8.2+
- Composer
- Git

### Target Production System
- Ubuntu 24.04 LTS
- PHP 8.2+
- Composer
- Mosquitto
- jq (für Control Script)
- systemd

---

## 🎯 Implementierungsstrategie

### Prinzipien
1. **Atomic Steps**: Jeder Commit ist ein funktionierender Zustand
2. **Test-Driven**: Jeder Schritt hat einen Test
3. **Incremental**: Alte API bleibt parallel funktionsfähig
4. **Documented**: Jede Phase hat vollständige Anleitung

### Workflow pro Phase
```bash
# 1. Lese Phasen-Dokument
cat docs/deployment/01_PHASE_CACHE.md

# 2. Implementiere Step-by-Step
# 3. Teste jeden Step einzeln
# 4. Commit nach erfolgreichem Test
# 5. Erst dann: Nächster Step
```

### Git Commit Convention
```
feat(cache): add TokenCache with file persistence
test(cache): add unit tests for TokenCache
refactor(async): integrate TokenCache in AsyncCloudClient
fix(cache): handle corrupt JSON gracefully
docs(deployment): add Phase 1 implementation guide
```

---

## 📊 Progress Tracking

| Phase | Status | Files Created | Tests | Commits |
|-------|--------|---------------|-------|---------|
| Phase 1 | ⏸️ Pending | 0/4 | 0/3 | 0 |
| Phase 2 | ⏸️ Pending | 0/2 | 0/1 | 0 |
| Phase 3 | ⏸️ Pending | 0/1 | 0/1 | 0 |
| Phase 4 | ⏸️ Pending | 0/1 | 0/1 | 0 |
| Phase 5 | ⏸️ Pending | 0/3 | 0/3 | 0 |
| Phase 6 | ⏸️ Pending | 0/1 | 0/1 | 0 |
| Phase 7 | ⏸️ Pending | 0/4 | 0/0 | 0 |

---

## 🔗 Verwandte Dokumente

- `CLAUDE.md` - Projekt-Standards & Architektur
- `SYSTEM.md` - API Referenz
- `CACHE_EDGE_CASES.md` - Cache Design Details
- `TEST-RESULTS.md` - Hardware Test Results

---

## 🚀 Quick Start

```bash
# Start mit Phase 1
cat docs/deployment/01_PHASE_CACHE.md

# Oder: Direkt zu spezifischer Phase springen
cat docs/deployment/03_PHASE_PID.md  # Schneller Win: PID File (30min)
```

---

**Ready**: Alle Phasen-Dokumente erstellt, jedes Phase-Dokument ist self-contained und direkt umsetzbar.
