# Deployment Documentation

Complete step-by-step deployment guides for transforming Fossibot MQTT Bridge from development setup to production-ready systemd service.

**Created**: 2025-10-03 02:14 CEST
**Total**: 7,087 lines across 7 phase documents
**Target**: Ubuntu 24.04 LTS Production Deployment

---

## 📋 Documentation Overview

| Phase | Document | Lines | Time | Priority | Description |
|-------|----------|-------|------|----------|-------------|
| **Overview** | [00_OVERVIEW.md](00_OVERVIEW.md) | 214 | - | - | Architecture decisions, file structure |
| **Phase 1** | [01_PHASE_CACHE.md](01_PHASE_CACHE.md) | 1,389 | 2h | P1 | Token & Device Cache Implementation |
| **Phase 2** | [02_PHASE_HEALTH.md](02_PHASE_HEALTH.md) | 834 | 1h | P1 | HTTP Health Check Endpoint |
| **Phase 3** | [03_PHASE_PID.md](03_PHASE_PID.md) | 488 | 30min | P0 | PID File Management (Quick Win!) |
| **Phase 4** | [04_PHASE_CONTROL.md](04_PHASE_CONTROL.md) | 808 | 1h | P0 | CLI Control Script |
| **Phase 5** | [05_PHASE_INSTALL.md](05_PHASE_INSTALL.md) | 1,161 | 2h | P0 | Installation Scripts (install/uninstall/upgrade) |
| **Phase 6** | [06_PHASE_SYSTEMD.md](06_PHASE_SYSTEMD.md) | 654 | 30min | P0 | Enhanced systemd Service |
| **Phase 7** | [07_PHASE_DOCS.md](07_PHASE_DOCS.md) | 1,539 | 1h | P0 | User Documentation (INSTALL/UPGRADE/TROUBLESHOOTING) |

**Total Effort**: ~8-10 hours

---

## 🚀 Quick Start

### For Implementers

```bash
# Recommended order (P0 first for immediate value):
1. Phase 3: PID Management         (30min) ✅ Quick Win
2. Phase 4: Control Script         (1h)    ✅ Immediate usability
3. Phase 5: Installation Scripts   (2h)    ✅ Production deployment
4. Phase 6: systemd Enhancement    (30min) ✅ Security hardening
5. Phase 1: Cache System           (2h)    ⚡ Performance boost
6. Phase 2: Health Check           (1h)    📊 Monitoring
7. Phase 7: Documentation          (1h)    📚 User guides
```

### For Users

**Production Installation**:
```bash
# Read deployment docs first
cat docs/deployment/00_OVERVIEW.md

# Then follow user guides
cat docs/INSTALL.md
cat docs/OPERATIONS.md
```

---

## 📂 File Structure

```
docs/deployment/
├── README.md                    ← You are here
├── 00_OVERVIEW.md               ← Start here for architecture
├── 01_PHASE_CACHE.md            ← Token/Device caching
├── 02_PHASE_HEALTH.md           ← HTTP health endpoint
├── 03_PHASE_PID.md              ← Double-start prevention
├── 04_PHASE_CONTROL.md          ← fossibot-bridge-ctl script
├── 05_PHASE_INSTALL.md          ← install.sh, uninstall.sh, upgrade.sh
├── 06_PHASE_SYSTEMD.md          ← Enhanced service file
├── 07_PHASE_DOCS.md             ← User documentation
└── CACHE_EDGE_CASES.md          ← Cache design deep-dive

User Documentation (created by Phase 7):
docs/
├── INSTALL.md                   ← Installation guide
├── UPGRADE.md                   ← Upgrade process
├── OPERATIONS.md                ← Daily operations
└── TROUBLESHOOTING.md           ← Problem solving
```

---

## 🎯 What Each Phase Delivers

### Phase 1: Cache Implementation
**Deliverables**:
- `src/Cache/CachedToken.php` - Value object
- `src/Cache/TokenCache.php` - File-based token cache
- `src/Cache/DeviceCache.php` - Device list cache
- AsyncCloudClient integration
- MqttBridge integration
- Config section for cache

