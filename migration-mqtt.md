# Migration: Vom `MqttWebSocketClient` zum universellen `AsyncMqttClient`

## Kontext und Hintergrund

### Das Problem
Die Bridge verwendet aktuell zwei verschiedene MQTT-Client-Implementierungen:
1. **Cloud-Verbindung**: `MqttWebSocketClient` (ReactPHP, non-blocking, WebSocket)
2. **Lokaler Broker**: `php-mqtt/client` (blocking, TCP)

Der blocking Client für den lokalen Broker blockiert die ReactPHP Event Loop, wodurch Timer (Polling, Status-Publishing) nicht feuern können.

### Die Lösung
Wir bauen den `MqttWebSocketClient` zu einem universellen `AsyncMqttClient` um, der beide Verbindungstypen (WebSocket + TCP) non-blocking unterstützt.

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

### ✅ Schritt 1.5: MqttWebSocketClient → AsyncMqttClient umbenennen

**Aktionen:**
1. Datei umbenennen: `src/Bridge/MqttWebSocketClient.php` → `src/Bridge/AsyncMqttClient.php`
2. Klassenname ändern: `class MqttWebSocketClient` → `class AsyncMqttClient`
3. PHPDoc aktualisieren

**Status:** [ ] Todo

---

### ✅ Schritt 1.6: AsyncMqttClient refactoren - Transport Strategy Pattern

**Änderungen in `src/Bridge/AsyncMqttClient.php`:**

#### Constructor anpassen:

```php
public function __construct(
    private readonly MqttTransport $transport,
    private readonly LoopInterface $loop,
    private readonly LoggerInterface $logger,
    // ... andere MQTT-spezifische Parameter
) {
}
```

#### Connection-Methode anpassen:

**Vorher:**
```php
public function connect(): PromiseInterface
{
    // Direkte WebSocket-Logik hier...
    $connector = new Connector($this->loop);
    return $connector($url, ['mqtt'])...
}
```

**Nachher:**
```php
public function connect(): PromiseInterface
{
    // Delegiere an Transport-Strategy
    return $this->transport->connect()
        ->then(function (ConnectionInterface $connection) {
            $this->connection = $connection;
            // ... restliche MQTT-Setup-Logik
        });
}
```

**Status:** [ ] Todo

---

### ✅ Schritt 1.7: Authentifizierungs-Logik aufspalten

**Problem:** Die 3-stufige HTTP-Authentifizierung wird nur für Cloud (WebSocket) benötigt, nicht für lokalen Broker (TCP).

**Lösung:** Authentifizierung als optionalen Parameter oder separate Methode:

```php
private ?callable $authenticator = null;

public function setAuthenticator(callable $authenticator): void
{
    $this->authenticator = $authenticator;
}

public function connect(): PromiseInterface
{
    $promise = $this->authenticator !== null
        ? ($this->authenticator)()  // HTTP Auth für Cloud
        : \React\Promise\resolve(); // Keine Auth für lokalen Broker

    return $promise->then(fn() => $this->transport->connect())
        ->then(fn($conn) => $this->setupMqttConnection($conn));
}
```

**Status:** [ ] Todo

---

## Phase 2: Integration und Testen

### ✅ Schritt 2.1: Regressionstest - Cloud-Verbindung

**Ziel:** Sicherstellen, dass nichts kaputtgegangen ist.

**Änderungen in `src/Bridge/MqttBridge.php`:**

```php
// VORHER:
use Fossibot\Bridge\MqttWebSocketClient;

$client = new MqttWebSocketClient(
    $this->loop,
    $account['email'],
    $account['password'],
    $this->logger
);

// NACHHER:
use Fossibot\Bridge\AsyncMqttClient;
use Fossibot\Bridge\WebSocketTransport;

$transport = new WebSocketTransport(
    $this->loop,
    'ws://mqtt.sydpower.com:8083/mqtt',
    ['mqtt'],
    $this->logger
);

$client = new AsyncMqttClient(
    $transport,
    $this->loop,
    $this->logger
    // ... andere Parameter
);

// Authentifizierung separat setzen
$client->setAuthenticator(fn() => $this->authenticateCloud($email, $password));
```

**Test:**
```bash
./start-debug-bridge.sh
# Erwartung: Cloud-Verbindung funktioniert wie vorher
# Polling läuft weiter
# Keine Errors
```

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
