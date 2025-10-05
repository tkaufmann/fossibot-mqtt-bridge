# Fossibot MQTT Bridge Documentation

Complete documentation for the Fossibot MQTT Bridge project.

---

## 📚 For Users & Administrators

Production deployment and operations guides.

- **[INSTALL.md](INSTALL.md)** - Installation Guide
  - System requirements & dependencies
  - Automated & manual installation
  - Configuration & service setup
  - Home Assistant & monitoring integration

- **[UPGRADE.md](UPGRADE.md)** - Upgrade Guide
  - Pre-upgrade checklist
  - Automated upgrade process
  - Config merging & rollback procedures

- **[OPERATIONS.md](OPERATIONS.md)** - Daily Operations
  - Service management (start/stop/restart)
  - Monitoring & resource usage
  - Maintenance tasks (logs, cache, updates)
  - Backup & restore procedures

- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** - Problem Solving
  - Common issues & solutions
  - Debug logging
  - Emergency recovery

---

## 🔧 For Developers

System architecture, API reference, and development guides.

- **[ARCHITECTURE.md](ARCHITECTURE.md)** - System Design & API Reference
  - Complete Fossibot Cloud API documentation
  - MQTT protocol & message formats
  - Authentication flow (4-stage)
  - Device control commands (Modbus/CRC-16)

- **[TESTING.md](TESTING.md)** - Hardware Validation Log
  - Real hardware test results (F2400 device)
  - Register 41 bit-mapping analysis
  - Command timing & response patterns
  - Topic priority system validation

---

## 🚀 Deployment

Implementation phases for production deployment.

See [deployment/README.md](deployment/README.md) for details.

**Implemented Phases:**
- ✅ Phase 4: Control Script (`fossibot-bridge-ctl`)
- ✅ Phase 5: Installation Scripts (install/uninstall/upgrade)
- ✅ Phase 6: systemd Service Enhancement (security hardening)
- ✅ Phase 7: User Documentation

**Pending Phases:**
- ⏳ Phase 1: Cache System (tokens & device list)
- ⏳ Phase 2: Health Check Server (HTTP monitoring endpoint)
- ⏳ Phase 3: PID File Management (prevent duplicate instances)

---

## 📦 Quick Links

- **Root**: [../README.md](../README.md) - Project overview
- **Root**: [../ONBOARDING.md](../ONBOARDING.md) - Developer onboarding
- **Root**: [../CLAUDE.md](../CLAUDE.md) - AI assistant context
- **Root**: [../GEMINI.md](../GEMINI.md) - Gemini AI context

---

## 📁 Archive

Historical documentation and old implementation plans: [archive/](archive/)

- Old daemon implementation phases
- Migration notes (MQTT refactoring)
- Research & planning documents
- Deprecated guides (QUICKSTART, TODO)
