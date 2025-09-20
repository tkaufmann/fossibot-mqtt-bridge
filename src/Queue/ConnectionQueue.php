<?php

declare(strict_types=1);

namespace Fossibot\Queue;

use Fossibot\Commands\Command;
use Fossibot\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages a queue of commands for a specific MQTT connection.
 *
 * Implements event-driven processing where commands are queued and executed
 * asynchronously. Each queue is tied to a specific MQTT connection and
 * processes commands sequentially to avoid conflicts.
 */
class ConnectionQueue
{
    private array $commandQueue = [];
    private bool $isProcessing = false;
    private LoggerInterface $logger;
    private ?Connection $connection = null;

    public function __construct(?LoggerInterface $logger = null, ?Connection $connection = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->connection = $connection;
    }

    /**
     * Set the connection for this queue.
     *
     * @param Connection $connection MQTT connection instance
     */
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Add a command to the queue for execution.
     *
     * @param string $macAddress Device MAC address without colons
     * @param Command $command Command to execute
     * @throws \InvalidArgumentException If MAC address is empty or invalid format
     */
    public function enqueue(string $macAddress, Command $command): void
    {
        if (empty($macAddress)) {
            throw new \InvalidArgumentException('MAC address cannot be empty');
        }
        if (strlen($macAddress) !== 12 || !ctype_xdigit($macAddress)) {
            throw new \InvalidArgumentException("Invalid MAC address format: '{$macAddress}'. Expected 12 hex characters without colons");
        }

        $queueItem = [
            'macAddress' => $macAddress,
            'command' => $command,
            'enqueuedAt' => microtime(true),
            'id' => uniqid('cmd_', true)
        ];

        $this->commandQueue[] = $queueItem;

        $this->logger->debug('Command enqueued', [
            'queue_size' => count($this->commandQueue),
            'mac_address' => $macAddress,
            'command_description' => $command->getDescription(),
            'command_id' => $queueItem['id']
        ]);

        // Start processing if not already running
        if (!$this->isProcessing) {
            $this->processQueue();
        }
    }

    /**
     * Process all queued commands sequentially.
     *
     * Event-driven processing - executes commands one by one
     * and removes them from the queue upon completion.
     */
    public function processQueue(): void
    {
        if ($this->isProcessing) {
            $this->logger->debug('Queue processing already in progress, skipping');
            return;
        }

        $this->isProcessing = true;
        $this->logger->debug('Starting queue processing', [
            'queue_size' => count($this->commandQueue)
        ]);

        try {
            while (!empty($this->commandQueue)) {
                $queueItem = array_shift($this->commandQueue);
                $this->executeCommand($queueItem['macAddress'], $queueItem['command'], $queueItem['id']);
            }
        } finally {
            $this->isProcessing = false;
            $this->logger->debug('Queue processing completed');
        }
    }

    /**
     * Execute a single command via MQTT.
     *
     * @param string $macAddress Device MAC address
     * @param Command $command Command to execute
     * @param string $commandId Unique command identifier for logging
     */
    private function executeCommand(string $macAddress, Command $command, string $commandId): void
    {
        $this->logger->info('Executing command', [
            'command_id' => $commandId,
            'mac_address' => $macAddress,
            'command_description' => $command->getDescription(),
            'target_register' => $command->getTargetRegister(),
            'response_type' => $command->getResponseType()->name
        ]);

        try {
            if ($this->connection !== null) {
                // Send command via real MQTT connection
                $this->connection->sendCommand($macAddress, $command);
            } else {
                // Fallback: Log the command bytes (for testing without connection)
                $bytes = $command->getModbusBytes();
                $hexBytes = implode(' ', array_map(fn($b) => sprintf('%02X', $b), $bytes));

                $this->logger->info('Command would be sent via MQTT (no connection set)', [
                    'command_id' => $commandId,
                    'topic' => $macAddress . '/client/request/data',
                    'payload_hex' => $hexBytes,
                    'payload_bytes' => $bytes
                ]);

                // Simulate processing time
                usleep(100000); // 100ms delay
            }

            $this->logger->debug('Command execution completed', [
                'command_id' => $commandId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Command execution failed', [
                'command_id' => $commandId,
                'mac_address' => $macAddress,
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
            throw $e;
        }
    }

    /**
     * Get current queue size.
     *
     * @return int Number of commands waiting in queue (0 or positive integer)
     */
    public function getQueueSize(): int
    {
        return count($this->commandQueue);
    }

    /**
     * Check if queue is currently processing commands.
     *
     * @return bool True if processing is active
     */
    public function isProcessing(): bool
    {
        return $this->isProcessing;
    }

    /**
     * Clear all pending commands from the queue.
     *
     * Removes all queued commands without executing them.
     * Logs the number of commands that were cleared.
     */
    public function clear(): void
    {
        $clearedCount = count($this->commandQueue);
        $this->commandQueue = [];

        $this->logger->info('Queue cleared', [
            'cleared_commands' => $clearedCount
        ]);
    }
}