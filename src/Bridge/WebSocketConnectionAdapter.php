<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\Socket\ConnectionInterface;

/**
 * Adapter that wraps Ratchet WebSocket to implement ConnectionInterface.
 *
 * Translates write() calls to WebSocket send() with binary frames.
 * This allows AsyncMqttClient to work transparently with WebSocket transport.
 */
class WebSocketConnectionAdapter extends EventEmitter implements ConnectionInterface
{
    public function __construct(
        private readonly WebSocket $websocket
    ) {
        // Forward WebSocket events to ConnectionInterface events
        $this->websocket->on('message', function ($message) {
            $this->emit('data', [$message->getPayload()]);
        });

        $this->websocket->on('close', function ($code = null, $reason = null) {
            $this->emit('close');
        });

        $this->websocket->on('error', function (\Exception $e) {
            $this->emit('error', [$e]);
        });
    }

    public function write($data): bool
    {
        // Wrap MQTT data in binary WebSocket frame
        $frame = new Frame($data, true, Frame::OP_BINARY);
        $this->websocket->send($frame);
        return true;
    }

    public function end($data = null): void
    {
        if ($data !== null) {
            $this->write($data);
        }
        $this->close();
    }

    public function close(): void
    {
        $this->websocket->close();
    }

    public function pause(): void
    {
        // WebSocket doesn't support pause/resume
    }

    public function resume(): void
    {
        // WebSocket doesn't support pause/resume
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function pipe(\React\Stream\WritableStreamInterface $dest, array $options = []): \React\Stream\WritableStreamInterface
    {
        throw new \RuntimeException('Pipe not supported for WebSocket connections');
    }

    public function getRemoteAddress(): ?string
    {
        return null; // WebSocket doesn't expose remote address
    }

    public function getLocalAddress(): ?string
    {
        return null; // WebSocket doesn't expose local address
    }
}