**Benefits**:
- 33-100% reduction in API calls on restart
- Stage 2 (Login) skipped when token cached
- Device discovery cached (24h TTL)

---

### Phase 2: Health Check
**Deliverables**:
- `src/Bridge/BridgeMetrics.php` - Metrics collector
- `src/Bridge/HealthCheckServer.php` - React\\Http server
- HTTP endpoint at `localhost:8080/health`
- MqttBridge integration

**Benefits**:
- Prometheus/Nagios monitoring
- Kubernetes liveness probes
- Load balancer health checks
- `fossibot-bridge-ctl health` command

---

### Phase 3: PID Management
**Deliverables**:
- PID file handling in `daemon/fossibot-bridge.php`
- Double-start prevention
- Stale PID cleanup
- Config option `daemon.pid_file`

**Benefits**:
- Prevents MQTT conflicts from double-start
- Auto-cleanup of crashed processes
- Foundation for control script

---

### Phase 4: Control Script
**Deliverables**:
- `bin/fossibot-bridge-ctl` - Unified CLI tool
- Commands: start, stop, restart, status, logs, validate, health
- Auto-detection: systemd vs. direct mode
- Installation to `/usr/local/bin/`

**Benefits**:
- Single command for all operations
- Works in dev and production
- No need to remember systemctl syntax

---

### Phase 5: Installation Scripts
**Deliverables**:
- `scripts/install.sh` - Complete production setup
- `scripts/uninstall.sh` - Clean removal
- `scripts/upgrade.sh` - In-place update with config diff
- FHS-compliant directory structure

**Benefits**:
- One-command production installation
- Automated user/directory creation
- Safe upgrades with rollback
- Config preservation

---

### Phase 6: systemd Enhancement
**Deliverables**:
- Enhanced `daemon/fossibot-bridge.service`
- RuntimeDirectory/StateDirectory auto-management
- Advanced security hardening
- Resource limits (CPU/Memory/Tasks)

**Benefits**:
- Production-grade security (systemd score ~7.5/10)
- Auto-directory management
- Syscall/Network filtering
- Smart restart policy

---

### Phase 7: Documentation
**Deliverables**:
- `docs/INSTALL.md` - Installation guide
- `docs/UPGRADE.md` - Upgrade process
- `docs/OPERATIONS.md` - Daily operations
- `docs/TROUBLESHOOTING.md` - Problem solving

**Benefits**:
- Self-service for users
- Reduced support burden
- Clear troubleshooting paths
- Copy-paste ready examples

---

## 🔍 Key Architecture Decisions

### Cache Integration Point
❌ **Not** in `src/Connection.php` (legacy sync code)
✅ **Yes** in `src/Bridge/AsyncCloudClient.php` (used by bridge)

**Reason**: Bridge only uses AsyncCloudClient, Connection.php is legacy.

### Token TTL Strategy
| Token | TTL | Cache? | Safety Margin |
|-------|-----|--------|---------------|
| S1 (Anonymous) | 10min | ✅ Yes | 60s |
| S2 (Login) | ~14 years | ✅ Yes | 300s |
| S3 (MQTT) | ~3 days | ✅ Yes | 300s |

**Safety Margin**: Token treated as expired 5min before actual expiry → prevents race conditions.

### Device Cache Strategy
- **TTL**: 24 hours (devices change rarely)
- **Refresh**: Periodic timer every 24h
- **Manual**: MQTT command for immediate refresh
- **Invalidation**: On auth failure

### Directory Hierarchy (FHS-Compliant)
| Type | Development | Production |
|------|-------------|------------|
| Application | `~/Code/fossibot-php2/` | `/opt/fossibot-bridge/` |
| Config | `./config/config.json` | `/etc/fossibot/config.json` |
| Logs | `./bridge-debug.log` | `/var/log/fossibot/bridge.log` |
| Cache | none | `/var/lib/fossibot/` |
| PID | `./bridge.pid` | `/var/run/fossibot/bridge.pid` |

---

## ✅ Document Quality Criteria

