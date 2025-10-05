# Production Deployment Plan - Fossibot MQTT Bridge

**Status**: ✅ Senior Review Approved (Oktober 2025)
**Estimated Effort**: 8-10 Stunden
**Target Platform**: Ubuntu 24.04 LTS

---

## 📋 Übersicht

Professionelles Produktiv-Deployment auf Ubuntu Linux mit systemd, FHS-konformer Installation und vollständiger Lifecycle-Verwaltung.

---

## 🏗️ Architektur-Entscheidungen

### Filesystem Hierarchy Standard (FHS) Konformität

```
/opt/fossibot-bridge/           # Application binaries & code
├── daemon/fossibot-bridge.php  # Main daemon
├── src/                        # Source code
├── vendor/                     # Composer dependencies
└── composer.{json,lock}        # Dependency management

/etc/fossibot/                  # Configuration (root-only write)
└── config.json                 # Main configuration

/var/log/fossibot/             # Log files (fossibot user writable)
└── bridge.log                 # Rotating logs (7 days retention)

/var/lib/fossibot/             # State & cache (NEU!)
├── token-cache/               # Token cache persistence
│   └── {email-hash}.json      # Per-account token cache
└── device-cache/              # Device list cache
    └── {email-hash}.json      # Per-account device cache

/usr/local/bin/                # CLI tools (NEU!)
└── fossibot-bridge-ctl        # Control script (start/stop/status/validate)
```

### Service-Architektur

- **User/Group**: Dedizierter `fossibot` System-User (kein Login, keine Shell)
- **Systemd Service**: Type=simple, auto-restart, resource limits
- **Security Hardening**: NoNewPrivileges, PrivateTmp, ProtectSystem=strict
- **Dependencies**: After network + mosquitto, Wants mosquitto

### Konfigurationsmanagement

**Bestehende Konfiguration**:
```json
{
  "accounts": [...],
  "mosquitto": {...},
  "daemon": {...},
  "bridge": {...},
  "debug": {...}
}
```

**NEUE Ergänzungen** (für Production):
```json
{
  "cache": {
    "enabled": true,
    "directory": "/var/lib/fossibot/token-cache",
    "token_ttl_safety_margin": 300,
    "device_list_ttl": 3600
  },
  "daemon": {
    "log_file": "/var/log/fossibot/bridge.log",
    "log_level": "info",
    "pid_file": "/var/run/fossibot/bridge.pid"
  },
  "monitoring": {
    "health_check_enabled": true,
    "health_check_port": 8080,
    "metrics_enabled": false
  }
}
```

---

## 📦 Komponenten-Übersicht

### 1. Installation Scripts

| Script | Zweck | Ausführung |
|--------|-------|------------|
| `install.sh` | Haupt-Installationsskript | `sudo ./install.sh` |
| `uninstall.sh` | Vollständige Deinstallation | `sudo ./uninstall.sh` |
| `upgrade.sh` | In-Place Upgrade (ohne Config-Touch) | `sudo ./upgrade.sh` |

### 2. Service Management

| Tool | Zweck |
|------|-------|
| `fossibot-bridge.service` | systemd Unit File |
| `fossibot-bridge-ctl` | CLI Control Script (Wrapper für systemctl + Extras) |

---

## 🛠️ Detaillierter Implementierungsplan

### Phase 1: Cache Infrastructure (1-2h) - P1

**Files**:
- `src/Cache/TokenCache.php` - Token Persistence
- `src/Cache/DeviceCache.php` - Device List Persistence
- `src/Cache/CachedToken.php` - Value Object für gecachte Tokens

**Integration**:
- ✅ **KORREKTUR**: `src/Bridge/AsyncCloudClient.php` - Cache-Check in `authenticate()` und `discoverDevices()`
  - ❌ ~~NICHT in `src/Connection.php`~~ (alte synchrone Klasse, wird von Bridge nicht verwendet!)

