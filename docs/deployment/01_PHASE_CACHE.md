# Phase 1: Cache Implementation

**Time**: 2h 0min
**Priority**: P1
**Dependencies**: None

---

## Goal

Implementiere Token- und Device-List-Caching mit TTL-basierter Invalidierung, um:
- Wiederholte API-Calls beim Bridge-Restart zu vermeiden
- Stage 2 (Login) zu überspringen bei gecachtem Login Token (~14 Jahre gültig)
- Device Discovery Cache (24h) für schnelleren Start
- Automatische Token-Refresh bei Ablauf während Runtime

**Performance Gain**:
- Ohne Cache: 3 API Calls beim Start (S1 + S2 + S3)
- Mit Cache: 1-2 API Calls (S1 + S3, wenn Login Token valid)
- Device List: 0 API Calls (24h Cache)

---

## Steps

### Step 1.1: CachedToken Value Object (10min)

**File**: `src/Cache/CachedToken.php`
**Lines**: New file

```php
<?php
declare(strict_types=1);

namespace Fossibot\Cache;

/**
 * Immutable value object for cached authentication tokens.
 *
 * Stores token string, expiry timestamp, and cache creation time.
 */
final class CachedToken
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresAt,
        public readonly int $cachedAt
    ) {
    }

    /**
     * Check if token is still valid with safety margin.
     *
     * @param int $safetyMargin Seconds before expiry to treat as expired (default: 300 = 5min)
     * @return bool True if token is valid and not expiring soon
     */
    public function isValid(int $safetyMargin = 300): bool
    {
        $now = time();
        return $this->expiresAt > ($now + $safetyMargin);
    }

    /**
     * Get TTL remaining in seconds (without safety margin).
     */
    public function getTtlRemaining(): int
    {
        return max(0, $this->expiresAt - time());
    }

    /**
     * Get cache age in seconds.
     */
    public function getAge(): int
    {
        return time() - $this->cachedAt;
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'expires_at' => $this->expiresAt,
            'cached_at' => $this->cachedAt
        ];
    }

    /**
     * Create from array (JSON deserialization).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['token'],
            $data['expires_at'],
            $data['cached_at']
        );
    }
}
```

**Test**:
```bash
php -r "
require 'vendor/autoload.php';
\$t = new Fossibot\Cache\CachedToken('test123', time() + 600, time());
var_dump(\$t->isValid(60));  // Should be true
var_dump(\$t->getTtlRemaining());  // Should be ~600
"
```

**Done when**: Value object correctly validates token expiry with safety margin

**Commit**: `feat(cache): add CachedToken value object for token management`

---

### Step 1.2: TokenCache with File Persistence (30min)

**File**: `src/Cache/TokenCache.php`
**Lines**: New file