Each phase document includes:
- ✅ Self-contained (can be read standalone)
- ✅ Exact file paths (absolute, not relative)
- ✅ Exact line numbers for code changes
- ✅ Complete code blocks (copy-paste ready)
- ✅ Test commands for each step
- ✅ Commit messages
- ✅ "Done when" criteria
- ✅ Time estimates per step
- ✅ Troubleshooting section

---

## 🧪 Testing Strategy

### Per-Phase Testing
Each phase includes:
1. **Unit tests** for new components
2. **Integration tests** for system interaction
3. **End-to-end tests** for full workflow

### Test Scripts Locations
```bash
tests/
├── test_cache_e2e.php              # Phase 1
├── test_health_endpoint.sh         # Phase 2
├── test_pid_double_start.sh        # Phase 3
├── test_pid_stale.sh               # Phase 3
├── test_control_script.sh          # Phase 4
└── test_systemd_service.sh         # Phase 6
```

### Manual Testing
Each phase includes manual verification steps for:
- Development environment testing
- Production deployment validation
- Rollback procedures

---

## 📊 Expected Performance Improvements

### Cache System (Phase 1)
| Scenario | Before | After | Improvement |
|----------|--------|-------|-------------|
| Cold restart | 3 API calls | 1-2 calls | 33-66% |
| Warm restart (<24h) | 3 API calls | 0 calls | 100% |
| Stage 2 (Login) | Always | Cached (~14y) | ~1s saved |
| Device Discovery | Always | Cached (24h) | ~0.5s saved |

**Cache hit rate estimation**:
- Login Token: ~99% (14 year TTL)
- MQTT Token: ~85% (3 day TTL)
- Device List: ~95% (24h TTL)

### systemd Enhancements (Phase 6)
- **Memory**: OOM protection with soft/hard limits
- **CPU**: Quota prevents CPU hogging
- **Security**: Reduced attack surface via syscall filtering
- **Restart**: Smart policy prevents boot loops

---

## 🔒 Security Considerations

### File Permissions
```bash
/etc/fossibot/config.json          # 640 root:fossibot (credentials)
/opt/fossibot-bridge/              # 755 root:root (read-only)
/var/log/fossibot/                 # 755 fossibot:fossibot (writable)
/var/lib/fossibot/                 # 755 fossibot:fossibot (writable)
```

### systemd Hardening
- Filesystem: `ProtectSystem=strict`, `ProtectHome=true`
- Namespacing: `PrivateDevices=true`, `ProtectHostname=true`
- Syscalls: `SystemCallFilter=@system-service`, deny privileged
- Network: IP whitelist (localhost + private networks)

### Credentials Storage
- Config file: 640 permissions (root:fossibot)
- Token cache: 600 permissions (fossibot only)
- No credentials in logs
- No credentials in health endpoint

---

## 🐛 Known Edge Cases

### Edge Case 1: Token Expiry During Runtime
**Problem**: Bridge runs 4+ days → MQTT token expires
**Solution**: `handleDisconnect()` checks `isAuthenticated()` → invalidates cache → re-auth

### Edge Case 2: App Login Invalidates Tokens
**Problem**: User logs in with smartphone → bridge tokens invalidated
**Solution**: MQTT auth-failure detection → force re-auth

### Edge Case 3: Stale Cache on Startup
**Problem**: Bridge offline 1 week → cache contains expired tokens
**Solution**: TTL check with safety margin → treats as cache miss

**Full details**: [CACHE_EDGE_CASES.md](CACHE_EDGE_CASES.md)

---

## 📝 Commit Strategy

### Commit Message Format
```
<type>(<scope>): <subject>

Types: feat, fix, refactor, test, docs, chore
Scopes: cache, health, daemon, bridge, systemd, scripts

Examples:
feat(cache): add TokenCache with TTL-based expiry handling
test(health): add health endpoint integration tests
refactor(async): integrate cache in AsyncCloudClient
docs(deployment): add Phase 1 implementation guide
```

### Atomic Commits
Each step in each phase = one commit
→ Every commit is a working state
→ Easy to revert individual steps

---

## 🎓 Learning Path

