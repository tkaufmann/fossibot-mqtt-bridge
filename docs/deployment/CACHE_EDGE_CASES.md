# Cache Edge Cases & Token Lifecycle

**Kontext**: Token-Gültigkeit, App-Konflikte, Cache-Invalidierung
**Status**: Architecture Review
**Datum**: 2025-10-03

---

## 🔍 Token-Lifecycle-Analyse

### Bekannte Token-TTL (aus CLAUDE.md)

| Token | Gültigkeit | Ablauf-Indikator | Cache-Würdigkeit |
|-------|-----------|------------------|------------------|
| **Anonymous Token (S1)** | 10 Minuten | `expiresInSecond: 600` | ❌ Zu kurz, lohnt nicht |
| **Login Token (S2)** | ~14 Jahre | `tokenExpired: 2073560992097` (Jahr 2037) | ✅ Definitiv cachen |
| **MQTT Token (S3)** | ~3 Tage | JWT `exp` claim (~259200 Sekunden) | ✅ Definitiv cachen |

### Aktuelles Runtime Token Monitoring

**AsyncCloudClient.php** hat bereits Token-Expiry-Tracking:
```php
// Lines 47-49
private ?int $mqttTokenExpiresAt = null;
private ?int $loginTokenExpiresAt = null;

// Lines 358-376: isAuthenticated() prüft Ablauf
if ($this->mqttTokenExpiresAt !== null && $this->mqttTokenExpiresAt <= $now) {
    // Token abgelaufen → return false
}
```

**Aber**: Kein automatischer Re-Auth-Trigger bei Ablauf während Laufzeit!

---

## 🚨 Kritische Edge Cases

### Edge Case 1: Token läuft während Bridge-Laufzeit ab

**Szenario**: Bridge läuft 4 Tage, MQTT Token (~3 Tage) läuft ab

**Aktuelles Verhalten**:
1. MQTT Verbindung wird vom Server getrennt (Token ungültig)
2. `AsyncCloudClient::handleDisconnect()` wird aufgerufen
3. Reconnect-Logik startet mit **gecachten, abgelaufenen Tokens**
4. ❌ **PROBLEM**: Reconnect scheitert, weil isAuthenticated() false zurückgibt
5. ❌ **PROBLEM**: Keine automatische Re-Authentifizierung

**Gewünschtes Verhalten**:
1. MQTT Disconnect erkannt
2. `isAuthenticated()` prüft Token-Gültigkeit
3. ✅ **FIX**: Bei abgelaufenen Tokens → `clearAuthTokens()` + neuer Full Auth Flow
4. ✅ **FIX**: Neue Tokens in Cache schreiben
5. Reconnect mit frischen Tokens

**Code-Fix Needed**:
```php
// In AsyncCloudClient::handleDisconnect() - vor reconnectWithBackoff()
if (!$this->isAuthenticated()) {
    $this->logger->warning('Tokens expired during runtime, clearing cache', [
        'email' => $this->email
    ]);
    $this->clearAuthTokens();

    // Falls Cache existiert: invalidiere dort auch
    if ($this->tokenCache) {
        $this->tokenCache->invalidate($this->email);
    }
}
```

---

### Edge Case 2: App-Login invalidiert Bridge-Tokens

**Szenario**: User loggt sich mit Smartphone-App ein → Bridge-Tokens werden ungültig

**Root Cause Analyse**:
- Fossibot API erlaubt möglicherweise nur **eine aktive Session pro Account**
- Neuer Login → alte Tokens werden server-seitig invalidiert
- Bridge merkt es erst bei nächstem API-Call oder MQTT-Disconnect

**Symptome**:
1. MQTT Verbindung wird plötzlich geschlossen (auth error)
2. Reconnect mit gecachten Tokens schlägt fehl (401 Unauthorized)
3. Bridge stuck in Reconnect-Loop

**Detection Strategie**:
```php
// MQTT Disconnect Reason Codes (MQTT 3.1.1)
// Reason Code 5 = Connection Refused, not authorized
// Reason Code 4 = Connection Refused, bad username or password

private function handleMqttDisconnect(int $reasonCode): void
{
    if ($reasonCode === 4 || $reasonCode === 5) {
        // Auth failure → Token wurde server-seitig invalidiert
        $this->logger->warning('MQTT auth failure - tokens likely invalidated by external login', [
            'reason_code' => $reasonCode,
            'email' => $this->email
        ]);

        $this->clearAuthTokens();
        $this->tokenCache?->invalidate($this->email);

        // Force full re-auth bei nächstem Reconnect
    }
}
```