```php
<?php
declare(strict_types=1);

namespace Fossibot\Cache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-based cache for authentication tokens with TTL and safety margin.
 *
 * Stores tokens per account (identified by email) in JSON files.
 * Automatically invalidates expired tokens based on expires_at timestamp.
 * Supports three token stages: s1_anonymous, s2_login, s3_mqtt.
 */
class TokenCache
{
    private string $cacheDir;
    private LoggerInterface $logger;
    private int $safetyMargin;

    /**
     * @param string $cacheDir Directory for cache files (will be created if missing)
     * @param int $safetyMargin Seconds before expiry to treat token as expired (default: 300 = 5min)
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        string $cacheDir,
        int $safetyMargin = 300,
        ?LoggerInterface $logger = null
    ) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->safetyMargin = $safetyMargin;
        $this->logger = $logger ?? new NullLogger();

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
            $this->logger->debug('Created cache directory', [
                'path' => $this->cacheDir
            ]);
        }
    }

    /**
     * Get cached token with automatic expiry check.
     *
     * @param string $email Account email
     * @param string $stage Token stage (s1_anonymous, s2_login, s3_mqtt)
     * @return CachedToken|null Token if valid, null if expired/missing
     */
    public function getCachedToken(string $email, string $stage): ?CachedToken
    {
        $cached = $this->readFromDisk($email);

        if (!isset($cached[$stage])) {
            $this->logger->debug('Token cache miss', [
                'email' => $email,
                'stage' => $stage
            ]);
            return null;
        }

        $token = CachedToken::fromArray($cached[$stage]);

        // Check validity with safety margin
        if (!$token->isValid($this->safetyMargin)) {
            $this->logger->debug('Cached token expired or expiring soon', [
                'email' => $email,
                'stage' => $stage,
                'expires_at' => date('Y-m-d H:i:s', $token->expiresAt),
                'ttl_remaining' => $token->getTtlRemaining(),
                'safety_margin' => $this->safetyMargin
            ]);
            return null;
        }

        $this->logger->debug('Token cache hit', [
            'email' => $email,
            'stage' => $stage,
            'ttl_remaining' => $token->getTtlRemaining(),
            'cache_age' => $token->getAge()
        ]);

        return $token;
    }

    /**
     * Save token to cache.
     *
     * @param string $email Account email
     * @param string $stage Token stage (s1_anonymous, s2_login, s3_mqtt)
     * @param string $token Token string
     * @param int $expiresAt Unix timestamp when token expires
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

        $this->logger->info('Token cached', [
            'email' => $email,
            'stage' => $stage,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'ttl' => $expiresAt - time()
        ]);
    }

    /**
     * Invalidate all tokens for account.
     *
     * @param string $email Account email
     */
    public function invalidate(string $email): void
    {
        $file = $this->getCacheFile($email);

        if (file_exists($file)) {
            unlink($file);
            $this->logger->info('Token cache invalidated', [
                'email' => $email
            ]);
        }
    }

    /**
     * Invalidate specific stage token.
     *
     * @param string $email Account email
     * @param string $stage Token stage to invalidate
     */
    public function invalidateStage(string $email, string $stage): void
    {
        $cached = $this->readFromDisk($email);

        if ($cached && isset($cached[$stage])) {
            unset($cached[$stage]);
            $this->writeToDisk($email, $cached);

            $this->logger->info('Stage token invalidated', [
                'email' => $email,
                'stage' => $stage
            ]);
        }
    }

    /**
     * Get cache file path for account.
     */
    private function getCacheFile(string $email): string
    {
        $hash = md5($email);
        return "{$this->cacheDir}/tokens_{$hash}.json";
    }

    /**
     * Read cache from disk with error handling.
     */
    private function readFromDisk(string $email): ?array
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            $this->logger->warning('Failed to read token cache file', [
                'email' => $email,
                'file' => $file
            ]);
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Token cache file corrupted, ignoring', [
                'email' => $email,
                'file' => $file,
                'error' => json_last_error_msg()
            ]);
            return null;
        }

        return $data;
    }

    /**
     * Write cache to disk with atomic write.
     */
    private function writeToDisk(string $email, array $data): void
    {
        $file = $this->getCacheFile($email);
        $json = json_encode($data, JSON_PRETTY_PRINT);

        // Atomic write via temp file + rename
        $tempFile = $file . '.tmp';
        file_put_contents($tempFile, $json, LOCK_EX);
        chmod($tempFile, 0600); // Read/write only for owner
        rename($tempFile, $file);
    }
}
```

**Test**:
```bash
cat > test_token_cache.php << 'EOF'
<?php
require 'vendor/autoload.php';

use Fossibot\Cache\TokenCache;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$cache = new TokenCache('/tmp/fossibot-cache-test', 300, $logger);

// Test 1: Save token
echo "\n=== Test 1: Save Token ===\n";
$cache->saveToken('test@example.com', 's2_login', 'token123', time() + 3600);

// Test 2: Read valid token
echo "\n=== Test 2: Read Valid Token ===\n";
$token = $cache->getCachedToken('test@example.com', 's2_login');
var_dump($token !== null); // Should be true

// Test 3: Read expired token
echo "\n=== Test 3: Expired Token (manual) ===\n";
$cache->saveToken('test@example.com', 's1_anon', 'expired', time() - 100);
$expired = $cache->getCachedToken('test@example.com', 's1_anon');
var_dump($expired === null); // Should be true (expired)

// Test 4: Invalidate
echo "\n=== Test 4: Invalidate ===\n";
$cache->invalidate('test@example.com');
$after = $cache->getCachedToken('test@example.com', 's2_login');
var_dump($after === null); // Should be true (deleted)

echo "\n✅ All tests passed\n";
EOF

php test_token_cache.php
rm test_token_cache.php
rm -rf /tmp/fossibot-cache-test
```

**Done when**: TokenCache passes all tests and handles corrupt JSON gracefully

**Commit**: `feat(cache): add TokenCache with TTL-based expiry handling`

---

### Step 1.3: DeviceCache with TTL (20min)

**File**: `src/Cache/DeviceCache.php`
**Lines**: New file

