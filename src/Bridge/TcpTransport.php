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
 * Establishes a non-blocking TCP connection using ReactPHP's Connector.
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

    /**
     * Establish TCP socket connection.
     *
     * @return PromiseInterface<ConnectionInterface> Resolves with TCP connection
     */
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