**Aber**: AsyncMqttClient gibt aktuell keinen Reason Code zurück!
→ **TODO**: AsyncMqttClient erweitern um Disconnect Reason

---

### Edge Case 3: Cache-Datei enthält abgelaufene Tokens beim Bridge-Start

**Szenario**: Bridge war 1 Woche offline, Cache enthält 3-Tage-alte MQTT Tokens

**Aktuelles Verhalten** (mit naivem Cache):
1. Bridge liest Cache → `mqttToken` ist 7 Tage alt
2. Versucht MQTT-Verbindung mit abgelaufenem Token
3. Auth-Fehler → Reconnect-Loop

**FIX: TTL-Aware Cache-Read**:
```php
// In TokenCache::getCachedToken()
public function getCachedToken(string $email, string $stage): ?CachedToken
{
    $cached = $this->readFromDisk($email);

    if (!isset($cached[$stage])) {
        return null;
    }

    $token = $cached[$stage];

    // Prüfe Gültigkeit MIT Safety Margin
    $now = time();
    $safetyMargin = $this->config['cache']['token_ttl_safety_margin'] ?? 300; // 5min

    if ($token['expires_at'] <= ($now + $safetyMargin)) {
        $this->logger->debug('Cached token expired or expiring soon', [
            'email' => $email,
            'stage' => $stage,
            'expires_at' => date('Y-m-d H:i:s', $token['expires_at']),
            'ttl_remaining' => $token['expires_at'] - $now
        ]);
        return null; // Treat as cache miss
    }

    return new CachedToken(
        $token['token'],
        $token['expires_at'],
        $token['cached_at']
    );
}
```

**Safety Margin Rationale**:
- Token läuft in 4 Minuten ab → Cache-Miss → Frischen Token holen
- Verhindert Race Conditions (Token läuft während Auth-Flow ab)

---

### Edge Case 4: Anonymous Token (S1) - Zu kurz für Cache?

**Analyse**:
- TTL: 10 Minuten
- Wird für jede Stage 2 + 3 Request gebraucht
- Bridge Re-Auth passiert bei Disconnect (kann alle paar Stunden sein)

**Frage**: Lohnt sich Caching von S1?

**Antwort**: **Ja, aber nur für Startup-Optimierung!**

**Use Case**:
```
Bridge startet → Liest Cache:
- S2 (Login): Noch 10 Tage gültig ✅
- S3 (MQTT): Noch 2 Tage gültig ✅
- S1 (Anon): Abgelaufen (10min TTL) ❌

Optimierter Flow:
1. Neuen S1 Token holen (1 API Call)
2. S2 aus Cache nehmen (0 API Calls)
3. Mit S1 + S2 → Neuen S3 Token holen (1 API Call)
4. MQTT verbinden

Gespart: 1 API Call (Login)
```

**Ohne S1 Cache**: Gleich, da S1 für S2-Request eh gebraucht wird

**Entscheidung**: ✅ S1 cachen, aber mit kurzer TTL (9 Minuten, 1min Safety)

---

## 📋 Device List Cache

### Device List Refresh Strategie

**Frage**: Wann Device List neu holen?

**Szenarien**:
1. **User kauft neues Gerät** → Taucht nicht in Bridge auf
2. **User verkauft/löscht Gerät** → Bridge versucht weiter zu subscriben
3. **Gerät wird umbenannt** → Name in Bridge veraltet

**Aktuelle Realität**:
- Devices ändern sich **sehr selten** (Monate/Jahre)
- Device Discovery ist **teuer** (1 API Call + Parsing)

**Cache-Strategie**:

| Trigger | Refresh Interval | Begründung |
|---------|------------------|------------|
| **Cold Start** | Immer aus Cache (falls valid) | Schneller Start |
| **Periodic Refresh** | Alle 24h | Erkennt neue/gelöschte Geräte |
| **Manual Trigger** | On-Demand via MQTT/CLI | User kauft Gerät → Force Refresh |
| **Auth Error** | Bei Token-Invalidierung | Kompletter Reset |