**Cache-Struktur** (`/var/lib/fossibot/token-cache/tim@example.de.json`):
```json
{
  "s1_anonymous": {
    "token": "eyJhbGc...",
    "expires_at": 1728012345,
    "cached_at": 1728011745
  },
  "s2_login": {
    "token": "eyJhbGc...",
    "expires_at": 2073560992,
    "cached_at": 1728011750
  },
  "s3_mqtt": {
    "token": "eyJhbGc...",
    "expires_at": 1728273550,
    "cached_at": 1728011755
  }
}
```

**AsyncCloudClient Integration**:
```php
// In AsyncCloudClient::authenticate()
private function authenticate(): PromiseInterface
{
    // 1. Check Token Cache
    $cached = $this->tokenCache->getCachedTokens($this->email);

    // 2. Determine which stages to skip
    $needsS1 = !$cached['s1_anonymous'] || !$this->tokenCache->isValid($cached['s1_anonymous']);
    $needsS2 = !$cached['s2_login'] || !$this->tokenCache->isValid($cached['s2_login']);
    $needsS3 = !$cached['s3_mqtt'] || !$this->tokenCache->isValid($cached['s3_mqtt']);

    // 3. Build promise chain (skip stages if cached)
    $promise = \React\Promise\resolve(null);

    if ($needsS1) {
        $promise = $promise->then(fn() => $this->s1_getAnonymousToken());
    } else {
        $this->anonymousToken = $cached['s1_anonymous']->token;
    }

    // ... analog für S2 und S3

    return $promise;
}
```

**Device Cache Integration**:
```php
// In AsyncCloudClient::discoverDevices()
private function discoverDevices(): PromiseInterface
{
    // Check cache first
    $cached = $this->deviceCache->getDevices($this->email);
    if ($cached && $this->deviceCache->isValid($this->email)) {
        $this->devices = $cached;
        return \React\Promise\resolve(null);
    }

    // Cache miss -> fetch from API
    return $this->fetchDevicesFromApi()
        ->then(function($devices) {
            $this->devices = $devices;
            $this->deviceCache->saveDevices($this->email, $devices);
            return null;
        });
}
```

**Testing**:
```bash
# Token Cache Test
php tests/manual/test_token_cache.php

# Device Cache Test
php tests/manual/test_device_cache.php
```

---

### Phase 2: Health Check Server (1h) - P1

**Files**:
- `src/Bridge/HealthCheckServer.php` - HTTP Server mit React\Http
- `src/Bridge/BridgeMetrics.php` - Metrics Collection

**Integration**:
- `MqttBridge::run()` - Start HealthCheck Server parallel zum Event Loop

**Health Response**:
```json
{
  "status": "ok",
  "uptime": 3600,
  "accounts": {
    "total": 1,
    "connected": 1,
    "failed": 0
  },
  "devices": {
    "total": 2,
    "online": 2,
    "offline": 0
  },
  "mqtt": {
    "cloud_connected": true,
    "broker_connected": true,
    "messages_sent": 1234,
    "messages_received": 5678
  }
}
```

**Testing**:
```bash
curl http://localhost:8080/health | jq
curl http://localhost:8080/metrics  # optional
```

---

### Phase 3: PID File Management (30min) - P0

**Changes**:
- `daemon/fossibot-bridge.php` - PID write/check/cleanup
- `config/example.json` - `daemon.pid_file` hinzufügen

**Implementation**:
```php
// In daemon/fossibot-bridge.php (nach Config-Load)
$pidFile = $config['daemon']['pid_file'] ?? '/var/run/fossibot/bridge.pid';
$pidDir = dirname($pidFile);

if (!is_dir($pidDir)) {
    mkdir($pidDir, 0755, true);
}

// Check if already running
if (file_exists($pidFile)) {
    $pid = (int)trim(file_get_contents($pidFile));
    if (posix_kill($pid, 0)) {  // Process exists
        die("ERROR: Bridge already running (PID: $pid)\n");
    }
    unlink($pidFile);  // Stale PID file
}

// Write PID
file_put_contents($pidFile, getmypid());

// Cleanup on shutdown
register_shutdown_function(function() use ($pidFile) {
    @unlink($pidFile);
});
```