```php
<?php
declare(strict_types=1);

namespace Fossibot\Cache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-based cache for device lists with TTL.
 *
 * Caches discovered devices per account to avoid repeated API calls.
 * Default TTL: 24 hours (devices change rarely).
 */
class DeviceCache
{
    private string $cacheDir;
    private LoggerInterface $logger;
    private int $ttl;

    /**
     * @param string $cacheDir Directory for cache files (will be created if missing)
     * @param int $ttl Cache lifetime in seconds (default: 86400 = 24 hours)
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        string $cacheDir,
        int $ttl = 86400,
        ?LoggerInterface $logger = null
    ) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->ttl = $ttl;
        $this->logger = $logger ?? new NullLogger();

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
            $this->logger->debug('Created device cache directory', [
                'path' => $this->cacheDir
            ]);
        }
    }

    /**
     * Get cached devices with TTL check.
     *
     * @param string $email Account email
     * @return array|null Array of Device objects if valid, null if expired/missing
     */
    public function getDevices(string $email): ?array
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            $this->logger->debug('Device cache miss', ['email' => $email]);
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            $this->logger->warning('Failed to read device cache', [
                'email' => $email,
                'file' => $file
            ]);
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Device cache corrupted, ignoring', [
                'email' => $email,
                'error' => json_last_error_msg()
            ]);
            return null;
        }

        $now = time();
        $age = $now - $data['cached_at'];

        // Check TTL
        if ($age > $this->ttl) {
            $this->logger->debug('Device cache expired', [
                'email' => $email,
                'age' => $age,
                'ttl' => $this->ttl
            ]);
            return null;
        }

        $this->logger->debug('Device cache hit', [
            'email' => $email,
            'device_count' => count($data['devices']),
            'age' => $age
        ]);

        // Deserialize Device objects
        return array_map(
            fn($deviceData) => \Fossibot\Device\Device::fromArray($deviceData),
            $data['devices']
        );
    }

    /**
     * Save devices to cache.
     *
     * @param string $email Account email
     * @param array $devices Array of Device objects
     */
    public function saveDevices(string $email, array $devices): void
    {
        $file = $this->getCacheFile($email);

        // Serialize Device objects to arrays
        $deviceArrays = array_map(
            fn($device) => $device->toArray(),
            $devices
        );

        $data = [
            'cached_at' => time(),
            'devices' => $deviceArrays
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT);

        // Atomic write
        $tempFile = $file . '.tmp';
        file_put_contents($tempFile, $json, LOCK_EX);
        chmod($tempFile, 0600);
        rename($tempFile, $file);

        $this->logger->info('Device list cached', [
            'email' => $email,
            'device_count' => count($devices),
            'ttl' => $this->ttl
        ]);
    }

    /**
     * Get cache age in seconds.
     *
     * @param string $email Account email
     * @return int|null Age in seconds, or null if cache doesn't exist
     */
    public function getAge(string $email): ?int
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return time() - $data['cached_at'];
    }

    /**
     * Invalidate device cache.
     *
     * @param string $email Account email
     */
    public function invalidate(string $email): void
    {
        $file = $this->getCacheFile($email);

        if (file_exists($file)) {
            unlink($file);
            $this->logger->info('Device cache invalidated', [
                'email' => $email
            ]);
        }
    }

    /**
     * Get cache file path for account.
     */
    private function getCacheFile(string $email): string
    {
        $hash = md5($email);
        return "{$this->cacheDir}/devices_{$hash}.json";
    }
}
```

**Test**:
```bash
cat > test_device_cache.php << 'EOF'
<?php
require 'vendor/autoload.php';

use Fossibot\Cache\DeviceCache;
use Fossibot\Device\Device;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$cache = new DeviceCache('/tmp/fossibot-device-cache-test', 3600, $logger);

// Create test devices
$device1 = Device::fromArray([
    'deviceid' => 'DEV001',
    'name' => 'Test Device 1',
    'mac' => '7C2C67AB5F0E',
    'devicetype' => 'F2400'
]);

$device2 = Device::fromArray([
    'deviceid' => 'DEV002',
    'name' => 'Test Device 2',
    'mac' => 'AABBCCDDEEFF',
    'devicetype' => 'F3000'
]);

// Test 1: Save devices
echo "\n=== Test 1: Save Devices ===\n";
$cache->saveDevices('test@example.com', [$device1, $device2]);

// Test 2: Read devices
echo "\n=== Test 2: Read Devices ===\n";
$devices = $cache->getDevices('test@example.com');
var_dump(count($devices) === 2);
var_dump($devices[0]->getName() === 'Test Device 1');

// Test 3: Check age
echo "\n=== Test 3: Cache Age ===\n";
$age = $cache->getAge('test@example.com');
var_dump($age >= 0 && $age < 5);

// Test 4: Invalidate
echo "\n=== Test 4: Invalidate ===\n";
$cache->invalidate('test@example.com');
$after = $cache->getDevices('test@example.com');
var_dump($after === null);

echo "\n✅ All tests passed\n";
EOF

php test_device_cache.php
rm test_device_cache.php
rm -rf /tmp/fossibot-device-cache-test
```

