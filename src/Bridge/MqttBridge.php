<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Fossibot\Device\DeviceStateManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * MQTT Bridge orchestrator with ReactPHP event loop.
 *
 * Manages multiple AsyncCloudClient instances (multi-account support).
 * Routes messages between Fossibot Cloud and local Mosquitto broker.
 * Handles state management and reconnection logic.
 */
class MqttBridge
{
    private LoopInterface $loop;
    private array $config;
    private LoggerInterface $logger;

    /** @var AsyncCloudClient[] Indexed by account email */
    private array $cloudClients = [];

    private ?AsyncMqttClient $localBrokerClient = null;
    private DeviceStateManager $stateManager;
    private TopicTranslator $topicTranslator;
    private PayloadTransformer $payloadTransformer;

    private bool $running = false;
    private int $startTime = 0;

    // Broker reconnect state
    private int $brokerReconnectAttempt = 0;
    private int $maxBrokerReconnectAttempts = 5;
    private array $brokerBackoffDelays = [5, 10, 15, 30, 60]; // seconds

    // Connection promises (must persist to prevent GC cleanup)
    private array $connectionPromises = [];

    // Device state polling
    private ?\React\EventLoop\TimerInterface $pollingTimer = null;
    private float $lastPollTime = 0;

    public function __construct(
        array $config,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->loop = Loop::get();

        // Initialize utilities
        $this->stateManager = new DeviceStateManager();
        $this->topicTranslator = new TopicTranslator();
        $this->payloadTransformer = new PayloadTransformer();
    }

    /**
     * Start bridge (blocking - runs event loop).
     */
    public function run(): void
    {
        $this->logger->info('MqttBridge starting...');
        $this->startTime = time();

        // Setup signal handlers
        $this->setupSignalHandlers();

        // Initialize accounts and wait for them to connect
        $this->initializeAccounts();

        \React\Promise\all($this->connectionPromises)->then(
            function () {
                $this->logger->info('All accounts connected successfully, proceeding with broker connection.');
                // Connect to local broker
                $this->connectBroker();

                // Publish initial bridge status
                $this->publishBridgeStatus();

                // Setup status publish timer (every 60s) - AFTER connections are established
                $this->loop->addPeriodicTimer(
                    $this->config['bridge']['status_publish_interval'] ?? 60,
                    fn() => $this->publishBridgeStatus()
                );

                // Setup device state polling timer (every 30s) - AFTER connections are established
                $this->pollingTimer = $this->loop->addPeriodicTimer(
                    $this->config['bridge']['device_poll_interval'] ?? 30,
                    fn() => $this->pollDeviceStates()
                );

                $this->logger->debug('Periodic timers started', [
                    'status_interval' => $this->config['bridge']['status_publish_interval'] ?? 60,
                    'polling_interval' => $this->config['bridge']['device_poll_interval'] ?? 30
                ]);
            },
            function (\Exception $e) {
                $this->logger->error('A critical error occurred during account connection, shutting down.', [
                    'error' => $e->getMessage(),
                ]);
                $this->shutdown();
            }
        );

        // NOTE: Removed periodic broker message loop timer - it blocked ReactPHP event loop
        // The phpMQTT client will process messages via the callback registered in connectBroker()

        $this->running = true;
        $this->logger->info('MqttBridge ready, entering event loop');

        // Run event loop (blocks here)
        $this->loop->run();

        $this->logger->info('MqttBridge stopped');
    }

    /**
     * Shutdown bridge gracefully.
     */
    public function shutdown(): void
    {
        $this->logger->info('MqttBridge shutting down...');
        $this->running = false;

        // Publish offline status to broker
        if ($this->brokerClient !== null) {
            $this->brokerClient->publish(
                'fossibot/bridge/status',
                'offline',
                1,
                true
            );

            // Publish device offline status
            foreach ($this->cloudClients as $email => $client) {
                foreach ($client->getDevices() as $device) {
                    $mac = $device->getMqttId();
                    $this->brokerClient->publish(
                        "fossibot/$mac/availability",
                        'offline',
                        1,
                        true
                    );
                }
            }
        }

        // Disconnect all cloud clients
        foreach ($this->cloudClients as $email => $client) {
            $this->logger->info('Disconnecting cloud client', ['email' => $email]);
            $client->disconnect();
        }

        // Disconnect broker
        if ($this->brokerClient !== null) {
            $this->brokerClient->disconnect();
        }

        // Stop event loop
        $this->loop->stop();

        $this->logger->info('MqttBridge stopped');
    }