### For Developers
1. Read `00_OVERVIEW.md` for architecture
2. Read `CACHE_EDGE_CASES.md` for design rationale
3. Implement phases in order (or P0 first)
4. Run tests after each step
5. Commit after successful tests

### For System Administrators
1. Read `docs/INSTALL.md` for installation
2. Follow installation steps
3. Read `docs/OPERATIONS.md` for daily tasks
4. Bookmark `docs/TROUBLESHOOTING.md` for issues
5. Use `fossibot-bridge-ctl` for all operations

---

## 🚨 Prerequisites Check

### Development Machine
```bash
# Check PHP version
php -v  # Need 8.2+

# Check Composer
composer --version

# Check dependencies
which git jq
```

### Production Server
```bash
# Ubuntu 24.04 LTS
lsb_release -a

# systemd
systemctl --version

# Mosquitto
mosquitto -h

# All dependencies
./scripts/install.sh  # Checks automatically
```

---

## 🔄 Implementation Workflow

### Recommended Sequence

**Option A: Quick Wins First (Fastest Value)**
```
1. Phase 3 (PID)         → 30min  → Prevents double-start
2. Phase 4 (Control)     → 1h     → Unified CLI
3. Phase 6 (systemd)     → 30min  → Production-ready service
4. Phase 5 (Install)     → 2h     → Automated deployment
   --- Deploy to Production (4h total) ---
5. Phase 1 (Cache)       → 2h     → Performance boost
6. Phase 2 (Health)      → 1h     → Monitoring
7. Phase 7 (Docs)        → 1h     → User guides
```

**Option B: Sequential (Logical Order)**
```
1. Phase 1 (Cache)       → 2h
2. Phase 2 (Health)      → 1h
3. Phase 3 (PID)         → 30min
4. Phase 4 (Control)     → 1h
5. Phase 5 (Install)     → 2h
6. Phase 6 (systemd)     → 30min
7. Phase 7 (Docs)        → 1h
   --- Total: 8h ---
```

### Per-Phase Workflow
```bash
# 1. Read phase document
cat docs/deployment/0X_PHASE_*.md

# 2. Implement step-by-step
# 3. Run test after each step
# 4. Commit on successful test
# 5. Next step only if previous passes

# 6. Phase complete → validate entire phase
./tests/test_*.sh  # Phase-specific test
```

---

## 🎯 Success Criteria

### Phase Completion
- ✅ All steps implemented
- ✅ All tests passing
- ✅ All commits made
- ✅ No breaking changes
- ✅ Documentation updated

### Full Deployment
- ✅ All 7 phases completed
- ✅ Production installation successful
- ✅ Service running stable for 24h
- ✅ Health checks passing
- ✅ MQTT communication working
- ✅ User documentation published

---

## 📞 Support & Contributions

### Getting Help
- **GitHub Issues**: https://github.com/youruser/fossibot-php2/issues
- **Documentation**: Read phase docs carefully
- **Troubleshooting**: See TROUBLESHOOTING.md

### Contributing
- Follow coding standards in `CLAUDE.md`
- Add tests for new features
- Update deployment docs if architecture changes
- Use semantic commit messages

---

## 📈 Changelog

| Date | Version | Changes |
|------|---------|---------|
| 2025-10-03 | 1.0 | Initial deployment documentation created |
|  |  | All 7 phase documents completed (7,087 lines) |
|  |  | User documentation structure defined |

---

## 🏁 Next Steps

**For Implementers**:
1. Start with Phase 3 (PID) - 30min quick win
2. Continue with Phase 4 (Control) - immediately useful
3. Deploy to production after Phase 5 (Install)
4. Add performance/monitoring with Phase 1+2
5. Complete with Phase 7 (Docs) for users

**For Users**:
1. Wait for implementation completion
2. Read `docs/INSTALL.md`
3. Follow installation steps
4. Use `fossibot-bridge-ctl` for operations
5. Reference `docs/TROUBLESHOOTING.md` if needed

---

**Ready to start?** → Begin with [00_OVERVIEW.md](00_OVERVIEW.md)
