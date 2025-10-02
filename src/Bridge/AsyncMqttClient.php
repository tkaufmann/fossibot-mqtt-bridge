<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
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

    // Connection parameters
    private string $clientId;
    private ?string $username;
    private ?string $password;
    private int $keepAlive;

    public function __construct(
        private readonly MqttTransport $transport,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        string $clientId = '',
        ?string $username = null,
        ?string $password = null,
        int $keepAlive = 30
    ) {
        $this->clientId = $clientId ?: 'mqtt_client_' . uniqid();
        $this->username = $username;
        $this->password = $password;
        $this->keepAlive = $keepAlive;
    }

    /**
     * Connect to MQTT broker.
     *
     * @return PromiseInterface Resolves when connected
     */
    public function connect(): PromiseInterface
    {
        $this->logger->debug('AsyncMqttClient connecting', [
            'client_id' => $this->clientId,
            'has_auth' => $this->username !== null
        ]);

        return $this->transport->connect()
            ->then(function (ConnectionInterface $connection) {
                $this->connection = $connection;
                $this->setupConnectionHandlers();
                return $this->performMqttHandshake();
            })
            ->then(function () {
                $this->connected = true;
                $this->logger->info('AsyncMqttClient connected successfully');
                $this->emit('connect');
            });
    }

    /**
     * Disconnect from MQTT broker.
     *
     * @return PromiseInterface Resolves when disconnected
     */
    public function disconnect(): PromiseInterface
    {
        $this->logger->info('AsyncMqttClient disconnecting');

        $this->connected = false;

        if ($this->connection !== null) {
            // Send MQTT DISCONNECT packet
            $disconnectPacket = "\xe0\x00";
            $this->connection->write($disconnectPacket);
            $this->connection->close();
            $this->connection = null;
        }

        $this->emit('disconnect');

        return \React\Promise\resolve(null);
    }

    /**
     * Check if client is connected.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Publish to MQTT topic.
     */
    public function publish(string $topic, string $payload, int $qos = 0): PromiseInterface
    {
        if (!$this->connected || $this->connection === null) {
            return \React\Promise\reject(
                new \RuntimeException('Cannot publish: not connected')
            );
        }

        $packetId = $qos > 0 ? $this->getNextPacketId() : 0;

        // Build MQTT PUBLISH packet
        $flags = 0x30 | ($qos << 1);
        $variableHeader = pack('n', strlen($topic)) . $topic;

        if ($qos > 0) {
            $variableHeader .= pack('n', $packetId);
        }

        $packet = chr($flags) . $this->encodeLength(strlen($variableHeader) + strlen($payload)) . $variableHeader . $payload;

        $this->connection->write($packet);

        $this->logger->debug('Published to topic', [
            'topic' => $topic,
            'payload_length' => strlen($payload),
            'packet_id' => $packetId,
            'qos' => $qos
        ]);

        return \React\Promise\resolve(null);
    }

    /**
     * Subscribe to MQTT topic.
     */
    public function subscribe(string $topic, int $qos = 0): PromiseInterface
    {
        if (!$this->connected || $this->connection === null) {
            return \React\Promise\reject(
                new \RuntimeException('Cannot subscribe: not connected')
            );
        }

        $packetId = $this->getNextPacketId();

        // Build MQTT SUBSCRIBE packet
        $payload = pack('n', $packetId);
        $payload .= pack('n', strlen($topic)) . $topic;
        $payload .= chr($qos);

        $packet = chr(0x82) . $this->encodeLength(strlen($payload)) . $payload;

        $this->pendingSubscriptions[$packetId] = $topic;

        $this->connection->write($packet);

        $this->logger->debug('Subscribed to topic', [
            'topic' => $topic,
            'packet_id' => $packetId,
            'qos' => $qos
        ]);

        return \React\Promise\resolve(null);
    }

    // --- Private Methods ---

    private function setupConnectionHandlers(): void
    {
        $this->connection->on('data', function ($data) {
            $this->handleMqttData($data);
        });

        $this->connection->on('close', function () {
            $this->logger->warning('MQTT connection closed');
            $this->connected = false;
            $this->emit('disconnect');
        });

        $this->connection->on('error', function (\Exception $e) {
            $this->logger->error('MQTT connection error', [
                'error' => $e->getMessage()
            ]);
            $this->emit('error', [$e]);
        });
    }

    private function performMqttHandshake(): PromiseInterface
    {
        $deferred = new Deferred();

        // Build and send MQTT CONNECT packet
        $connectPacket = $this->buildConnectPacket();

        $this->logger->debug('Sending MQTT CONNECT packet', [
            'packet_length' => strlen($connectPacket),
            'client_id' => $this->clientId
        ]);

        $this->connection->write($connectPacket);

        // Wait for CONNACK
        $this->once('mqtt_connack', function ($returnCode) use ($deferred) {
            if ($returnCode === 0) {
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

    /**
     * Handle incoming MQTT data (event-driven).
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

    private function buildConnectPacket(): string
    {
        // MQTT 3.1.1 CONNECT packet
        $protocolName = 'MQTT';
        $protocolLevel = 4; // MQTT 3.1.1

        // Connect flags
        $connectFlags = 0x02; // Clean session
        if ($this->username !== null) {
            $connectFlags |= 0x80; // Username flag
        }
        if ($this->password !== null) {
            $connectFlags |= 0x40; // Password flag
        }

        $variableHeader = pack('n', strlen($protocolName)) . $protocolName;
        $variableHeader .= chr($protocolLevel);
        $variableHeader .= chr($connectFlags);
        $variableHeader .= pack('n', $this->keepAlive);

        $payload = pack('n', strlen($this->clientId)) . $this->clientId;

        if ($this->username !== null) {
            $payload .= pack('n', strlen($this->username)) . $this->username;
        }

        if ($this->password !== null) {
            $payload .= pack('n', strlen($this->password)) . $this->password;
        }

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