**Implementation**:
```php
// In AsyncCloudClient::discoverDevices()
private function discoverDevices(): PromiseInterface
{
    // Check cache first
    $cached = $this->deviceCache->getDevices($this->email);

    if ($cached && $this->deviceCache->isValid($this->email)) {
        $this->logger->debug('Using cached device list', [
            'email' => $this->email,
            'device_count' => count($cached),
            'cache_age' => $this->deviceCache->getAge($this->email)
        ]);
        $this->devices = $cached;
        return \React\Promise\resolve(null);
    }

    // Cache miss or expired → fetch from API
    $this->logger->info('Fetching fresh device list from API', [
        'email' => $this->email
    ]);

    return $this->fetchDevicesFromApi()
        ->then(function($devices) {
            $this->devices = $devices;
            $this->deviceCache->saveDevices($this->email, $devices);

            $this->logger->info('Device list cached', [
                'email' => $this->email,
                'device_count' => count($devices)
            ]);

            return null;
        });
}
```

**Periodic Refresh** (in MqttBridge.php):
```php
// In MqttBridge::run()
Loop::addPeriodicTimer(86400, function() { // 24h
    $this->logger->info('Periodic device list refresh');

    foreach ($this->cloudClients as $client) {
        $client->refreshDeviceList(); // Neue Methode
    }
});
```

**Manual Refresh** via MQTT:
```bash
# User Command
mosquitto_pub -h localhost -t 'fossibot/bridge/command' \
    -m '{"action":"refresh_devices"}'
```

---

## 🔒 Cache Invalidation Triggers

### Wann Cache löschen?

| Trigger | Action | Begründung |
|---------|--------|------------|
| **MQTT Auth Failure** | Invalidate Tokens | Token server-seitig ungültig |
| **HTTP 401 Error** | Invalidate Tokens | Token abgelaufen/ungültig |
| **User Command** | Invalidate All | Debug/Troubleshooting |
| **Config Change** | Invalidate Account | Passwort geändert |
| **Graceful Shutdown** | Keep Cache | Schneller Restart |
| **Crash** | Keep Cache | Recovery |

### Cache-Invalidierung per MQTT Command:
```bash
# Full Cache Reset
mosquitto_pub -h localhost -t 'fossibot/bridge/command' \
    -m '{"action":"cache_invalidate"}'

# Single Account
mosquitto_pub -h localhost -t 'fossibot/bridge/command' \
    -m '{"action":"cache_invalidate","email":"tim@example.de"}'
```

---

## 🏗️ Erweiterte Cache-Architektur

### TokenCache.php - Enhanced

```php
class TokenCache
{
    private string $cacheDir;
    private LoggerInterface $logger;
    private int $safetyMargin;

    public function __construct(
        string $cacheDir,
        int $safetyMargin,
        LoggerInterface $logger
    ) {
        $this->cacheDir = $cacheDir;
        $this->safetyMargin = $safetyMargin;
        $this->logger = $logger;

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }
    }

    /**
     * Get cached token with automatic expiry check.
     */
    public function getCachedToken(string $email, string $stage): ?CachedToken
    {
        $cached = $this->readFromDisk($email);

        if (!isset($cached[$stage])) {
            return null;
        }

        $token = $cached[$stage];
        $now = time();

        // Check expiry with safety margin
        if ($token['expires_at'] <= ($now + $this->safetyMargin)) {
            $this->logger->debug('Cached token expired', [
                'email' => $email,
                'stage' => $stage,
                'ttl_remaining' => $token['expires_at'] - $now
            ]);
            return null;
        }

        return new CachedToken(
            $token['token'],
            $token['expires_at'],
            $token['cached_at']
        );
    }

    /**
     * Save token to cache.
     */
    public function saveToken(
        string $email,
        string $stage,
        string $token,
        int $expiresAt
    ): void {
        $cached = $this->readFromDisk($email) ?? [];

        $cached[$stage] = [
            'token' => $token,
            'expires_at' => $expiresAt,
            'cached_at' => time()
        ];

        $this->writeToDisk($email, $cached);

        $this->logger->debug('Token cached', [
            'email' => $email,
            'stage' => $stage,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt)
        ]);
    }

    /**
     * Invalidate all tokens for account.
     */
    public function invalidate(string $email): void
    {
        $file = $this->getCacheFile($email);
        if (file_exists($file)) {
            unlink($file);
            $this->logger->info('Token cache invalidated', ['email' => $email]);
        }
    }

    private function getCacheFile(string $email): string
    {
        $hash = md5($email);
        return "{$this->cacheDir}/{$hash}.json";
    }

    private function readFromDisk(string $email): ?array
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            return null;
        }

        $json = file_get_contents($file);
        return json_decode($json, true);
    }

    private function writeToDisk(string $email, array $data): void
    {
        $file = $this->getCacheFile($email);
        $json = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($file, $json, LOCK_EX);
        chmod($file, 0600); // Read/write only for owner
    }
}
```