**Testing**:
```bash
./daemon/fossibot-bridge.php -c config/config.json
# In anderem Terminal:
./daemon/fossibot-bridge.php -c config/config.json  # Sollte fehlschlagen
```

---

### Phase 4: Control Script (1h) - P0

**File**: `bin/fossibot-bridge-ctl`

**Commands**:
```bash
fossibot-bridge-ctl start      # systemctl start + Validierung
fossibot-bridge-ctl stop       # systemctl stop
fossibot-bridge-ctl restart    # systemctl restart
fossibot-bridge-ctl status     # systemctl status + Health Check
fossibot-bridge-ctl logs       # journalctl -u fossibot-bridge -f
fossibot-bridge-ctl validate   # Config validation ohne Start
fossibot-bridge-ctl health     # curl health endpoint
fossibot-bridge-ctl metrics    # curl metrics endpoint (optional)
```

**Implementation**:
```bash
#!/bin/bash
# bin/fossibot-bridge-ctl

SERVICE_NAME="fossibot-bridge"
HEALTH_URL="http://localhost:8080/health"

case "$1" in
  start)
    echo "Validating config..."
    /usr/bin/php /opt/fossibot-bridge/daemon/fossibot-bridge.php \
      --config /etc/fossibot/config.json --validate || exit 1

    echo "Starting service..."
    systemctl start $SERVICE_NAME
    sleep 2

    echo "Checking health..."
    curl -sf $HEALTH_URL | jq . || echo "WARNING: Health check failed"
    ;;

  status)
    systemctl status $SERVICE_NAME --no-pager
    echo -e "\nHealth Status:"
    curl -sf $HEALTH_URL | jq . 2>/dev/null || echo "Not available"
    ;;

  # ... weitere commands
esac
```

---

### Phase 5: Installation Scripts (2h) - P0

#### A. `install.sh` - Vollinstallation

**Features**:
- Dependency Check (PHP 8.2+, composer, mosquitto, **jq**)
- User/Group Creation
- Directory Structure Setup
- File Copy mit Version-Check
- Config-Template mit interaktivem Setup
- systemd Service Installation
- Post-Install Validation

**Dependency Check**:
```bash
# Check dependencies
echo "Checking dependencies..."
for cmd in php composer mosquitto jq; do
    if ! command -v $cmd &> /dev/null; then
        echo "❌ $cmd not found"
        MISSING_DEPS="$MISSING_DEPS $cmd"
    fi
done

if [ -n "$MISSING_DEPS" ]; then
    echo "Install missing dependencies:"
    echo "  sudo apt-get install -y $MISSING_DEPS"
    exit 1
fi
```

**Interactive Config Setup**:
```bash
echo "Fossibot Account Setup"
read -p "Email: " email
read -sp "Password: " password
echo
read -p "Mosquitto Host [localhost]: " mqtt_host
mqtt_host=${mqtt_host:-localhost}

# Generate config.json from template with substitution
jq ".accounts[0].email = \"$email\" | \
    .accounts[0].password = \"$password\" | \
    .mosquitto.host = \"$mqtt_host\"" \
    /opt/fossibot-bridge/config/example.json > /etc/fossibot/config.json
```

#### B. `uninstall.sh` - Vollständige Entfernung

**Features**:
- Service Stop & Disable
- File Removal (mit Backup-Option für /etc/fossibot)
- User Removal
- Cleanup Verification

**Backup-Logik**:
```bash
if [ -f /etc/fossibot/config.json ]; then
    read -p "Backup config to /tmp/fossibot-config.json? [Y/n] " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        cp /etc/fossibot/config.json /tmp/fossibot-config.json
        echo "✅ Config backed up to /tmp/fossibot-config.json"
    fi
fi
```

#### C. `upgrade.sh` - In-Place Upgrade

**Features**:
- Stop Service
- Backup Current Installation
- Update Code (src/, daemon/, vendor/)
- **Preserve Config** (/etc/fossibot/config.json)
- Preserve Logs & Cache
- **Config Schema Check** (statt automatischer Migration)
- Start Service
- Verify Upgrade

