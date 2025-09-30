# Fossibot MQTT Bridge - Implementation Guide

**Status:** Architecture Redesign - ReactPHP + Multi-Account
**Datum:** 30. September 2025
**Version:** 2.0

---

## 📚 Documentation Structure

This implementation guide is split into modular documents for better readability and LLM processing:

### Core Documentation

- **[00-OVERVIEW.md](docs/daemon/00-OVERVIEW.md)**
  Problem statement, solution overview, and technology stack decisions

- **[01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md)**
  Detailed architecture: ReactPHP event loop, multi-account design, component structure

- **[02-TOPICS-MESSAGES.md](docs/daemon/02-TOPICS-MESSAGES.md)**
  Complete MQTT topic structure and message formats (Cloud ↔ Broker)

### Implementation Phases

- **[03-PHASE-0-SETUP.md](docs/daemon/03-PHASE-0-SETUP.md)**
  Phase 0: Setup ReactPHP dependencies, config system, cleanup legacy code (~3h)

- **[04-PHASE-1-CLIENT.md](docs/daemon/04-PHASE-1-CLIENT.md)**
  Phase 1: Build AsyncCloudClient with Pawl + php-mqtt/client (~5h)

- **[05-PHASE-2-BRIDGE.md](docs/daemon/05-PHASE-2-BRIDGE.md)**
  Phase 2: Implement MqttBridge with multi-account support (~6h)

- **[06-PHASE-3-RECONNECT.md](docs/daemon/06-PHASE-3-RECONNECT.md)**
  Phase 3: Smart reconnect logic, error handling, exponential backoff (~4h)

- **[07-PHASE-4-CLI.md](docs/daemon/07-PHASE-4-CLI.md)**
  Phase 4: CLI entry point, signal handling, systemd integration (~3h)

- **[08-PHASE-5-DOCS.md](docs/daemon/08-PHASE-5-DOCS.md)**
  Phase 5: Documentation, examples, deployment guide (~2h)

### Testing & Quality

- **[09-TESTING.md](docs/daemon/09-TESTING.md)**
  Testing strategy: E2E tests, unit tests, no mocking approach

---

## 🎯 Quick Start

1. **Understand the problem:** Read [00-OVERVIEW.md](docs/daemon/00-OVERVIEW.md)
2. **Learn the architecture:** Read [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md)
3. **Start implementing:** Follow phases 0-5 in order
4. **Test continuously:** Apply strategy from [09-TESTING.md](docs/daemon/09-TESTING.md)

---

## 📊 Project Stats

| Metric | Value |
|--------|-------|
| **Total Effort** | ~23-25 hours |
| **Phases** | 6 (0-5) |
| **New Components** | 3 (AsyncCloudClient, MqttBridge, CLI) |
| **Kept Components** | 6 (Connection, Commands, Device, State) |
| **Removed Components** | 6 (Queue, Facade, Executor, etc.) |

---

## 🔄 Migration Path

If you have an existing implementation based on the old synchronous architecture:

1. **Read [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md)** to understand architectural changes
2. **Follow [03-PHASE-0-SETUP.md](docs/daemon/03-PHASE-0-SETUP.md)** to remove legacy code
3. **Implement phases 1-5** with new async architecture
4. **Existing components stay:** Connection, Commands, Device/DeviceState classes remain unchanged

---

## 📝 Key Decisions

| Decision | Choice | Document |
|----------|--------|----------|
| **Event Loop** | ReactPHP | [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md) |
| **Multi-Account** | Single daemon, multiple cloud clients | [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md) |
| **WebSocket Transport** | Ratchet/Pawl | [04-PHASE-1-CLIENT.md](docs/daemon/04-PHASE-1-CLIENT.md) |
| **MQTT Protocol** | php-mqtt/client (Cloud + Broker) | [04-PHASE-1-CLIENT.md](docs/daemon/04-PHASE-1-CLIENT.md) |
| **Process Management** | systemd (no custom daemonization) | [07-PHASE-4-CLI.md](docs/daemon/07-PHASE-4-CLI.md) |
| **Testing** | E2E + Unit Tests, no mocking | [09-TESTING.md](docs/daemon/09-TESTING.md) |

---

## 🚀 Implementation Order

```
Phase 0: Setup & Cleanup
  └─> Phase 1: AsyncCloudClient
       └─> Phase 2: MqttBridge (Multi-Account)
            └─> Phase 3: Reconnect & Error Handling
                 └─> Phase 4: CLI & systemd
                      └─> Phase 5: Documentation
```

Each phase builds on the previous one. **Do not skip phases.**

---

## 📖 Reading Guide

**For New Developers:**
1. Start with [00-OVERVIEW.md](docs/daemon/00-OVERVIEW.md) (context)
2. Read [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md) (big picture)
3. Follow implementation phases 0-5 sequentially

**For Code Reviewers:**
1. [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md) - Architectural decisions
2. [09-TESTING.md](docs/daemon/09-TESTING.md) - Test coverage strategy
3. Phase documents for implementation details

**For DevOps/Deployment:**
1. [07-PHASE-4-CLI.md](docs/daemon/07-PHASE-4-CLI.md) - systemd setup
2. [08-PHASE-5-DOCS.md](docs/daemon/08-PHASE-5-DOCS.md) - Deployment guide
3. [02-TOPICS-MESSAGES.md](docs/daemon/02-TOPICS-MESSAGES.md) - MQTT integration

---

## ⚠️ Important Notes

- **Architecture Change:** This is a fundamental redesign from synchronous to asynchronous
- **No Backward Compatibility:** Old Queue/Facade classes are removed
- **ReactPHP Required:** Event loop is core to the new architecture
- **systemd Recommended:** No custom daemonization, use process manager
- **Test After Every Step:** Follow testing strategy strictly

---

## 🆘 Support

If you get stuck:
1. Check the relevant phase document for detailed instructions
2. Review [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md) for architectural clarity
3. Consult [09-TESTING.md](docs/daemon/09-TESTING.md) for debugging strategies
4. Create an issue with reference to the specific phase/step

---

**Ready to start?** → [00-OVERVIEW.md](docs/daemon/00-OVERVIEW.md)