### DeviceCache.php - Enhanced

```php
class DeviceCache
{
    private string $cacheDir;
    private LoggerInterface $logger;
    private int $ttl;

    public function __construct(
        string $cacheDir,
        int $ttl,
        LoggerInterface $logger
    ) {
        $this->cacheDir = $cacheDir;
        $this->ttl = $ttl;
        $this->logger = $logger;

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }
    }

    /**
     * Get cached devices with TTL check.
     */
    public function getDevices(string $email): ?array
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        $now = time();

        // Check TTL
        if (($data['cached_at'] + $this->ttl) <= $now) {
            $this->logger->debug('Device cache expired', [
                'email' => $email,
                'age' => $now - $data['cached_at']
            ]);
            return null;
        }

        return $data['devices'];
    }

    /**
     * Save devices to cache.
     */
    public function saveDevices(string $email, array $devices): void
    {
        $file = $this->getCacheFile($email);

        $data = [
            'cached_at' => time(),
            'devices' => $devices
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($file, $json, LOCK_EX);
        chmod($file, 0600);

        $this->logger->debug('Device list cached', [
            'email' => $email,
            'device_count' => count($devices)
        ]);
    }

    /**
     * Get cache age in seconds.
     */
    public function getAge(string $email): ?int
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        return time() - $data['cached_at'];
    }

    /**
     * Invalidate device cache.
     */
    public function invalidate(string $email): void
    {
        $file = $this->getCacheFile($email);
        if (file_exists($file)) {
            unlink($file);
            $this->logger->info('Device cache invalidated', ['email' => $email]);
        }
    }

    private function getCacheFile(string $email): string
    {
        $hash = md5($email);
        return "{$this->cacheDir}/devices_{$hash}.json";
    }
}
```

---

## 🧪 Testing Strategy

### Test Cases

#### 1. Token Expiry During Runtime
```bash
# Setup: Modify cache file with expired MQTT token
vim /var/lib/fossibot/token-cache/xxx.json
# Set s3_mqtt.expires_at = <now - 3600>

# Start bridge
./daemon/fossibot-bridge.php -c config.json

# Expected: Fresh auth, no errors
```

#### 2. App Login Invalidation
```bash
# Setup: Bridge running
# Action: Login mit Smartphone App
# Monitor: tail -f /var/log/fossibot/bridge.log

# Expected:
# - MQTT disconnect detected
# - "tokens likely invalidated by external login"
# - Fresh re-auth
# - Reconnect successful
```

#### 3. Cache Corruption
```bash
# Setup: Corrupt cache file
echo "invalid json" > /var/lib/fossibot/token-cache/xxx.json

# Start bridge
# Expected: Ignore cache, fresh auth, overwrite cache
```

---

## 📝 Zusammenfassung: Required Changes

### AsyncCloudClient.php

1. **Token Expiry Handling** in `handleDisconnect()`:
```php
if (!$this->isAuthenticated()) {
    $this->clearAuthTokens();
    $this->tokenCache?->invalidate($this->email);
}
```

2. **Device List Refresh Method**:
```php
public function refreshDeviceList(): PromiseInterface
{
    $this->deviceCache?->invalidate($this->email);
    return $this->discoverDevices();
}
```

3. **Cache Integration** in `authenticate()` und `discoverDevices()`

### MqttBridge.php

1. **Periodic Device Refresh** (24h Timer)
2. **MQTT Command Handlers**:
   - `cache_invalidate`
   - `refresh_devices`

### AsyncMqttClient.php

1. **Disconnect Reason Code** in disconnect callback
   - Erfordert Pawl/Ratchet Message Parsing

---

## 🎯 Priorität

| Feature | Priority | Reason |
|---------|----------|--------|
| Token Cache mit TTL Check | P0 | Verhindert Auth-Loops |
| Token Invalidation on Auth Failure | P0 | Verhindert Stuck States |
| Device Cache mit 24h TTL | P1 | Performance-Optimierung |
| Periodic Device Refresh | P1 | Erkennt neue Devices |
| Manual Cache Invalidation | P2 | Debug/Troubleshooting |
| MQTT Disconnect Reason Codes | P2 | Nice-to-Have Detection |

---

**Status**: Ready for Implementation
**Next Step**: Implementierung der P0-Features in AsyncCloudClient.php