    // --- Private Methods ---

    private function setupSignalHandlers(): void
    {
        // SIGTERM (systemd stop)
        $this->loop->addSignal(SIGTERM, function() {
            $this->logger->info('Received SIGTERM, shutting down gracefully');
            $this->shutdown();
        });

        // SIGINT (Ctrl+C)
        $this->loop->addSignal(SIGINT, function() {
            $this->logger->info('Received SIGINT, shutting down gracefully');
            $this->shutdown();
        });
    }

    private function initializeAccounts(): void
    {
        foreach ($this->config['accounts'] as $account) {
            if (isset($account['enabled']) && $account['enabled'] === false) {
                $this->logger->info('Account disabled, skipping', ['email' => $account['email']]);
                continue;
            }

            $email = $account['email'];
            $password = $account['password'];

            $this->logger->info('Initializing account', ['email' => $email]);

            $client = new AsyncCloudClient($email, $password, $this->loop, $this->logger);

            // Register event handlers
            $this->registerCloudClientEvents($client, $email);

            $this->cloudClients[$email] = $client;

            // Connect (async) - Store promise to prevent GC cleanup
            $this->connectionPromises[$email] = $client->connect()->then(
                function() use ($email) {
                    $this->logger->info('Account connected', ['email' => $email]);
                },
                function($error) use ($email) {
                    $this->logger->error('Account connection failed', [
                        'email' => $email,
                        'error' => $error->getMessage()
                    ]);
                }
            );
        }

        $this->logger->info('Initialized accounts', ['count' => count($this->cloudClients)]);
    }

    private function registerCloudClientEvents(AsyncCloudClient $client, string $email): void
    {
        $client->on('connect', function() use ($email, $client) {
            $this->logger->info('Cloud client connected', ['email' => $email]);

            // Publish availability for all devices
            foreach ($client->getDevices() as $device) {
                $this->publishAvailability($device->getMqttId(), 'online');
            }
        });

        $client->on('message', function($topic, $payload) use ($email) {
            $this->handleCloudMessage($email, $topic, $payload);
        });

        $client->on('disconnect', function() use ($email) {
            $this->logger->warning('Cloud client disconnected', ['email' => $email]);
            // TODO: Reconnect logic in Phase 3
        });

        $client->on('error', function($error) use ($email) {
            $this->logger->error('Cloud client error', [
                'email' => $email,
                'error' => $error->getMessage()
            ]);
        });
    }

    private function connectBroker(): void
    {
        $host = $this->config['mosquitto']['host'];
        $port = $this->config['mosquitto']['port'];
        $clientId = $this->config['mosquitto']['client_id'] ?? 'fossibot_bridge';
        $username = $this->config['mosquitto']['username'] ?? null;
        $password = $this->config['mosquitto']['password'] ?? null;

        $this->logger->info('Connecting to local broker', [
            'host' => $host,
            'port' => $port,
            'attempt' => $this->brokerReconnectAttempt + 1
        ]);

        // Create TCP transport
        $transport = new TcpTransport(
            $this->loop,
            $host,
            $port,
            $this->logger
        );

        // Create AsyncMqttClient with TCP transport
        $this->localBrokerClient = new AsyncMqttClient(
            $transport,
            $this->loop,
            $this->logger,
            $clientId,
            $username,
            $password
        );

        // Setup message handler
        $this->localBrokerClient->on('message', function($topic, $payload) {
            $this->handleBrokerCommand($topic, $payload);
        });

        // Connect and subscribe
        $this->localBrokerClient->connect()
            ->then(function() {
                $this->logger->info('Connected to local broker');

                // Reset reconnect counter on success
                $this->brokerReconnectAttempt = 0;

                // Subscribe to command topics
                return $this->localBrokerClient->subscribe('fossibot/+/command', 1);
            })
            ->then(function() {
                // Publish availability for all devices from all connected clients
                foreach ($this->cloudClients as $client) {
                    if ($client->isConnected()) {
                        foreach ($client->getDevices() as $device) {
                            $this->publishAvailability($device->getMqttId(), 'online');
                        }
                    }
                }

                // Publish initial bridge status
                $this->publishBridgeStatus();

                // Start periodic timers
                $this->startTimers();
            })
            ->otherwise(function(\Exception $e) {
                $this->handleBrokerConnectionFailure($e);
            });
    }

