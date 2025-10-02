# Migration: Generischer AsyncMqttClient mit Transport Strategy Pattern

## Kontext und Hintergrund

### Das Problem
Die Bridge verwendet aktuell zwei verschiedene MQTT-Client-Implementierungen:
1. **Cloud-Verbindung**: `AsyncCloudClient` (ReactPHP, non-blocking, WebSocket, Fossibot-spezifisch)
2. **Lokaler Broker**: `php-mqtt/client` (blocking, TCP)

Der blocking Client für den lokalen Broker blockiert die ReactPHP Event Loop, wodurch Timer (Polling, Status-Publishing) nicht feuern können.

### Die Lösung
Wir erstellen einen neuen generischen `AsyncMqttClient`, der transport-agnostisch ist. Der bestehende `AsyncCloudClient` nutzt ihn intern für die MQTT-Kommunikation, während er weiterhin die Fossibot-spezifische Logik verwaltet.

### Design-Entscheidung: Strategy Pattern
Statt die Transport-Logik direkt im Client zu implementieren, verwenden wir das **Strategy Pattern**:

**Vorteile:**
- ✅ Bessere Testbarkeit (jeder Transport isoliert testbar)
- ✅ Einfachere Erweiterung (z.B. TLS-TCP später)
- ✅ Sauberere Trennung der Verantwortlichkeiten
- ✅ AsyncMqttClient fokussiert auf MQTT-Protokoll, nicht Socket-Details

---

## Ziel

Ein vollständig non-blocking MQTT-System mit einheitlicher Architektur:

```
AsyncMqttClient (MQTT-Protokoll-Logik)
    ├─ WebSocketTransport (Cloud: mqtt.sydpower.com:8083)
    └─ TcpTransport (Lokal: localhost:1883)
```

---

## Phase 1: Refactoring des Clients (Isolation)

**Wichtig:** In dieser Phase fassen wir nur den Client an. Die MqttBridge bleibt unverändert!

### ✅ Schritt 1.1: ConnectionType Enum erstellen

**Datei:** `src/Bridge/ConnectionType.php`

```php
<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Defines the type of connection for MQTT transport.
 */
enum ConnectionType
{
    case WEBSOCKET;
    case TCP;
}
```

**Status:** [ ] Todo

---

### ✅ Schritt 1.2: MqttTransport Interface erstellen

**Datei:** `src/Bridge/MqttTransport.php`

```php
<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

/**
 * Strategy interface for MQTT transport layer.
 *
 * Implementations provide the low-level connection mechanism
 * (WebSocket, TCP, TLS-TCP, etc.) independent of the MQTT protocol.
 */
interface MqttTransport
{
    /**
     * Establish the transport connection.
     *
     * @return PromiseInterface<ConnectionInterface> Resolves with established connection
     */
    public function connect(): PromiseInterface;
}
```

**Status:** [ ] Todo

---

### ✅ Schritt 1.3: WebSocketTransport implementieren

**Datei:** `src/Bridge/WebSocketTransport.php`

Extrahiert die bisherige WebSocket-Logik aus `MqttWebSocketClient`:

```php
<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use Psr\Log\LoggerInterface;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

/**
 * WebSocket transport for MQTT over WebSocket (ws://).
 *
 * Used for Fossibot Cloud connection via mqtt.sydpower.com:8083.
 */
class WebSocketTransport implements MqttTransport
{
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly string $url,
        private readonly array $subprotocols,
        private readonly LoggerInterface $logger
    ) {
    }

    public function connect(): PromiseInterface
    {
        $this->logger->debug('Connecting WebSocket', [
            'url' => $this->url,
            'subprotocols' => $this->subprotocols,
        ]);

        $connector = new Connector($this->loop);

        return $connector($this->url, $this->subprotocols)
            ->then(function (WebSocket $connection) {
                $this->logger->info('WebSocket connected with MQTT subprotocol');
                return $connection;
            });
    }
}
```

**Status:** [ ] Todo

---

### ✅ Schritt 1.4: TcpTransport implementieren

**Datei:** `src/Bridge/TcpTransport.php`

Neue Implementierung für TCP-Verbindung zum lokalen Broker:

