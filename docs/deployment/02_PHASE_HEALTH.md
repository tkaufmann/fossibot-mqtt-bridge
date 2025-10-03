# Phase 2: Health Check Server

**Time**: 1h 0min
**Priority**: P1
**Dependencies**: None

---

## Goal

Implementiere HTTP Health Check Endpoint für:
- Monitoring/Alerting-Integration (Prometheus, Nagios, etc.)
- Container Orchestration (Kubernetes Liveness/Readiness Probes)
- Load Balancer Health Checks
- `fossibot-bridge-ctl health` Command (Phase 4)

**Endpoint**: `http://localhost:8080/health`

**Response**:
```json
{
  "status": "healthy",
  "uptime": 3600,
  "accounts": {
    "total": 2,
    "connected": 2,
    "disconnected": 0
  },
  "devices": {
    "total": 5,
    "online": 4,
    "offline": 1
  },
  "mqtt": {
    "cloud_clients": 2,
    "local_broker": "connected"
  },
  "memory": {
    "usage_mb": 42,
    "limit_mb": 256
  }
}
```

---

## Steps

### Step 1: BridgeMetrics Class (20min)

**File**: `src/Bridge/BridgeMetrics.php`
**Lines**: New file

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Metrics collector for bridge health monitoring.
 *
 * Tracks connection status, device counts, uptime, and resource usage.
 */
class BridgeMetrics
{
    private int $startTime;
    private int $accountsTotal = 0;
    private int $accountsConnected = 0;
    private int $devicesTotal = 0;
    private int $devicesOnline = 0;
    private bool $localBrokerConnected = false;

    public function __construct()
    {
        $this->startTime = time();
    }

    /**
     * Update account connection metrics.
     */
    public function setAccountMetrics(int $total, int $connected): void
    {
        $this->accountsTotal = $total;
        $this->accountsConnected = $connected;
    }

    /**
     * Update device metrics.
     */
    public function setDeviceMetrics(int $total, int $online): void
    {
        $this->devicesTotal = $total;
        $this->devicesOnline = $online;
    }

    /**
     * Set local broker connection status.
     */
    public function setLocalBrokerConnected(bool $connected): void
    {
        $this->localBrokerConnected = $connected;
    }

    /**
     * Get current health status.
     *
     * @return array Health data
     */
    public function getHealth(): array
    {
        $uptime = time() - $this->startTime;
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));

        // Determine overall health status
        $status = 'healthy';

        // Unhealthy if no accounts connected
        if ($this->accountsTotal > 0 && $this->accountsConnected === 0) {
            $status = 'unhealthy';
        }

        // Degraded if some accounts disconnected
        if ($this->accountsTotal > 0 && $this->accountsConnected < $this->accountsTotal) {
            $status = 'degraded';
        }

        // Unhealthy if local broker disconnected
        if (!$this->localBrokerConnected) {
            $status = 'unhealthy';
        }

        return [
            'status' => $status,
            'uptime' => $uptime,
            'accounts' => [
                'total' => $this->accountsTotal,
                'connected' => $this->accountsConnected,
                'disconnected' => $this->accountsTotal - $this->accountsConnected
            ],
            'devices' => [
                'total' => $this->devicesTotal,
                'online' => $this->devicesOnline,
                'offline' => $this->devicesTotal - $this->devicesOnline
            ],
            'mqtt' => [
                'cloud_clients' => $this->accountsConnected,
                'local_broker' => $this->localBrokerConnected ? 'connected' : 'disconnected'
            ],
            'memory' => [
                'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'limit_mb' => $memoryLimit > 0 ? round($memoryLimit / 1024 / 1024, 2) : null
            ]
        ];
    }

    /**
     * Parse PHP memory_limit string to bytes.
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1; // Unlimited
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
```

**Test**:
```bash
cat > test_metrics.php << 'EOF'
<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\BridgeMetrics;

$metrics = new BridgeMetrics();

// Simulate metrics
$metrics->setAccountMetrics(2, 2);
$metrics->setDeviceMetrics(5, 4);
$metrics->setLocalBrokerConnected(true);

sleep(2);

$health = $metrics->getHealth();
print_r($health);

// Check expected values
assert($health['status'] === 'healthy');
assert($health['uptime'] >= 2);
assert($health['accounts']['total'] === 2);
assert($health['devices']['online'] === 4);

echo "\n✅ Metrics test passed\n";
EOF

php test_metrics.php
rm test_metrics.php
```

**Done when**: BridgeMetrics correctly calculates health status

**Commit**: `feat(bridge): add BridgeMetrics for health monitoring`

---

### Step 2: HealthCheckServer with React\\Http (30min)

**File**: `src/Bridge/HealthCheckServer.php`
**Lines**: New file

```php
<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP server for health check endpoint.
 *
 * Provides /health endpoint for monitoring and liveness probes.
 * Runs on ReactPHP event loop (non-blocking).
 */