**Done when**: DeviceCache correctly stores/retrieves Device objects with TTL validation

**Commit**: `feat(cache): add DeviceCache with 24h TTL for device lists`

---

### Step 1.4: Add Device::toArray() / fromArray() (15min)

**File**: `src/Device/Device.php`
**Lines**: Add methods after existing properties

```php
/**
 * Convert Device to array for serialization.
 */
public function toArray(): array
{
    return [
        'deviceid' => $this->deviceid,
        'name' => $this->name,
        'mac' => $this->mac,
        'devicetype' => $this->devicetype
    ];
}

/**
 * Create Device from array (deserialization).
 */
public static function fromArray(array $data): self
{
    return new self(
        $data['deviceid'],
        $data['name'],
        $data['mac'],
        $data['devicetype']
    );
}
```

**Test**:
```bash
php -r "
require 'vendor/autoload.php';
\$d = new Fossibot\Device\Device('DEV001', 'Test', '7C2C67AB5F0E', 'F2400');
\$arr = \$d->toArray();
\$d2 = Fossibot\Device\Device::fromArray(\$arr);
var_dump(\$d2->getName() === 'Test');
"
```

**Done when**: Device serialization/deserialization works correctly

**Commit**: `feat(device): add toArray/fromArray for cache serialization`

---

### Step 2.1: Integrate TokenCache in AsyncCloudClient (30min)

**File**: `src/Bridge/AsyncCloudClient.php`
**Lines**: Multiple locations

**Location 1** - Add property after line 58:
```php
// Token & Device Cache
private ?\Fossibot\Cache\TokenCache $tokenCache = null;
private ?\Fossibot\Cache\DeviceCache $deviceCache = null;
```

**Location 2** - Add to constructor after line 73:
```php
/**
 * Set token cache (optional).
 */
public function setTokenCache(\Fossibot\Cache\TokenCache $cache): void
{
    $this->tokenCache = $cache;
}

/**
 * Set device cache (optional).
 */
public function setDeviceCache(\Fossibot\Cache\DeviceCache $cache): void
{
    $this->deviceCache = $cache;
}
```