```php
<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/**
 * TCP transport for MQTT over raw TCP socket.
 *
 * Used for local Mosquitto broker connection via localhost:1883.
 */
class TcpTransport implements MqttTransport
{
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly string $host,
        private readonly int $port,
        private readonly LoggerInterface $logger
    ) {
    }

    public function connect(): PromiseInterface
    {
        $uri = "tcp://{$this->host}:{$this->port}";

        $this->logger->debug('Connecting TCP socket', [
            'host' => $this->host,
            'port' => $this->port,
            'uri' => $uri,
        ]);

        $connector = new Connector($this->loop);

        return $connector->connect($uri)
            ->then(function (ConnectionInterface $connection) {
                $this->logger->info('TCP socket connected', [
                    'remote_address' => $connection->getRemoteAddress(),
                ]);
                return $connection;
            });
    }
}
```

**Status:** [ ] Todo

---

### ✅ Schritt 1.5: Neuen AsyncMqttClient erstellen (MQTT-Engine)

**WICHTIGE ARCHITEKTUR-ENTSCHEIDUNG:**

Nach Analyse des bestehenden Codes haben wir erkannt, dass `AsyncCloudClient` bereits existiert und sehr Fossibot-spezifisch ist (HTTP Auth, Device Discovery, etc.).

**Statt umzubenennen, erstellen wir eine NEUE Architektur:**

```
AsyncMqttClient (NEU)
  ├─ Generischer MQTT-Protokoll-Client
  ├─ Transport-agnostisch (via MqttTransport Interface)
  └─ Keine Fossibot-spezifische Logik

AsyncCloudClient (BLEIBT)
  ├─ Fossibot-spezifische Logik (3-Stage Auth, Device Discovery)
  └─ Nutzt intern AsyncMqttClient mit WebSocketTransport
```

**Warum diese Architektur?**
- ✅ **Single Responsibility:** AsyncMqttClient = MQTT-Protokoll, AsyncCloudClient = Fossibot-Business-Logic
- ✅ **Wiederverwendbar:** AsyncMqttClient kann für beliebige MQTT-Broker genutzt werden
- ✅ **Testbar:** MQTT-Logik isoliert testbar ohne Fossibot-Dependencies
- ✅ **Wartbar:** Klare Trennung, keine `if (cloud) {...} else {...}` Code Smells

**Datei:** `src/Bridge/AsyncMqttClient.php` (NEU erstellen)

**Was extrahieren wir aus AsyncCloudClient?**
1. MQTT Packet Encoding/Decoding
2. CONNECT, PUBLISH, SUBSCRIBE, PUBACK Handling
3. Packet-ID Management
4. Message Buffer Parsing
5. Event Emission (message, connect, disconnect)

**Was NICHT übernehmen:**
- HTTP Authentication (bleibt in AsyncCloudClient)
- Device Discovery (bleibt in AsyncCloudClient)
- Token Management (bleibt in AsyncCloudClient)

**Status:** [ ] Todo

---

### ✅ Schritt 1.6: AsyncMqttClient Grundstruktur

**Datei:** `src/Bridge/AsyncMqttClient.php`

```php
<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

/**
 * Generic async MQTT client using ReactPHP.
 *
 * Transport-agnostic MQTT protocol implementation that works with
 * any MqttTransport (WebSocket, TCP, TLS, etc.).
 *
 * Events:
 * - 'connect' => function()
 * - 'message' => function(string $topic, string $payload)
 * - 'disconnect' => function()
 * - 'error' => function(\Exception $e)
 */
class AsyncMqttClient extends EventEmitter
{
    private ?ConnectionInterface $connection = null;
    private bool $connected = false;

    // MQTT Protocol State
    private string $mqttBuffer = '';
    private int $mqttPacketId = 1;
    private array $pendingSubscriptions = [];
    private array $activeSubscriptions = [];

    public function __construct(
        private readonly MqttTransport $transport,
        private readonly string $clientId,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly ?string $username = null,
        private readonly ?string $password = null
    ) {
    }

    /**
     * Connect to MQTT broker.
     *
     * @return PromiseInterface Resolves when connected
     */
    public function connect(): PromiseInterface
    {
        return $this->transport->connect()
            ->then(function (ConnectionInterface $connection) {
                $this->connection = $connection;
                return $this->performMqttHandshake();
            })
            ->then(function () {
                $this->setupConnectionHandlers();
                $this->connected = true;
                $this->emit('connect');
            });
    }

    private function performMqttHandshake(): PromiseInterface
    {
        // Send MQTT CONNECT packet
        // Wait for CONNACK
        // Return promise
    }

    private function setupConnectionHandlers(): void
    {
        // Setup data handler for incoming MQTT packets
        // Setup close handler
        // Setup error handler
    }

    public function publish(string $topic, string $payload, int $qos = 0): PromiseInterface
    {
        // Build MQTT PUBLISH packet
        // Send via connection
        // Handle QoS acknowledgment
    }

    public function subscribe(string $topic, callable $callback, int $qos = 0): PromiseInterface
    {
        // Build MQTT SUBSCRIBE packet
        // Send via connection
        // Store callback for topic
    }

    // ... weitere MQTT-Methoden
}
```