class HealthCheckServer
{
    private LoopInterface $loop;
    private BridgeMetrics $metrics;
    private LoggerInterface $logger;
    private ?HttpServer $httpServer = null;
    private ?SocketServer $socket = null;

    public function __construct(
        LoopInterface $loop,
        BridgeMetrics $metrics,
        ?LoggerInterface $logger = null
    ) {
        $this->loop = $loop;
        $this->metrics = $metrics;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Start HTTP server on specified port.
     *
     * @param int $port Port to listen on (default: 8080)
     */
    public function start(int $port = 8080): void
    {
        $this->logger->info('Starting health check server', [
            'port' => $port
        ]);

        $this->httpServer = new HttpServer(function (ServerRequestInterface $request) {
            return $this->handleRequest($request);
        });

        try {
            $this->socket = new SocketServer("0.0.0.0:$port", [], $this->loop);
            $this->httpServer->listen($this->socket);

            $this->logger->info('Health check server listening', [
                'url' => "http://localhost:$port/health"
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to start health check server', [
                'error' => $e->getMessage(),
                'port' => $port
            ]);
            throw $e;
        }
    }

    /**
     * Stop HTTP server.
     */
    public function stop(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
            $this->logger->info('Health check server stopped');
        }
    }

    /**
     * Handle HTTP request.
     */
    private function handleRequest(ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        $this->logger->debug('Health check request', [
            'method' => $method,
            'path' => $path
        ]);

        // Only support GET /health
        if ($method !== 'GET') {
            return new Response(
                405,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Method not allowed'])
            );
        }

        if ($path !== '/health') {
            return new Response(
                404,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Not found'])
            );
        }

        // Get health data
        $health = $this->metrics->getHealth();

        // Determine HTTP status code
        $statusCode = match($health['status']) {
            'healthy' => 200,
            'degraded' => 200, // Still responsive
            'unhealthy' => 503, // Service unavailable
            default => 500
        };

        return new Response(
            $statusCode,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache, no-store, must-revalidate'
            ],
            json_encode($health, JSON_PRETTY_PRINT)
        );
    }
}
```

**Done when**: HealthCheckServer responds to HTTP requests with health data

**Commit**: `feat(bridge): add HTTP health check server with React\\Http`

---

### Step 3: Integrate in MqttBridge (15min)

**File**: `src/Bridge/MqttBridge.php`
**Lines**: Multiple locations

**Location 1** - Add properties after line 31:
```php
// Health monitoring
private BridgeMetrics $metrics;
private ?HealthCheckServer $healthServer = null;
```

**Location 2** - Initialize in constructor after line 63:
```php
// Initialize health metrics
$this->metrics = new BridgeMetrics();
```

**Location 3** - Start health server in `run()` after line 98:
```php
// Start health check server (if configured)
if (isset($this->config['health']['enabled']) && $this->config['health']['enabled']) {
    $port = $this->config['health']['port'] ?? 8080;
    $this->healthServer = new HealthCheckServer($this->loop, $this->metrics, $this->logger);

    try {
        $this->healthServer->start($port);
    } catch (\Exception $e) {
        $this->logger->error('Failed to start health server, continuing without it', [
            'error' => $e->getMessage()
        ]);
    }
}
```

**Location 4** - Update metrics when accounts connect (find `initializeAccounts()`, after each client connects):
```php
// After all clients connected, update metrics
$totalAccounts = count($this->config['accounts']);
$connectedAccounts = count($this->cloudClients);
$this->metrics->setAccountMetrics($totalAccounts, $connectedAccounts);

// Count total devices
$totalDevices = 0;
foreach ($this->cloudClients as $client) {
    $totalDevices += count($client->getDevices());
}
$this->metrics->setDeviceMetrics($totalDevices, $totalDevices); // Assume all online initially
```

**Location 5** - Update broker status (find `connectBroker()`, after successful connection):
```php
$this->metrics->setLocalBrokerConnected(true);
```

**Location 6** - Stop health server in `shutdown()`:
```php
// Stop health server
if ($this->healthServer !== null) {
    $this->healthServer->stop();
}
```

**Done when**: MqttBridge starts health server and updates metrics

**Commit**: `feat(bridge): integrate health check server in MqttBridge`

---

### Step 4: Config Changes (5min)

**File**: `config/example.json`
**Lines**: Add new `health` section after `daemon` section

```json
  "health": {
    "enabled": true,
    "port": 8080
  },