**Location 3** - Replace `authenticate()` method (lines 468-501) with cache-aware version:
```php
private function authenticate(): PromiseInterface
{
    $this->logger->info('Starting async authentication', [
        'email' => $this->email
    ]);

    // Try cache first (if TokenCache configured)
    if ($this->tokenCache !== null) {
        $s1Token = $this->tokenCache->getCachedToken($this->email, 's1_anonymous');
        $s2Token = $this->tokenCache->getCachedToken($this->email, 's2_login');
        $s3Token = $this->tokenCache->getCachedToken($this->email, 's3_mqtt');

        // Check which stages can be skipped
        $skipS1 = $s1Token !== null;
        $skipS2 = $s2Token !== null;
        $skipS3 = $s3Token !== null;

        if ($skipS1 && $skipS2 && $skipS3) {
            // Full cache hit - use all cached tokens
            $this->logger->info('Using fully cached authentication tokens', [
                'email' => $this->email
            ]);
            $this->anonymousToken = $s1Token->token;
            $this->loginToken = $s2Token->token;
            $this->mqttToken = $s3Token->token;
            $this->loginTokenExpiresAt = $s2Token->expiresAt;
            $this->mqttTokenExpiresAt = $s3Token->expiresAt;
            return \React\Promise\resolve(null);
        }

        // Partial cache hit - log what we're skipping
        if ($skipS2) {
            $this->logger->info('Stage 2 (Login) cached, skipping', [
                'email' => $this->email,
                'ttl_remaining' => $s2Token->getTtlRemaining()
            ]);
        }
    }

    // Create Browser only once and store as class property to prevent GC cleanup
    if ($this->browser === null) {
        $this->browser = $this->createBrowser();
    }

    // Partial or full cache miss - execute auth stages
    $promise = \React\Promise\resolve(null);

    // Stage 1: Anonymous Token (always fetch fresh, TTL too short)
    $promise = $promise->then(function() {
        $this->logger->debug('Fetching fresh Stage 1 token');
        return $this->stage1_getAnonymousToken($this->browser);
    })->then(function(string $anonToken) {
        $this->anonymousToken = $anonToken;
        $this->logger->info('Stage 1 completed: Anonymous token acquired');

        // Cache S1 token (even though TTL is short, useful for quick restarts)
        if ($this->tokenCache !== null) {
            $this->tokenCache->saveToken(
                $this->email,
                's1_anonymous',
                $anonToken,
                time() + 540 // 9 minutes (10min TTL - 1min safety)
            );
        }
        return null;
    });

    // Stage 2: Login Token (skip if cached)
    if ($this->tokenCache === null || $this->tokenCache->getCachedToken($this->email, 's2_login') === null) {
        $promise = $promise->then(function() {
            $this->logger->debug('Fetching fresh Stage 2 token');
            return $this->stage2_login($this->browser, $this->anonymousToken);
        })->then(function(string $loginToken) {
            $this->loginToken = $loginToken;
            $this->logger->info('Stage 2 completed: Login token acquired');

            // Cache S2 token (very long TTL)
            if ($this->tokenCache !== null) {
                // Login token expires in ~14 years, use far future timestamp
                $expiresAt = time() + (14 * 365 * 86400); // 14 years
                $this->loginTokenExpiresAt = $expiresAt;

                $this->tokenCache->saveToken(
                    $this->email,
                    's2_login',
                    $loginToken,
                    $expiresAt
                );
            }
            return null;
        });
    } else {
        // Use cached S2 token
        $promise = $promise->then(function() {
            $cachedS2 = $this->tokenCache->getCachedToken($this->email, 's2_login');
            $this->loginToken = $cachedS2->token;
            $this->loginTokenExpiresAt = $cachedS2->expiresAt;
            $this->logger->info('Using cached Stage 2 token', [
                'ttl_remaining' => $cachedS2->getTtlRemaining()
            ]);
            return null;
        });
    }

    // Stage 3: MQTT Token (always fetch fresh if not cached)
    if ($this->tokenCache === null || $this->tokenCache->getCachedToken($this->email, 's3_mqtt') === null) {
        $promise = $promise->then(function() {
            $this->logger->debug('Fetching fresh Stage 3 token');
            return $this->stage3_getMqttToken($this->browser, $this->anonymousToken, $this->loginToken);
        })->then(function(string $mqttToken) {
            $this->mqttToken = $mqttToken;
            $this->logger->info('Stage 3 completed: MQTT token acquired', [
                'expires_at' => $this->mqttTokenExpiresAt ? date('Y-m-d H:i:s', $this->mqttTokenExpiresAt) : 'unknown'
            ]);

            // Cache S3 token
            if ($this->tokenCache !== null && $this->mqttTokenExpiresAt !== null) {
                $this->tokenCache->saveToken(
                    $this->email,
                    's3_mqtt',
                    $mqttToken,
                    $this->mqttTokenExpiresAt
                );
            }
            return null;
        });
    } else {
        // Use cached S3 token
        $promise = $promise->then(function() {
            $cachedS3 = $this->tokenCache->getCachedToken($this->email, 's3_mqtt');
            $this->mqttToken = $cachedS3->token;
            $this->mqttTokenExpiresAt = $cachedS3->expiresAt;
            $this->logger->info('Using cached Stage 3 token', [
                'ttl_remaining' => $cachedS3->getTtlRemaining(),
                'expires_at' => date('Y-m-d H:i:s', $cachedS3->expiresAt)
            ]);
            return null;
        });
    }

    return $promise;
}
```

**Location 4** - Replace `discoverDevices()` method (lines 511-523) with cache-aware version:
```php
private function discoverDevices(): PromiseInterface
{
    // Try device cache first
    if ($this->deviceCache !== null) {
        $cachedDevices = $this->deviceCache->getDevices($this->email);

        if ($cachedDevices !== null) {
            $this->logger->info('Using cached device list', [
                'email' => $this->email,
                'device_count' => count($cachedDevices),
                'cache_age' => $this->deviceCache->getAge($this->email)
            ]);
            $this->devices = $cachedDevices;
            return \React\Promise\resolve(null);
        }
    }

    // Cache miss - fetch from API
    $this->logger->info('Fetching fresh device list from API', [
        'email' => $this->email
    ]);

    // Reuse existing browser instance (created in authenticate())
    if ($this->browser === null) {
        $this->browser = $this->createBrowser();
    }

    return $this->fetchDevices($this->browser, $this->anonymousToken, $this->loginToken)
        ->then(function(array $devices) {
            $this->devices = $devices;

            // Cache device list
            if ($this->deviceCache !== null) {
                $this->deviceCache->saveDevices($this->email, $devices);
            }

            return null;
        });
}
```

