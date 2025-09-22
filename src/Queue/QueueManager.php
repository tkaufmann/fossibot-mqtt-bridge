<?php

declare(strict_types=1);

namespace Fossibot\Queue;

use Fossibot\Commands\Command;
use Fossibot\Connection;
use Fossibot\Contracts\CommandExecutor;
use Fossibot\Device\Device;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages multiple connection queues and routes commands to appropriate connections.
 *
 * Coordinates command execution across multiple MQTT connections by maintaining
 * separate queues for each connection and mapping device MAC addresses to
 * the correct connection instances.
 */
class QueueManager implements CommandExecutor
{
    private static ?QueueManager $instance = null;

    private array $connectionQueues = []; // connectionId -> ConnectionQueue
    private array $macToConnection = []; // macAddress -> Connection
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get the singleton instance of QueueManager.
     *
     * @param LoggerInterface|null $logger Optional logger (only used on first call)
     * @return QueueManager Singleton instance
     */
    public static function getInstance(?LoggerInterface $logger = null): QueueManager
    {
        if (self::$instance === null) {
            self::$instance = new self($logger);
        }

        return self::$instance;
    }

    /**
     * Reset singleton instance (mainly for testing).
     *
     * @internal This method should only be used in tests
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Register a connection with the queue manager.
     *
     * @param Connection $connection MQTT connection instance
     * @param array $macAddresses Array of MAC addresses this connection handles
     * @throws \InvalidArgumentException If MAC addresses array contains invalid entries
     */
    public function registerConnection(Connection $connection, array $macAddresses): void
    {
        if (empty($macAddresses)) {
            throw new \InvalidArgumentException('MAC addresses array cannot be empty');
        }

        // Validate MAC addresses
        foreach ($macAddresses as $index => $macAddress) {
            if (!is_string($macAddress) || empty($macAddress)) {
                throw new \InvalidArgumentException("Invalid MAC address at index {$index}: must be non-empty string");
            }
            if (strlen($macAddress) !== 12 || !ctype_xdigit($macAddress)) {
                throw new \InvalidArgumentException("Invalid MAC address format at index {$index}: '{$macAddress}'. Expected 12 hex characters without colons");
            }
        }

        $connectionId = spl_object_id($connection);

        // Create queue for this connection
        if (!isset($this->connectionQueues[$connectionId])) {
            $this->connectionQueues[$connectionId] = new ConnectionQueue($this->logger, $connection);
        }

        // Map MAC addresses to this connection
        foreach ($macAddresses as $macAddress) {
            $this->macToConnection[$macAddress] = $connection;
        }

        $this->logger->info('Connection registered with queue manager', [
            'connection_id' => $connectionId,
            'mac_addresses' => $macAddresses,
            'total_connections' => count($this->connectionQueues)
        ]);
    }

    /**
     * Execute a command for a specific device.
     *
     * Routes the command to the appropriate connection queue based on
     * the device's MAC address.
     *
     * @param string $macAddress Device MAC address without colons
     * @param Command $command Command to execute
     * @throws \RuntimeException If no connection found for MAC address
     * @throws \InvalidArgumentException If MAC address is empty (forwarded from queue)
     */
    public function executeCommand(string $macAddress, Command $command): void
    {
        $connection = $this->findConnectionForMac($macAddress);
        $queue = $this->getQueueForConnection($connection);

        $this->logger->debug('Routing command to connection queue', [
            'mac_address' => $macAddress,
            'command_description' => $command->getDescription(),
            'connection_id' => spl_object_id($connection),
            'queue_size_before' => $queue->getQueueSize()
        ]);

        $queue->enqueue($macAddress, $command);
    }

    /**
     * Execute a command on a specific device (CommandExecutor interface).
     *
     * This method implements the CommandExecutor interface and delegates
     * to the existing executeCommand method for backwards compatibility.
     *
     * @param string $deviceId Device MAC address without colons
     * @param Command $command Command to execute
     * @throws \RuntimeException If no connection found for device ID
     * @throws \InvalidArgumentException If device ID is empty or invalid format (forwarded from queue)
     */
    public function execute(string $deviceId, Command $command): void
    {
        $this->executeCommand($deviceId, $command);
    }