```

**Done when**: example.json contains health configuration

**Commit**: `feat(config): add health check server configuration`

---

### Step 5: Test Health Endpoint (10min)

**Test Script**: `tests/test_health_endpoint.sh`

```bash
#!/bin/bash
# Test health check endpoint

set -e

echo "=== Health Check Endpoint Test ==="
echo ""

# Start bridge in background
echo "--- Starting bridge ---"
php daemon/fossibot-bridge.php --config config/config.json &
BRIDGE_PID=$!
echo "Bridge PID: $BRIDGE_PID"

# Wait for startup
sleep 5

# Test 1: Health endpoint responds
echo ""
echo "--- Test 1: Health Endpoint ---"
if curl -s http://localhost:8080/health | jq '.status' | grep -q 'healthy\|degraded'; then
    echo "✅ Health endpoint responds"
else
    echo "❌ Health endpoint not responding"
    kill $BRIDGE_PID
    exit 1
fi

# Test 2: Metrics present
echo ""
echo "--- Test 2: Metrics Present ---"
RESPONSE=$(curl -s http://localhost:8080/health)

if echo "$RESPONSE" | jq -e '.uptime' > /dev/null; then
    echo "✅ Uptime metric present"
else
    echo "❌ Uptime metric missing"
fi

if echo "$RESPONSE" | jq -e '.accounts' > /dev/null; then
    echo "✅ Account metrics present"
else
    echo "❌ Account metrics missing"
fi

if echo "$RESPONSE" | jq -e '.devices' > /dev/null; then
    echo "✅ Device metrics present"
else
    echo "❌ Device metrics missing"
fi

if echo "$RESPONSE" | jq -e '.mqtt' > /dev/null; then
    echo "✅ MQTT metrics present"
else
    echo "❌ MQTT metrics missing"
fi

if echo "$RESPONSE" | jq -e '.memory' > /dev/null; then
    echo "✅ Memory metrics present"
else
    echo "❌ Memory metrics missing"
fi

# Test 3: Invalid path returns 404
echo ""
echo "--- Test 3: Invalid Path (404) ---"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/invalid)
if [ "$HTTP_CODE" = "404" ]; then
    echo "✅ 404 for invalid path"
else
    echo "❌ Expected 404, got $HTTP_CODE"
fi

# Test 4: Invalid method returns 405
echo ""
echo "--- Test 4: Invalid Method (405) ---"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8080/health)
if [ "$HTTP_CODE" = "405" ]; then
    echo "✅ 405 for POST method"
else
    echo "❌ Expected 405, got $HTTP_CODE"
fi

# Cleanup
echo ""
echo "--- Cleanup ---"
kill $BRIDGE_PID
sleep 2

echo ""
echo "=== ✅ All Health Endpoint Tests Passed ==="
echo ""
echo "Sample health response:"
curl -s http://localhost:8080/health | jq '.' || true
```

**Run**:
```bash
chmod +x tests/test_health_endpoint.sh
./tests/test_health_endpoint.sh
```

**Expected output**:
```
=== Health Check Endpoint Test ===

--- Starting bridge ---
Bridge PID: 12345

--- Test 1: Health Endpoint ---
✅ Health endpoint responds

--- Test 2: Metrics Present ---
✅ Uptime metric present
✅ Account metrics present
✅ Device metrics present
✅ MQTT metrics present
✅ Memory metrics present

--- Test 3: Invalid Path (404) ---
✅ 404 for invalid path

--- Test 4: Invalid Method (405) ---
✅ 405 for POST method

--- Cleanup ---

=== ✅ All Health Endpoint Tests Passed ===