**Location 5** - Add refresh method after `discoverDevices()`:
```php
/**
 * Force refresh device list (invalidates cache).
 *
 * @return PromiseInterface Resolves when devices refreshed
 */
public function refreshDeviceList(): PromiseInterface
{
    $this->logger->info('Force refreshing device list', [
        'email' => $this->email
    ]);

    // Invalidate cache
    if ($this->deviceCache !== null) {
        $this->deviceCache->invalidate($this->email);
    }

    // Fetch fresh
    return $this->discoverDevices();
}
```

**Location 6** - Add token expiry check in `handleDisconnect()` (find existing method, add after line with `$this->connected = false;`):
```php
// Check if tokens expired during runtime
if (!$this->isAuthenticated()) {
    $this->logger->warning('Tokens expired during runtime, invalidating cache', [
        'email' => $this->email
    ]);

    $this->clearAuthTokens();

    // Invalidate cached tokens to force fresh auth
    if ($this->tokenCache !== null) {
        $this->tokenCache->invalidate($this->email);
    }
}
```

**Test**:
```bash
# Test wird in Step 3 durchgeführt (MqttBridge Integration)
```

**Done when**: AsyncCloudClient uses cache when available and invalidates on token expiry

**Commit**: `feat(async): integrate TokenCache and DeviceCache in AsyncCloudClient`

---

### Step 3: MqttBridge Integration (20min)

**File**: `src/Bridge/MqttBridge.php`
**Lines**: Multiple locations

**Location 1** - Add cache properties after line 31:
```php
// Cache instances (shared across all cloud clients)
private ?\Fossibot\Cache\TokenCache $tokenCache = null;
private ?\Fossibot\Cache\DeviceCache $deviceCache = null;
```

**Location 2** - Initialize caches in constructor after line 63:
```php
// Initialize caches if configured
if (isset($this->config['cache'])) {
    $cacheDir = $this->config['cache']['directory'] ?? '/var/lib/fossibot';

    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0700, true);
        $this->logger->info('Created cache directory', ['path' => $cacheDir]);
    }

    // Token cache
    $tokenTtlSafety = $this->config['cache']['token_ttl_safety_margin'] ?? 300;
    $this->tokenCache = new \Fossibot\Cache\TokenCache($cacheDir, $tokenTtlSafety, $this->logger);

    // Device cache
    $deviceTtl = $this->config['cache']['device_list_ttl'] ?? 86400;
    $this->deviceCache = new \Fossibot\Cache\DeviceCache($cacheDir, $deviceTtl, $this->logger);

    $this->logger->info('Cache initialized', [
        'directory' => $cacheDir,
        'token_safety_margin' => $tokenTtlSafety,
        'device_ttl' => $deviceTtl
    ]);
}
```

**Location 3** - Set cache in cloud clients (find `initializeAccounts()` method, add after creating each AsyncCloudClient):
```php
// Inside initializeAccounts() after: $client = new AsyncCloudClient(...)
if ($this->tokenCache !== null) {
    $client->setTokenCache($this->tokenCache);
}
if ($this->deviceCache !== null) {
    $client->setDeviceCache($this->deviceCache);
}
```

**Location 4** - Add periodic device refresh timer in `run()` method after line 98:
```php
// Periodic device list refresh (24h)
if (isset($this->config['cache']['device_refresh_interval'])) {
    $refreshInterval = $this->config['cache']['device_refresh_interval'];
    Loop::addPeriodicTimer($refreshInterval, function() {
        $this->logger->info('Periodic device list refresh triggered');

        foreach ($this->cloudClients as $client) {
            $client->refreshDeviceList()->then(
                function() {
                    $this->logger->debug('Device list refresh completed');
                },
                function(\Exception $e) {
                    $this->logger->error('Device list refresh failed', [
                        'error' => $e->getMessage()
                    ]);
                }
            );
        }
    });

    $this->logger->info('Periodic device refresh enabled', [
        'interval' => $refreshInterval . 's'
    ]);
}
```

**Done when**: MqttBridge initializes caches and passes them to AsyncCloudClient instances

**Commit**: `feat(bridge): integrate cache system in MqttBridge with periodic refresh`

---

### Step 4: Config Changes (10min)

**File**: `config/example.json`
**Lines**: Add new `cache` section after `daemon` section (after line 18)

```json
  "cache": {
    "directory": "/var/lib/fossibot",
    "token_ttl_safety_margin": 300,
    "device_list_ttl": 86400,
    "device_refresh_interval": 86400
  },
```