**Config Schema Comparison** (pragmatischer Ansatz):
```bash
# Compare config schemas
echo "Checking config schema..."
MISSING_KEYS=$(jq -n --argfile old /etc/fossibot/config.json \
                     --argfile new /opt/fossibot-bridge/config/example.json \
                     '($new | keys) - ($old | keys)')

if [ "$MISSING_KEYS" != "[]" ]; then
    echo "⚠️  WARNING: New config parameters detected:"
    echo "$MISSING_KEYS" | jq -r '.[]'
    echo ""
    echo "Please review /opt/fossibot-bridge/config/example.json"
    echo "and update /etc/fossibot/config.json accordingly."
    echo ""
    read -p "Continue anyway? [y/N] " -n 1 -r
    echo
    [[ ! $REPLY =~ ^[Yy]$ ]] && exit 1
fi
```

---

### Phase 6: systemd Service Enhancement (30min) - P0

**File**: `daemon/fossibot-bridge.service`

**Additions**:
```ini
[Unit]
Description=Fossibot MQTT Bridge Daemon
Documentation=https://github.com/youruser/fossibot-php2
After=network-online.target mosquitto.service
Wants=network-online.target mosquitto.service

[Service]
Type=simple
User=fossibot
Group=fossibot
WorkingDirectory=/opt/fossibot-bridge

# Pre-validation (NEU)
ExecStartPre=/usr/bin/php /opt/fossibot-bridge/daemon/fossibot-bridge.php --config /etc/fossibot/config.json --validate

ExecStart=/usr/bin/php /opt/fossibot-bridge/daemon/fossibot-bridge.php --config /etc/fossibot/config.json

# Reload support (NEU)
ExecReload=/bin/kill -HUP $MAINPID

Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

# PID File (NEU)
PIDFile=/var/run/fossibot/bridge.pid
RuntimeDirectory=fossibot
RuntimeDirectoryMode=0755

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/log/fossibot /var/lib/fossibot
ProtectKernelTunables=true
ProtectControlGroups=true
RestrictRealtime=true

# Resource limits
LimitNOFILE=65536
MemoryMax=512M
CPUQuota=200%

# Environment
Environment="PHP_MEMORY_LIMIT=256M"

# Health Check (NEU)
ExecStartPost=/bin/sleep 5
ExecStartPost=/usr/bin/curl -sf http://localhost:8080/health

[Install]
WantedBy=multi-user.target
```

---

### Phase 7: Documentation (1h) - P0

**Files**:
- `INSTALL.md` - Installation Guide
- `UPGRADE.md` - Upgrade Guide
- `DEPLOYMENT.md` - Production Deployment Best Practices
- `TROUBLESHOOTING.md` - Common Issues & Solutions

---

## 📊 Configuration Parameter Review

### Bestehende Parameter

| Parameter | Aktuell | Production | Anmerkung |
|-----------|---------|------------|-----------|
| `daemon.log_file` | `logs/bridge.log` | `/var/log/fossibot/bridge.log` | FHS-konform |
| `daemon.log_level` | `debug` | `info` | Production = info |
| `bridge.status_publish_interval` | `60` | `60` | ✅ OK |
| `bridge.device_poll_interval` | `30` | `30` | ✅ OK |
| `bridge.reconnect_delay_min` | `5` | `5` | ✅ OK |
| `bridge.reconnect_delay_max` | `60` | `60` | ✅ OK |

### Neue Parameter (HINZUFÜGEN)

| Parameter | Default | Beschreibung |
|-----------|---------|--------------|
| `daemon.pid_file` | `/var/run/fossibot/bridge.pid` | PID File Location |
| `cache.enabled` | `true` | Token/Device Cache aktivieren |
| `cache.directory` | `/var/lib/fossibot/token-cache` | Cache Storage |
| `cache.token_ttl_safety_margin` | `300` | 5min vor Ablauf neu holen |
| `cache.device_list_ttl` | `3600` | 1h Device Cache |
| `monitoring.health_check_enabled` | `true` | Health Endpoint aktivieren |
| `monitoring.health_check_port` | `8080` | Health Endpoint Port |
| `monitoring.metrics_enabled` | `false` | Prometheus Metrics (optional) |

