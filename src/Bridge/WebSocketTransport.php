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
 * Establishes a WebSocket connection with MQTT subprotocol support.
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

    /**
     * Establish WebSocket connection with MQTT subprotocol.
     *
     * @return PromiseInterface<ConnectionInterface> Resolves with WebSocket connection
     */
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