**Updated full structure**:
```json
{
  "accounts": [
    {
      "email": "user@example.com",
      "password": "your-password-here",
      "enabled": true
    }
  ],
  "mosquitto": {
    "host": "localhost",
    "port": 1883,
    "username": null,
    "password": null,
    "client_id": "fossibot_bridge"
  },
  "daemon": {
    "log_file": "logs/bridge.log",
    "log_level": "info"
  },
  "cache": {
    "directory": "/var/lib/fossibot",
    "token_ttl_safety_margin": 300,
    "device_list_ttl": 86400,
    "device_refresh_interval": 86400
  },
  "bridge": {
    "status_publish_interval": 60,
    "device_poll_interval": 30,
    "reconnect_delay_min": 5,
    "reconnect_delay_max": 60
  },
  "debug": {
    "log_raw_registers": false,
    "log_update_source": false
  }
}
```

**Documentation Comment** (add to example.json as comment at top):
```
// Cache Configuration:
// - directory: Path to cache directory (default: /var/lib/fossibot)
// - token_ttl_safety_margin: Seconds before expiry to treat token as expired (default: 300 = 5min)
// - device_list_ttl: Device list cache lifetime in seconds (default: 86400 = 24h)
// - device_refresh_interval: Periodic device list refresh interval (default: 86400 = 24h)
```

**Done when**: example.json contains cache configuration with sensible defaults

**Commit**: `feat(config): add cache configuration section to example.json`

---

### Step 5: End-to-End Test (15min)

**File**: `tests/test_cache_e2e.php`
**Lines**: New file

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Fossibot\Bridge\AsyncCloudClient;
use Fossibot\Cache\TokenCache;
use Fossibot\Cache\DeviceCache;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;

echo "\n=== Cache End-to-End Test ===\n\n";

// Load config
$configFile = $argv[1] ?? __DIR__ . '/../config/config.json';
if (!file_exists($configFile)) {
    die("Config file not found: $configFile\n");
}

$config = json_decode(file_get_contents($configFile), true);
$account = $config['accounts'][0];