    /**
     * Find the connection responsible for a MAC address.
     *
     * @param string $macAddress Device MAC address
     * @return Connection Connection instance for this MAC
     * @throws \RuntimeException If no connection found
     */
    private function findConnectionForMac(string $macAddress): Connection
    {
        if (!isset($this->macToConnection[$macAddress])) {
            $availableMacs = array_keys($this->macToConnection);
            throw new \RuntimeException(
                "No connection found for MAC address: {$macAddress}. " .
                "Available MACs: [" . implode(', ', $availableMacs) . "]"
            );
        }

        return $this->macToConnection[$macAddress];
    }

    /**
     * Get the queue for a specific connection.
     *
     * @param Connection $connection Connection instance
     * @return ConnectionQueue Queue for this connection
     */
    private function getQueueForConnection(Connection $connection): ConnectionQueue
    {
        $connectionId = spl_object_id($connection);

        if (!isset($this->connectionQueues[$connectionId])) {
            $this->connectionQueues[$connectionId] = new ConnectionQueue($this->logger, $connection);
        }

        return $this->connectionQueues[$connectionId];
    }

    /**
     * Get status of all connection queues.
     *
     * @return array Status information with keys: total_connections, total_mac_mappings, queues
     */
    public function getStatus(): array
    {
        $status = [
            'total_connections' => count($this->connectionQueues),
            'total_mac_mappings' => count($this->macToConnection),
            'queues' => []
        ];

        foreach ($this->connectionQueues as $connectionId => $queue) {
            $status['queues'][$connectionId] = [
                'queue_size' => $queue->getQueueSize(),
                'is_processing' => $queue->isProcessing()
            ];
        }

        return $status;
    }

    /**
     * Clear all queues.
     *
     * Removes all pending commands from all connection queues.
     * Useful for emergency stops or cleanup operations.
     */
    public function clearAllQueues(): void
    {
        foreach ($this->connectionQueues as $queue) {
            $queue->clear();
        }

        $this->logger->info('All queues cleared');
    }

    /**
     * Get all registered MAC addresses.
     *
     * @return array List of MAC addresses (string[]) currently mapped to connections
     */
    public function getRegisteredMacs(): array
    {
        return array_keys($this->macToConnection);
    }

    /**
     * Add a new connection with automatic authentication and device discovery.
     *
     * Creates a new Connection, performs full authentication flow (stages 1-4),
     * discovers devices, and registers them with the queue manager.
     *
     * @param string $email User email for authentication
     * @param string $password User password for authentication
     * @throws \RuntimeException If connection or authentication fails
     * @throws \InvalidArgumentException If email or password is empty
     */
    public function addConnection(string $email, string $password): void
    {
        if (empty($email)) {
            throw new \InvalidArgumentException('Email cannot be empty');
        }

        if (empty($password)) {
            throw new \InvalidArgumentException('Password cannot be empty');
        }

        $this->logger->info('Adding new connection with authentication', [
            'email' => $email
        ]);

        try {
            // Create and authenticate connection
            $connection = new \Fossibot\Connection($email, $password, $this->logger);
            $connection->connect();

            // Get devices for this connection
            $devices = $connection->getDevices();

            if (empty($devices)) {
                $this->logger->warning('No devices found for connection', [
                    'email' => $email
                ]);
                return;
            }

            // Extract MAC addresses
            $macAddresses = [];
            foreach ($devices as $device) {
                $macAddresses[] = $device->getMqttId();
            }

            // Register connection with discovered devices
            $this->registerConnection($connection, $macAddresses);

            $this->logger->info('Connection added successfully', [
                'email' => $email,
                'device_count' => count($devices),
                'mac_addresses' => $macAddresses
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to add connection', [
                'email' => $email,
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);

            throw new \RuntimeException("Failed to add connection for {$email}: {$e->getMessage()}", 0, $e);
        }
    }
}