    /**
     * Handles broker connection failure with exponential backoff.
     */
    private function handleBrokerConnectionFailure(\Exception $error): void
    {
        $this->brokerReconnectAttempt++;

        if ($this->brokerReconnectAttempt > $this->maxBrokerReconnectAttempts) {
            $this->logger->critical('Failed to connect to local broker after max attempts', [
                'attempts' => $this->brokerReconnectAttempt,
                'error' => $error->getMessage()
            ]);

            // Don't give up completely - reset counter and continue trying
            $this->brokerReconnectAttempt = 0;
            $delay = $this->brokerBackoffDelays[count($this->brokerBackoffDelays) - 1];
        } else {
            $delayIndex = min($this->brokerReconnectAttempt - 1, count($this->brokerBackoffDelays) - 1);
            $delay = $this->brokerBackoffDelays[$delayIndex];
        }

        $this->logger->error('Failed to connect to local broker, retrying', [
            'attempt' => $this->brokerReconnectAttempt,
            'next_retry_in_seconds' => $delay,
            'error' => $error->getMessage()
        ]);

        // Schedule reconnect attempt
        $this->loop->addTimer($delay, function() {
            $this->connectBroker();
        });
    }

    private function handleCloudMessage(string $accountEmail, string $topic, string $payload): void
    {
        try {
            // Extract MAC
            $mac = $this->topicTranslator->extractMacFromCloudTopic($topic);
            if ($mac === null) {
                return;
            }

            // Parse Modbus
            $registers = $this->payloadTransformer->parseModbusPayload($payload);
            if (empty($registers)) {
                return;
            }

            // Update state
            $this->stateManager->updateDeviceState($mac, $registers);

            // Get state and convert to JSON
            $state = $this->stateManager->getDeviceState($mac);
            $json = $this->payloadTransformer->stateToJson($state);

            // Publish to broker
            $brokerTopic = $this->topicTranslator->cloudToBroker($topic);
            $this->brokerClient->publish($brokerTopic, $json, 1, true);

            $this->logger->debug('State published', [
                'mac' => $mac,
                'soc' => $state->soc
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error handling cloud message', [
                'topic' => $topic,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleBrokerCommand(string $topic, string $payload): void
    {
        try {
            // Extract MAC
            $mac = $this->topicTranslator->extractMacFromBrokerTopic($topic);
            if ($mac === null) {
                return;
            }

            // Parse JSON command
            $command = $this->payloadTransformer->jsonToCommand($payload);

            // Convert to Modbus
            $modbusPayload = $this->payloadTransformer->commandToModbus($command);

            // Find client responsible for this device
            $client = $this->findClientForDevice($mac);
            if ($client === null) {
                $this->logger->warning('No client found for device', ['mac' => $mac]);
                return;
            }

            // Publish to cloud
            $cloudTopic = $this->topicTranslator->brokerToCloud($topic);
            $client->publish($cloudTopic, $modbusPayload);

            $this->logger->info('Command forwarded to cloud', [
                'mac' => $mac,
                'command' => $command->getDescription()
            ]);

            // Trigger immediate poll for settings commands (delayed response)
            // Output commands (USB/AC/DC) get instant response, no need to poll
            if ($command->getResponseType() === \Fossibot\Commands\CommandResponseType::DELAYED) {
                $this->triggerImmediatePoll();
            }

        } catch (\Exception $e) {
            $this->logger->error('Error handling broker command', [
                'topic' => $topic,
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function findClientForDevice(string $mac): ?AsyncCloudClient
    {
        foreach ($this->cloudClients as $client) {
            foreach ($client->getDevices() as $device) {
                if ($device->getMqttId() === $mac) {
                    return $client;
                }
            }
        }

        return null;
    }

    private function publishAvailability(string $mac, string $status): void
    {
        // Only publish if broker is connected
        if ($this->brokerClient === null) {
            $this->logger->debug('Skipping availability publish - broker not connected yet', [
                'mac' => $mac,
                'status' => $status
            ]);
            return;
        }

        $topic = "fossibot/$mac/availability";
        $this->brokerClient->publish($topic, $status, 1, true);

        $this->logger->debug('Published availability', [
            'mac' => $mac,
            'status' => $status
        ]);
    }

    private function publishBridgeStatus(string $status = 'online'): void
    {
        $this->logger->debug('Status publish timer fired', [
            'status' => $status
        ]);

        $devices = [];

        foreach ($this->cloudClients as $client) {
            foreach ($client->getDevices() as $device) {
                $devices[] = [
                    'id' => $device->getMqttId(),
                    'name' => $device->deviceName,
                    'model' => $device->model,
                    'cloudConnected' => $client->isConnected(),
                    'lastSeen' => date('c')
                ];
            }
        }

        $statusMessage = [
            'status' => $status,
            'version' => '2.0.0',
            'uptime_seconds' => time() - $this->startTime,
            'accounts' => array_map(fn($email) => [
                'email' => $this->maskEmail($email),
                'connected' => $this->cloudClients[$email]->isConnected(),
                'device_count' => count($this->cloudClients[$email]->getDevices())
            ], array_keys($this->cloudClients)),
            'devices' => $devices,
            'timestamp' => date('c')
        ];

        $json = json_encode($statusMessage, JSON_THROW_ON_ERROR);
        $this->brokerClient->publish('fossibot/bridge/status', $json, 1, true);

        $this->logger->debug('Published bridge status');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email);

        if (strlen($local) <= 2) {
            return $email; // Too short to mask meaningfully
        }

        $masked = $local[0] . '***' . $local[strlen($local) - 1];
        return "$masked@$domain";
    }

    /**
     * Poll all devices for their current state.
     * Sends ReadRegistersCommand to each connected device.
     */
    private function pollDeviceStates(): void
    {
        $this->logger->debug('Polling timer fired', [
            'cloud_clients' => count($this->cloudClients)
        ]);

        $this->lastPollTime = microtime(true);

        foreach ($this->cloudClients as $client) {
            if (!$client->isConnected()) {
                continue;
            }

            foreach ($client->getDevices() as $device) {
                $mac = $device->getMqttId();

                try {
                    // Create read settings command (read 80 registers starting from 0)
                    $command = new \Fossibot\Commands\ReadRegistersCommand(0, 80);
                    $modbusPayload = $this->payloadTransformer->commandToModbus($command);

                    // Publish to cloud
                    $cloudTopic = "$mac/client/request/data";
                    $client->publish($cloudTopic, $modbusPayload);

                    $this->logger->debug('Polling device state', [
                        'mac' => $mac,
                        'command' => $command->getDescription()
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to poll device', [
                        'mac' => $mac,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Trigger immediate state poll after command execution.
     * Cancels and restarts the polling timer for quick feedback.
     */
    private function triggerImmediatePoll(): void
    {
        // Only trigger if last poll was more than 2 seconds ago
        // (avoid spam if multiple commands sent quickly)
        if ((microtime(true) - $this->lastPollTime) < 2.0) {
            return;
        }

        $this->logger->debug('Triggering immediate state poll after command');

        // Cancel existing timer
        if ($this->pollingTimer !== null) {
            $this->loop->cancelTimer($this->pollingTimer);
        }

        // Poll immediately
        $this->pollDeviceStates();

        // Restart periodic timer
        $this->pollingTimer = $this->loop->addPeriodicTimer(
            $this->config['bridge']['device_poll_interval'] ?? 30,
            fn() => $this->pollDeviceStates()
        );
    }
}