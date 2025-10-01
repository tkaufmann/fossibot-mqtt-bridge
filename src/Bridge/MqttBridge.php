<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Fossibot\Device\DeviceStateManager;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
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

    private ?MqttClient $brokerClient = null;
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
            },
            function (\Exception $e) {
                $this->logger->error('A critical error occurred during account connection, shutting down.', [
                    'error' => $e->getMessage(),
                ]);
                $this->shutdown();
            }
        );

        // Setup broker message loop (process incoming commands from broker)
        $this->loop->addPeriodicTimer(0.1, function() {
            if ($this->brokerClient !== null) {
                try {
                    // Process pending messages from local broker (non-blocking)
                    $this->brokerClient->loop(true);
                } catch (\Exception $e) {
                    $this->logger->error('Broker message loop error', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });

        // Setup broker health check (every 30 seconds)
        $this->loop->addPeriodicTimer(30, function() {
            if ($this->brokerClient !== null) {
                try {
                    // The loop() call will throw if connection is dead
                    $this->brokerClient->loop(true);
                } catch (\Exception $e) {
                    $this->logger->warning('Broker health check failed, reconnecting', [
                        'error' => $e->getMessage()
                    ]);

                    // Trigger reconnect
                    $this->brokerClient = null;
                    $this->connectBroker();
                }
            }
        });

        // Setup status publish timer (every 60s)
        $this->loop->addPeriodicTimer(
            $this->config['bridge']['status_publish_interval'] ?? 60,
            fn() => $this->publishBridgeStatus()
        );

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

        $this->logger->info('Connecting to local broker', [
            'host' => $host,
            'port' => $port,
            'attempt' => $this->brokerReconnectAttempt + 1
        ]);

        try {
            $this->brokerClient = new MqttClient($host, $port, $clientId);

            $settings = (new ConnectionSettings)
                ->setKeepAliveInterval(60)
                ->setUseTls(false)
                ->setLastWillTopic('fossibot/bridge/status')
                ->setLastWillMessage('offline')
                ->setLastWillQualityOfService(1)
                ->setRetainLastWill(true);

            if (!empty($this->config['mosquitto']['username'])) {
                $settings->setUsername($this->config['mosquitto']['username']);
                $settings->setPassword($this->config['mosquitto']['password']);
            }

            $this->brokerClient->connect($settings, true);

            // Subscribe to command topics
            $this->brokerClient->subscribe('fossibot/+/command', function($topic, $payload) {
                $this->handleBrokerCommand($topic, $payload);
            }, 1);

            $this->logger->info('Connected to local broker');

            // Reset reconnect counter on success
            $this->brokerReconnectAttempt = 0;

            // Publish availability for all devices from all connected clients
            foreach ($this->cloudClients as $client) {
                if ($client->isConnected()) {
                    foreach ($client->getDevices() as $device) {
                        $this->publishAvailability($device->getMqttId(), 'online');
                    }
                }
            }

        } catch (\Exception $e) {
            $this->handleBrokerConnectionFailure($e);
        }
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
}