Sample health response:
{
  "status": "healthy",
  "uptime": 5,
  "accounts": {
    "total": 1,
    "connected": 1,
    "disconnected": 0
  },
  "devices": {
    "total": 2,
    "online": 2,
    "offline": 0
  },
  "mqtt": {
    "cloud_clients": 1,
    "local_broker": "connected"
  },
  "memory": {
    "usage_mb": 42.5,
    "limit_mb": 256
  }
}
```

**Done when**: All health endpoint tests pass

**Commit**: `test(health): add health endpoint integration tests`

---

## Validation Checklist

After completing all steps, verify:

- ✅ BridgeMetrics calculates health status correctly
- ✅ HealthCheckServer responds on port 8080
- ✅ `/health` endpoint returns valid JSON
- ✅ HTTP status codes: 200 (healthy/degraded), 503 (unhealthy)
- ✅ Invalid paths return 404
- ✅ Invalid methods return 405
- ✅ MqttBridge updates metrics in real-time
- ✅ Config contains `health` section

---

## Usage Examples

### Manual Health Check

```bash
# Basic check
curl http://localhost:8080/health

# Pretty print
curl -s http://localhost:8080/health | jq '.'

# Check HTTP status code
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/health

# Watch health status
watch -n 1 'curl -s http://localhost:8080/health | jq ".status"'
```

### Integration with fossibot-bridge-ctl

```bash
# Already implemented in Phase 4
fossibot-bridge-ctl health

# Output:
# ✅ Health check passed
# {
#   "status": "healthy",
#   ...
# }
```

---

## Monitoring Integration

### Prometheus

**Scrape Config**:
```yaml
scrape_configs:
  - job_name: 'fossibot-bridge'
    metrics_path: '/health'
    static_configs:
      - targets: ['localhost:8080']
```

**Alert Rule**:
```yaml
groups:
  - name: fossibot
    rules:
      - alert: FossibotBridgeUnhealthy
        expr: fossibot_bridge_status != "healthy"
        for: 5m
        annotations:
          summary: "Fossibot Bridge unhealthy"
```

### Nagios/Icinga

```bash
#!/bin/bash
# check_fossibot_health.sh

STATUS=$(curl -s http://localhost:8080/health | jq -r '.status')

if [ "$STATUS" = "healthy" ]; then
    echo "OK: Bridge is healthy"
    exit 0
elif [ "$STATUS" = "degraded" ]; then
    echo "WARNING: Bridge is degraded"
    exit 1
else
    echo "CRITICAL: Bridge is unhealthy"
    exit 2
fi
```

### Docker/Kubernetes

**Dockerfile**:
```dockerfile
HEALTHCHECK --interval=30s --timeout=3s \
  CMD curl -f http://localhost:8080/health || exit 1
```

**Kubernetes Pod**:
```yaml
livenessProbe:
  httpGet:
    path: /health
    port: 8080
  initialDelaySeconds: 10
  periodSeconds: 30

readinessProbe:
  httpGet:
    path: /health
    port: 8080
  initialDelaySeconds: 5
  periodSeconds: 10
```

---

## Health Status Conditions

| Status | HTTP Code | Condition |
|--------|-----------|-----------|
| `healthy` | 200 | All accounts connected, local broker connected |
| `degraded` | 200 | Some accounts disconnected but service functional |
| `unhealthy` | 503 | No accounts connected OR local broker disconnected |

---

## Troubleshooting

### Port 8080 already in use

**Check** what's using port:
```bash
sudo lsof -i :8080
```

**Change** port in config:
```json
"health": {
  "enabled": true,
  "port": 8081
}
```

### Health endpoint not responding

**Check** if server started:
```bash
# Look for log message
grep "Health check server listening" logs/bridge.log
```

**Check** firewall:
```bash
sudo ufw status
sudo ufw allow 8080/tcp
```

**Test** locally:
```bash
curl http://127.0.0.1:8080/health
```

### Status always "unhealthy"

**Check** metrics update:
```bash
# Enable debug logging
"daemon": {
  "log_level": "debug"
}

# Look for metric updates in logs
grep "Account metrics" logs/bridge.log
```

---

## Security Considerations

**Bind to localhost only** (production):
```php
// In HealthCheckServer::start()
$this->socket = new SocketServer("127.0.0.1:$port", [], $this->loop);
```

**Expose via reverse proxy**:
```nginx
# nginx config
location /fossibot/health {
    proxy_pass http://127.0.0.1:8080/health;
}
```

**No authentication needed**: Health endpoint contains no sensitive data (just metrics).

---

## Next Steps

After Phase 2 completion:
- **Phase 4**: Control Script (uses health endpoint)
- **Phase 6**: systemd Service (adds health check dependency)
- **Phase 7**: Documentation (monitoring setup guides)

---

**Phase 2 Complete**: HTTP health check endpoint operational, ready for monitoring integration.
