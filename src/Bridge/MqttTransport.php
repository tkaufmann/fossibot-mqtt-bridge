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
 *
 * The AsyncMqttClient uses this interface to establish connections
 * without needing to know the underlying transport details.
 */
interface MqttTransport
{
    /**
     * Establish the transport connection.
     *
     * @return PromiseInterface<ConnectionInterface> Promise that resolves with established connection
     */
    public function connect(): PromiseInterface;
}