**Status:** [ ] Todo

---

### ✅ Schritt 1.7: AsyncCloudClient refactoren - Delegation an AsyncMqttClient

**Änderungen in `src/Bridge/AsyncCloudClient.php`:**

**Neue Property:**
```php
private ?AsyncMqttClient $mqttClient = null;
```

**Connect-Methode anpassen:**

**VORHER (direkte WebSocket-Logik):**
```php
public function connect(): PromiseInterface
{
    return $this->authenticate()
        ->then(fn() => $this->discoverDevices())
        ->then(fn() => $this->connectWebSocket())  // Direkt
        ->then(fn() => $this->subscribeMqttTopics());
}
```

**NACHHER (Delegation):**
```php
public function connect(): PromiseInterface
{
    return $this->authenticate()
        ->then(fn() => $this->discoverDevices())
        ->then(fn() => $this->connectMqtt())  // Delegiert!
        ->then(fn() => $this->subscribeMqttTopics());
}

private function connectMqtt(): PromiseInterface
{
    $transport = new WebSocketTransport(
        $this->loop,
        'ws://mqtt.sydpower.com:8083/mqtt',
        ['mqtt'],
        $this->logger
    );

    $this->mqttClient = new AsyncMqttClient(
        $transport,
        $this->generateClientId(),
        $this->loop,
        $this->logger,
        $this->mqttToken,  // JWT als username
        'helloyou'         // Password
    );

    return $this->mqttClient->connect();
}
```

**Alle MQTT-Methoden delegieren:**
```php
public function publish(string $topic, string $payload, int $qos = 1): void
{
    $this->mqttClient->publish($topic, $payload, $qos);
}

public function subscribe(string $topic, callable $callback, int $qos = 1): void
{
    $this->mqttClient->subscribe($topic, $callback, $qos);
}
```

**Status:** [ ] Todo

---

## Phase 2: Integration und Testen

### ✅ Schritt 2.1: Regressionstest - Cloud-Verbindung

**Ziel:** Beweisen, dass der interne Umbau des AsyncCloudClient (Delegation an AsyncMqttClient) die Funktionalität nicht verändert hat.

**WICHTIG:** Die MqttBridge muss weiterhin den `AsyncCloudClient` verwenden, nicht direkt den `AsyncMqttClient`!

**Warum?** Nur der AsyncCloudClient kennt die Fossibot-spezifische Logik (3-Stufen-HTTP-Auth, Device Discovery). Der neue AsyncMqttClient ist absichtlich generisch und kann das nicht.

**Änderungen in `src/Bridge/MqttBridge.php`:**

**KEINE Änderungen nötig!** Die MqttBridge erstellt weiterhin:

```php
$client = new AsyncCloudClient(
    $account['email'],
    $account['password'],
    $this->loop,
    $this->logger
);
```

Der Unterschied ist **intern**: AsyncCloudClient nutzt jetzt den neuen AsyncMqttClient mit WebSocketTransport, statt die MQTT-Logik direkt zu implementieren.

**Test:**
```bash
# 1. Alle Phase-1-Änderungen durchgeführt (AsyncMqttClient erstellt, AsyncCloudClient refactored)
# 2. Bridge starten OHNE Änderungen an MqttBridge
./start-debug-bridge.sh

# 3. Prüfen:
# ✅ Cloud-Verbindung funktioniert wie vorher
# ✅ Polling läuft weiter alle 30s
# ✅ Device State Updates werden empfangen
# ✅ Keine Errors in den Logs
```

**Erwartetes Verhalten:**
- Bridge startet normal
- AsyncCloudClient verbindet zur Cloud (via AsyncMqttClient intern)
- Alle bisherigen Features funktionieren unverändert

**Status:** [ ] Todo

---

### ✅ Schritt 2.2: php-mqtt/client Dependency entfernen

