<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use Fossibot\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ratchet\Client\Connector as WebSocketConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Async MQTT client for Fossibot Cloud connection.
 *
 * Connects to Fossibot Cloud via MQTT over WebSocket using ReactPHP.
 * Emits events for messages, connection status, and errors.
 * One instance per Fossibot account.
 *
 * This client implements event-based MQTT communication without polling,
 * integrating the WebSocket stream directly with MQTT packet handlers.
 *
 * Events:
 * - 'connect' => function()
 * - 'message' => function(string $topic, string $payload)
 * - 'disconnect' => function()
 * - 'error' => function(\Exception $e)
 */
class AsyncCloudClient extends EventEmitter
{
    private string $email;
    private string $password;
    private LoopInterface $loop;
    private LoggerInterface $logger;

    private ?Connection $connection = null;
    private ?WebSocket $websocket = null;
    private bool $connected = false;

    private array $devices = [];
    private array $subscriptions = [];

    // MQTT protocol state
    private string $mqttBuffer = '';
    private int $mqttPacketId = 1;
    private array $pendingSubscriptions = [];
    private array $activeSubscriptions = [];

    public function __construct(
        string $email,
        string $password,
        LoopInterface $loop,
        ?LoggerInterface $logger = null
    ) {
        $this->email = $email;
        $this->password = $password;
        $this->loop = $loop;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Connect to Fossibot Cloud (async).
     *
     * @return PromiseInterface Resolves when connected
     */
    public function connect(): PromiseInterface
    {
        $this->logger->info('AsyncCloudClient connecting', [
            'email' => $this->email
        ]);

        // Phase 1: Authenticate (synchronous, uses existing Connection)
        return $this->authenticate()
            ->then(function() {
                // Phase 2: Connect WebSocket
                return $this->connectWebSocket();
            })
            ->then(function() {
                // Phase 3: Setup MQTT over WebSocket (event-based)
                return $this->setupMqtt();
            })
            ->then(function() {
                // Phase 4: Discover and subscribe to devices
                return $this->discoverDevices();
            })
            ->then(function() {
                $this->connected = true;
                $this->emit('connect');
                $this->logger->info('AsyncCloudClient connected successfully');
            })
            ->otherwise(function(\Exception $e) {
                $this->logger->error('AsyncCloudClient connect failed', [
                    'error' => $e->getMessage()
                ]);
                $this->emit('error', [$e]);
                throw $e;
            });
    }

    /**
     * Disconnect from cloud (async).
     */
    public function disconnect(): PromiseInterface
    {
        $this->logger->info('AsyncCloudClient disconnecting');

        $this->connected = false;

        // Send MQTT DISCONNECT packet
        if ($this->websocket !== null) {
            $disconnectPacket = "\xe0\x00"; // DISCONNECT packet
            $frame = new \Ratchet\RFC6455\Messaging\Frame($disconnectPacket, true, \Ratchet\RFC6455\Messaging\Frame::OP_BINARY);
            $this->websocket->send($frame);
            $this->websocket->close();
            $this->websocket = null;
        }

        $this->emit('disconnect');

        return \React\Promise\resolve(null);
    }

    /**
     * Attempt reconnection (called by MqttBridge).
     *
     * @return PromiseInterface Resolves when reconnected
     */
    public function reconnect(): PromiseInterface
    {
        $this->logger->info('AsyncCloudClient attempting reconnect');

        // Disconnect cleanly first
        if ($this->connected) {
            $this->disconnect();
        }

        // Wait 1 second before reconnecting
        $deferred = new \React\Promise\Deferred();

        $this->loop->addTimer(1.0, function() use ($deferred) {
            $this->connect()->then(
                function() use ($deferred) {
                    $this->logger->info('AsyncCloudClient reconnect successful');
                    $deferred->resolve(null);
                },
                function($error) use ($deferred) {
                    $this->logger->error('AsyncCloudClient reconnect failed', [
                        'error' => $error->getMessage()
                    ]);
                    $deferred->reject($error);
                }
            );
        });

        return $deferred->promise();
    }

    /**
     * Check if client is connected.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get discovered devices.
     *
     * @return \Fossibot\Device\Device[]
     */
    public function getDevices(): array
    {
        return $this->devices;
    }

    /**
     * Subscribe to MQTT topic.
     */
    public function subscribe(string $topic): void
    {
        if (!$this->connected || $this->websocket === null) {
            throw new \RuntimeException('Cannot subscribe: not connected');
        }

        $packetId = $this->getNextPacketId();

        // Build MQTT SUBSCRIBE packet
        $payload = pack('n', $packetId); // Packet identifier
        $payload .= pack('n', strlen($topic)) . $topic; // Topic filter
        $payload .= chr(0); // QoS 0

        $packet = chr(0x82) . $this->encodeLength(strlen($payload)) . $payload;

        $this->pendingSubscriptions[$packetId] = $topic;

        // Send as Binary frame (will be automatically masked by WebSocket.php)
        $frame = new \Ratchet\RFC6455\Messaging\Frame($packet, true, \Ratchet\RFC6455\Messaging\Frame::OP_BINARY);
        $this->websocket->send($frame);

        $this->logger->debug('Subscribed to topic', ['topic' => $topic, 'packet_id' => $packetId]);
    }

    /**
     * Publish to MQTT topic.
     */
    public function publish(string $topic, string $payload, int $qos = 1): void
    {
        if (!$this->connected || $this->websocket === null) {
            throw new \RuntimeException('Cannot publish: not connected');
        }

        $packetId = $this->getNextPacketId();

        // Build MQTT PUBLISH packet (QoS 1)
        $flags = 0x30 | ($qos << 1); // PUBLISH with QoS
        $variableHeader = pack('n', strlen($topic)) . $topic;

        if ($qos > 0) {
            $variableHeader .= pack('n', $packetId); // Add packet ID for QoS > 0
        }

        $packet = chr($flags) . $this->encodeLength(strlen($variableHeader) + strlen($payload)) . $variableHeader . $payload;

        // Send as Binary frame (will be automatically masked by WebSocket.php)
        $frame = new \Ratchet\RFC6455\Messaging\Frame($packet, true, \Ratchet\RFC6455\Messaging\Frame::OP_BINARY);
        $this->websocket->send($frame);

        $this->logger->debug('Published to topic', [
            'topic' => $topic,
            'payload_length' => strlen($payload),
            'packet_id' => $packetId,
            'qos' => $qos
        ]);
    }

    // --- Private Methods ---

    private function authenticate(): PromiseInterface
    {
        // Reuse existing Connection class for 3-stage auth
        $this->connection = new Connection(
            $this->email,
            $this->password,
            $this->logger
        );

        try {
            // This is synchronous but fast (~1-2 seconds)
            $this->connection->connect();
            return \React\Promise\resolve(null);
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }
    }

    private function connectWebSocket(): PromiseInterface
    {
        $wsConnector = new WebSocketConnector($this->loop);

        // Port 8083 uses unencrypted WebSocket (ws://), not wss://
        $mqttUrl = 'ws://mqtt.sydpower.com:8083/mqtt';
        $subProtocols = ['mqtt'];

        $this->logger->debug('Connecting WebSocket', [
            'url' => $mqttUrl,
            'subprotocols' => $subProtocols
        ]);

        return $wsConnector($mqttUrl, $subProtocols)->then(
            function(WebSocket $conn) {
                $this->websocket = $conn;
                $this->logger->info('WebSocket connected with MQTT subprotocol');
                return $conn;
            },
            function(\Exception $e) {
                $this->logger->error('WebSocket connection failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        );
    }

    private function setupMqtt(): PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();

        // Get MQTT credentials
        $mqttToken = $this->connection->getMqttToken();
        $username = $mqttToken['username'] ?? '';
        $password = $mqttToken['password'] ?? '';
        $clientId = 'fossibot_async_' . uniqid();

        // Register event handlers on WebSocket stream
        $this->websocket->on('message', function($message) {
            $this->handleMqttData($message->getPayload());
        });

        $this->websocket->on('close', function() {
            $this->logger->warning('WebSocket closed');
            $this->connected = false;
            $this->emit('disconnect');
        });

        $this->websocket->on('error', function(\Exception $e) {
            $this->logger->error('WebSocket error', ['error' => $e->getMessage()]);
            $this->emit('error', [$e]);
        });

        // Build and send MQTT CONNECT packet
        $connectPacket = $this->buildConnectPacket($clientId, $username, $password);

        $this->logger->debug('MQTT CONNECT packet built', [
            'packet_length' => strlen($connectPacket),
            'hex' => bin2hex($connectPacket),
            'client_id' => $clientId,
            'username_length' => strlen($username),
            'password_length' => strlen($password)
        ]);

        // Create Frame with Binary opcode and send it
        // WebSocket.php send() will automatically call maskPayload() on the Frame
        $frame = new \Ratchet\RFC6455\Messaging\Frame(
            $connectPacket,
            true, // final frame
            \Ratchet\RFC6455\Messaging\Frame::OP_BINARY
        );

        $this->websocket->send($frame);

        $this->logger->debug('Sent MQTT CONNECT packet to WebSocket as Binary frame');

        // Wait for CONNACK (handled in handleMqttData)
        // We'll resolve the promise when CONNACK is received
        $this->once('mqtt_connack', function($returnCode) use ($deferred) {
            if ($returnCode === 0) {
                $this->connected = true; // MQTT is now connected
                $this->logger->info('MQTT connection accepted');
                $deferred->resolve(null);
            } else {
                $error = new \RuntimeException("MQTT connection refused: code $returnCode");
                $this->logger->error('MQTT connection refused', ['code' => $returnCode]);
                $deferred->reject($error);
            }
        });

        return $deferred->promise();
    }

    private function discoverDevices(): PromiseInterface
    {
        try {
            $this->devices = $this->connection->getDevices();

            $this->logger->info('Devices discovered', [
                'count' => count($this->devices)
            ]);

            // Subscribe to device topics
            foreach ($this->devices as $device) {
                $mac = $device->getMqttId();
                // Subscribe to all response topics:
                // - {mac}/device/response/client/+ (catches /04 and /data)
                // - {mac}/device/response/state
                $this->subscribe("$mac/device/response/client/+");
                $this->subscribe("$mac/device/response/state");
            }

            return \React\Promise\resolve(null);
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }
    }

    /**
     * Handle incoming MQTT data from WebSocket (event-driven).
     */
    private function handleMqttData(string $data): void
    {
        $this->mqttBuffer .= $data;

        while (strlen($this->mqttBuffer) > 0) {
            // Parse MQTT packet type
            $byte = ord($this->mqttBuffer[0]);
            $packetType = ($byte >> 4) & 0x0F;

            // Decode remaining length
            $lengthData = $this->decodeLength(substr($this->mqttBuffer, 1));
            if ($lengthData === null) {
                // Not enough data yet, wait for more
                return;
            }

            [$remainingLength, $lengthBytes] = $lengthData;
            $totalPacketLength = 1 + $lengthBytes + $remainingLength;

            if (strlen($this->mqttBuffer) < $totalPacketLength) {
                // Packet incomplete, wait for more data
                return;
            }

            // Extract complete packet
            $packet = substr($this->mqttBuffer, 0, $totalPacketLength);
            $this->mqttBuffer = substr($this->mqttBuffer, $totalPacketLength);

            // Process packet
            $this->processMqttPacket($packetType, $packet);
        }
    }

    private function processMqttPacket(int $packetType, string $packet): void
    {
        switch ($packetType) {
            case 2: // CONNACK
                $returnCode = ord($packet[3]);
                $this->emit('mqtt_connack', [$returnCode]);
                break;

            case 3: // PUBLISH
                $this->handlePublishPacket($packet);
                break;

            case 4: // PUBACK
                $packetId = unpack('n', substr($packet, 2, 2))[1];
                $this->logger->debug('Received PUBACK', ['packet_id' => $packetId]);
                break;

            case 9: // SUBACK
                $packetId = unpack('n', substr($packet, 2, 2))[1];
                if (isset($this->pendingSubscriptions[$packetId])) {
                    $topic = $this->pendingSubscriptions[$packetId];
                    $this->activeSubscriptions[] = $topic;
                    unset($this->pendingSubscriptions[$packetId]);
                    $this->logger->debug('Subscription confirmed', ['topic' => $topic]);
                }
                break;

            case 13: // PINGRESP
                $this->logger->debug('Received PINGRESP');
                break;

            default:
                $this->logger->warning('Unknown MQTT packet type', ['type' => $packetType]);
        }
    }

    private function handlePublishPacket(string $packet): void
    {
        $pos = 1;

        // Skip remaining length
        while (ord($packet[$pos]) & 0x80) {
            $pos++;
        }
        $pos++;

        // Extract topic
        $topicLength = unpack('n', substr($packet, $pos, 2))[1];
        $pos += 2;
        $topic = substr($packet, $pos, $topicLength);
        $pos += $topicLength;

        // Extract QoS from flags
        $flags = ord($packet[0]);
        $qos = ($flags >> 1) & 0x03;

        // Skip packet ID if QoS > 0
        if ($qos > 0) {
            $pos += 2;
        }

        // Extract payload
        $payload = substr($packet, $pos);

        $this->logger->debug('Message received', [
            'topic' => $topic,
            'payload_length' => strlen($payload)
        ]);

        $this->emit('message', [$topic, $payload]);
    }

    private function buildConnectPacket(string $clientId, string $username, string $password): string
    {
        // MQTT 3.1.1 CONNECT packet
        $protocolName = 'MQTT';
        $protocolLevel = 4; // MQTT 3.1.1
        $connectFlags = 0xC2; // Clean session + username + password
        $keepAlive = 30; // Must be 30 seconds per SYSTEM.md

        $variableHeader = pack('n', strlen($protocolName)) . $protocolName;
        $variableHeader .= chr($protocolLevel);
        $variableHeader .= chr($connectFlags);
        $variableHeader .= pack('n', $keepAlive);

        $payload = pack('n', strlen($clientId)) . $clientId;
        $payload .= pack('n', strlen($username)) . $username;
        $payload .= pack('n', strlen($password)) . $password;

        $remainingLength = strlen($variableHeader) + strlen($payload);

        return chr(0x10) . $this->encodeLength($remainingLength) . $variableHeader . $payload;
    }

    private function encodeLength(int $length): string
    {
        $encoded = '';
        do {
            $byte = $length % 128;
            $length = (int)($length / 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $encoded .= chr($byte);
        } while ($length > 0);

        return $encoded;
    }

    private function decodeLength(string $data): ?array
    {
        $multiplier = 1;
        $value = 0;
        $index = 0;

        do {
            if (!isset($data[$index])) {
                return null; // Not enough data
            }

            $byte = ord($data[$index]);
            $value += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
            $index++;

            if ($multiplier > 128 * 128 * 128) {
                throw new \RuntimeException('Malformed remaining length');
            }
        } while (($byte & 0x80) !== 0);

        return [$value, $index];
    }

    private function getNextPacketId(): int
    {
        $id = $this->mqttPacketId++;
        if ($this->mqttPacketId > 65535) {
            $this->mqttPacketId = 1;
        }
        return $id;
    }
}