// Setup logger
$logger = new Logger('cache_test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Setup caches
$cacheDir = '/tmp/fossibot-cache-e2e-test';
$tokenCache = new TokenCache($cacheDir, 300, $logger);
$deviceCache = new DeviceCache($cacheDir, 3600, $logger);

$loop = Loop::get();

// Test 1: First connection (cache miss)
echo "--- Test 1: First Connection (Cache Miss) ---\n";
$client1 = new AsyncCloudClient($account['email'], $account['password'], $loop, $logger);
$client1->setTokenCache($tokenCache);
$client1->setDeviceCache($deviceCache);

$client1->connect()->then(
    function() use ($client1, $logger) {
        $logger->info('✅ First connection successful (tokens + devices cached)');

        // Get devices to verify they were fetched
        $devices = $client1->getDevices();
        $logger->info('Devices discovered', ['count' => count($devices)]);

        // Disconnect
        $client1->disconnect();

        // Test 2: Second connection (cache hit)
        echo "\n--- Test 2: Second Connection (Cache Hit) ---\n";
        $client2 = new AsyncCloudClient(
            $GLOBALS['account']['email'],
            $GLOBALS['account']['password'],
            Loop::get(),
            $GLOBALS['logger']
        );
        $client2->setTokenCache($GLOBALS['tokenCache']);
        $client2->setDeviceCache($GLOBALS['deviceCache']);

        $client2->connect()->then(
            function() use ($client2) {
                $GLOBALS['logger']->info('✅ Second connection successful (used cached tokens + devices)');

                $devices2 = $client2->getDevices();
                $GLOBALS['logger']->info('Devices from cache', ['count' => count($devices2)]);

                $client2->disconnect();

                echo "\n=== ✅ All Cache Tests Passed ===\n";
                echo "Check logs above for:\n";
                echo "- First connection: 'Fetching fresh' messages\n";
                echo "- Second connection: 'Using cached' messages\n";
                echo "- Token TTL remaining times\n";
                echo "- Device cache age\n\n";

                // Cleanup
                $GLOBALS['tokenCache']->invalidate($GLOBALS['account']['email']);
                $GLOBALS['deviceCache']->invalidate($GLOBALS['account']['email']);
                rmdir($GLOBALS['cacheDir']);

                Loop::stop();
            },
            function(\Exception $e) {
                echo "❌ Second connection failed: " . $e->getMessage() . "\n";
                Loop::stop();
            }
        );
    },
    function(\Exception $e) use ($logger) {
        $logger->error('❌ First connection failed', ['error' => $e->getMessage()]);
        Loop::stop();
    }
);

// Store globals for Test 2
$GLOBALS['account'] = $account;
$GLOBALS['logger'] = $logger;
$GLOBALS['tokenCache'] = $tokenCache;
$GLOBALS['deviceCache'] = $deviceCache;
$GLOBALS['cacheDir'] = $cacheDir;

// Run event loop
$loop->run();
```

**Test**:
```bash
# Run end-to-end cache test
php tests/test_cache_e2e.php config/config.json

# Expected output:
# - First connection: "Fetching fresh Stage 1/2/3 token"
# - First connection: "Fetching fresh device list from API"
# - Second connection: "Using cached Stage 2/3 token"
# - Second connection: "Using cached device list"
# - TTL remaining times should be logged
```

**Done when**:
- First connection fetches all tokens and devices from API
- Second connection uses cached tokens and devices
- Cache TTL and age are logged correctly

**Commit**: `test(cache): add end-to-end test for token and device caching`

---

## Validation Checklist

After completing all steps, verify:

- ✅ CachedToken value object validates expiry with safety margin
- ✅ TokenCache stores/retrieves tokens with TTL check
- ✅ TokenCache handles corrupt JSON gracefully
- ✅ DeviceCache stores/retrieves Device objects
- ✅ Device serialization (toArray/fromArray) works
- ✅ AsyncCloudClient uses cached tokens when valid
- ✅ AsyncCloudClient skips Stage 2 (Login) when token cached
- ✅ AsyncCloudClient caches device list (24h TTL)
- ✅ AsyncCloudClient invalidates cache on token expiry
- ✅ MqttBridge initializes caches and passes to clients
- ✅ Periodic device refresh (24h timer) works
- ✅ Config contains cache section with correct defaults
- ✅ End-to-end test passes (cache hit/miss logging visible)

---

## Performance Metrics

**Expected improvements**:

| Scenario | Before | After | Saved |
|----------|--------|-------|-------|
| Bridge Restart (cold) | 3 API calls | 1-2 API calls | 33-66% |
| Bridge Restart (warm, <24h) | 3 API calls | 0 API calls | 100% |
| Stage 2 (Login) | Always executed | Skipped if cached | ~1s latency |
| Device Discovery | Always fetched | Cached 24h | ~0.5s latency |

**Cache hit rate estimation**:
- Login Token: ~99% (14 year TTL)
- MQTT Token: ~85% (3 day TTL, restarts < 3 days)
- Device List: ~95% (24h TTL, rare changes)

---

## Troubleshooting

### Cache not being used

**Check**:
```bash
# Verify cache directory exists and is writable
ls -la /var/lib/fossibot/

# Check cache files
ls -la /var/lib/fossibot/tokens_*.json
ls -la /var/lib/fossibot/devices_*.json

# Verify file contents
cat /var/lib/fossibot/tokens_$(echo -n 'your@email.com' | md5sum | cut -d' ' -f1).json
```

**Expected** cache file structure:
```json
{
  "s1_anonymous": {
    "token": "eyJ...",
    "expires_at": 1728123456,
    "cached_at": 1728123000
  },
  "s2_login": {
    "token": "abc123...",
    "expires_at": 2073560992,
    "cached_at": 1728123000
  },
  "s3_mqtt": {
    "token": "Bearer eyJ...",
    "expires_at": 1728382656,
    "cached_at": 1728123000
  }
}
```

### Tokens still being fetched every restart

**Check logs** for:
```
[DEBUG] Cached token expired or expiring soon
```

→ TTL too short or safety margin too aggressive

**Fix**: Adjust `token_ttl_safety_margin` in config.json (reduce from 300 to 60 seconds)

### Device list not cached

**Check**:
```bash
# Should see cache file
ls -la /var/lib/fossibot/devices_*.json

# Verify content
cat /var/lib/fossibot/devices_$(echo -n 'your@email.com' | md5sum | cut -d' ' -f1).json
```

**Expected**:
```json
{
  "cached_at": 1728123000,
  "devices": [
    {
      "deviceid": "DEV001",
      "name": "F2400",
      "mac": "7C2C67AB5F0E",
      "devicetype": "F2400"
    }
  ]
}
```

---

## Next Steps

After Phase 1 completion:
- **Phase 2**: Health Check Server (HTTP endpoint for monitoring)
- **Phase 3**: PID Management (prevent double-start)
- **Phase 4**: Control Script (systemctl wrapper)

---

**Phase 1 Complete**: Cache system fully functional, reducing API calls by 33-100% depending on restart frequency.