---

## 🔒 Credential Management

**Aktuelle Situation**: Credentials in config.json (Plaintext)

**Production Options**:

### Option 1: Encrypted Config (Empfohlen für Start)
```bash
# Encrypt config
openssl enc -aes-256-cbc -salt -in config.json -out config.json.enc

# Decrypt on start (in systemd service)
ExecStartPre=/usr/local/bin/decrypt-config.sh
```

### Option 2: Environment Variables (systemd)
```ini
# In fossibot-bridge.service
EnvironmentFile=/etc/fossibot/credentials.env

# credentials.env:
FOSSIBOT_ACCOUNT_1_EMAIL=tim@example.de
FOSSIBOT_ACCOUNT_1_PASSWORD=secret
```

### Option 3: External Secrets Manager (Enterprise)
- HashiCorp Vault
- AWS Secrets Manager
- systemd credentials (systemd v250+)

**Empfehlung**: Option 1 (Encrypted Config) - balanciert Security/Complexity

---

## 🔍 Testing Strategy

### Test-Szenarien

#### 1. Fresh Install auf Clean Ubuntu 24.04
```bash
curl -sSL https://raw.githubusercontent.com/youruser/fossibot-php2/main/install.sh | sudo bash
```

#### 2. Upgrade von v1.0 → v2.0
```bash
cd /opt/fossibot-bridge
sudo ./upgrade.sh
```

#### 3. Service Lifecycle
```bash
sudo fossibot-bridge-ctl start
sudo fossibot-bridge-ctl status
sudo fossibot-bridge-ctl restart
sudo fossibot-bridge-ctl stop
```

#### 4. Crash Recovery
```bash
# Simulate crash
sudo kill -9 $(cat /var/run/fossibot/bridge.pid)

# Check auto-restart (should happen within 10s)
sudo fossibot-bridge-ctl status
```

#### 5. Config Reload (P2 - Nice-to-Have)
```bash
# Change debug setting
sudo nano /etc/fossibot/config.json  # debug.log_update_source: false → true

# Reload without restart
sudo systemctl reload fossibot-bridge

# Verify change took effect in logs
```

---

## 📝 Prioritäten & Zeitplan

### P0 (Must-Have) - ~4h
- ✅ PID File Management (30min)
- ✅ Control Script (1h)
- ✅ Installation Scripts (2h)
- ✅ systemd Service Enhancement (30min)

### P1 (Should-Have) - ~3h
- ✅ Cache Persistence (1-2h)
- ✅ Health Check Server (1h)

### P2 (Nice-to-Have) - ~2h
- ⏸️ Config Hot-Reload (30min)
- ⏸️ Credential Encryption (1h)
- ⏸️ Prometheus Metrics (30min)

**Gesamt**: 8-10 Stunden

---

## ✅ Senior Review Feedback (Eingearbeitet)

### 🔴 Kritischer Punkt - KORRIGIERT
- ❌ **Fehler**: Cache-Integration in `Connection.php` geplant
- ✅ **Korrektur**: Cache-Integration in `AsyncCloudClient.php` (Zeilen 82-92)
  - `authenticate()` - Token Cache Check
  - `discoverDevices()` - Device Cache Check

### 💡 Verbesserungsvorschläge - UMGESETZT
1. ✅ `jq` Dependency im `install.sh` prüfen
2. ✅ Config Migration → Pragmatischer Ansatz (Diff + Manual Review statt Auto-Migration)

---

## 🚀 Nächste Schritte

1. ✅ Plan-Review durch Senior - **APPROVED**
2. ⏭️ Implementation nach Priorität (P0 → P1 → P2)
3. ⏭️ Testing auf Clean Ubuntu VM
4. ⏭️ Documentation

---

**Version**: 1.0
**Last Updated**: 2025-10-03
**Status**: Ready for Implementation
