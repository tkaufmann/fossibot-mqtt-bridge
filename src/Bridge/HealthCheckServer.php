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
use Exception;

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
        } catch (Exception $e) {
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
                [ 'Content-Type' => 'application/json' ],
                json_encode([ 'error' => 'Method not allowed' ])
            );
        }

        if ($path !== '/health') {
            return new Response(
                404,
                [ 'Content-Type' => 'application/json' ],
                json_encode([ 'error' => 'Not found' ])
            );
        }

        // Get health data
        $health = $this->metrics->getHealth();

        // Determine HTTP status code
        $statusCode = match ($health['status']) {
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