**Aktionen:**
1. `composer remove php-mqtt/client`
2. Alte `connectBroker()` Methode aus MqttBridge.php entfernen
3. `$this->brokerClient` Property entfernen

**Status:** [ ] Todo

---

### ✅ Schritt 2.3: Lokalen Broker-Client erstellen

**Neue Property in `src/Bridge/MqttBridge.php`:**

```php
private ?AsyncMqttClient $localBrokerClient = null;
```

**Initialisierung im Promise-Then-Block:**

```php
\React\Promise\all($this->connectionPromises)->then(
    function () {
        $this->logger->info('All accounts connected successfully, proceeding with broker connection.');

        // Lokalen Broker-Client erstellen
        $transport = new TcpTransport(
            $this->loop,
            $this->config['mosquitto']['host'],
            $this->config['mosquitto']['port'],
            $this->logger
        );

        $this->localBrokerClient = new AsyncMqttClient(
            $transport,
            $this->loop,
            $this->logger,
            $this->config['mosquitto']['client_id'] ?? 'fossibot_bridge'
        );

        // Optional: Username/Password für Mosquitto
        if (!empty($this->config['mosquitto']['username'])) {
            $this->localBrokerClient->setCredentials(
                $this->config['mosquitto']['username'],
                $this->config['mosquitto']['password']
            );
        }

        // Verbinden
        return $this->localBrokerClient->connect();
    }
)->then(function () {
    $this->logger->info('Connected to local broker');

    // Subscribe zu Command-Topics
    $this->localBrokerClient->subscribe(
        'fossibot/+/command',
        fn($topic, $payload) => $this->handleBrokerCommand($topic, $payload)
    );

    // Publish initial status
    $this->publishBridgeStatus();

    // Timer starten
    $this->startTimers();
});
```

**Status:** [ ] Todo

---

### ✅ Schritt 2.4: Alle Broker-Publishes umleiten

**Suchen & Ersetzen in `src/Bridge/MqttBridge.php`:**

```php
// VORHER:
$this->brokerClient->publish($topic, $payload, 1);

// NACHHER:
$this->localBrokerClient->publish($topic, $payload, 1);
```

**Betroffene Methoden:**
- `publishAvailability()`
- `publishBridgeStatus()`
- `publishDeviceState()` (falls vorhanden)

**Status:** [ ] Todo

---

### ✅ Schritt 2.5: Finaler Systemtest

**Test A: Cloud → Lokal (State Updates)**

```bash
# Terminal 1: Bridge starten
./start-debug-bridge.sh

# Terminal 2: Subscribe auf Device State
mosquitto_sub -t 'fossibot/7C2C67AB5F0E/state' -v
```

**Erwartung:**
- Alle 30s kommt ein State Update (durch Polling)
- State enthält SoC, Voltage, Current, etc.

**Status:** [ ] Todo

---

**Test B: Lokal → Cloud (Commands)**

```bash
# Terminal 1: Bridge läuft weiter

# Terminal 2: USB einschalten
mosquitto_pub -t 'fossibot/7C2C67AB5F0E/command' -m '{"usb_enabled": true}'

# Terminal 3: Logs beobachten
tail -f bridge-debug.log | grep -E "(command|USB)"
```

**Erwartung:**
- Bridge empfängt Command von Mosquitto
- Bridge sendet Command an Cloud
- Device reagiert (USB schaltet ein)

**Status:** [ ] Todo

---

## Erfolgs-Kriterien

- [ ] Cloud-Verbindung funktioniert (WebSocket)
- [ ] Lokaler Broker funktioniert (TCP)
- [ ] Polling-Timer feuert zuverlässig alle 30s
- [ ] State Updates werden nach Mosquitto published
- [ ] Commands von Mosquitto werden empfangen und weitergeleitet
- [ ] Keine blocking Calls mehr in der Event Loop
- [ ] Alle Tests grün

---

## Rollback-Plan

Falls etwas schiefgeht:

```bash
git checkout HEAD -- src/Bridge/
composer install
```

Die alte Implementierung ist im Git-History gesichert.

---

## Notizen

- ReactPHP `Connector` ist bereits installiert (via `react/socket`)
- Keine zusätzlichen Dependencies nötig
- Die MQTT-Protokoll-Logik (Packet-Encoding, etc.) bleibt unverändert
- Nur die Transport-Schicht wird ausgetauscht
