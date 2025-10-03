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

    // Cache instances (shared across all cloud clients)
    private ?\Fossibot\Cache\TokenCache $tokenCache = null;
    private ?\Fossibot\Cache\DeviceCache $deviceCache = null;

    // Health monitoring
    private BridgeMetrics $metrics;
    private ?HealthCheckServer $healthServer = null;

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

    // Command tracking for spontaneous vs. triggered /client/04 detection
    /** @var array<string, float> MAC => timestamp of last command sent */
    private array $lastCommandSent = [];

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

        // Initialize caches if configured
        if (isset($this->config['cache'])) {
            $cacheDir = $this->config['cache']['directory'] ?? '/var/lib/fossibot';

            // Ensure cache directory exists
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0700, true);
                $this->logger->info('Created cache directory', ['path' => $cacheDir]);
            }

            // Token cache
            $tokenTtlSafety = $this->config['cache']['token_ttl_safety_margin'] ?? 300;
            $this->tokenCache = new \Fossibot\Cache\TokenCache($cacheDir, $tokenTtlSafety, $this->logger);

            // Device cache
            $deviceTtl = $this->config['cache']['device_list_ttl'] ?? 86400;
            $this->deviceCache = new \Fossibot\Cache\DeviceCache($cacheDir, $deviceTtl, $this->logger);

            $this->logger->info('Cache initialized', [
                'directory' => $cacheDir,
                'token_safety_margin' => $tokenTtlSafety,
                'device_ttl' => $deviceTtl
            ]);
        }

        // Initialize health metrics
        $this->metrics = new BridgeMetrics();
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
                // Connect to local broker (async)
                $this->connectBroker();
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

        // Start health check server (if configured)
        if (isset($this->config['health']['enabled']) && $this->config['health']['enabled']) {
            $port = $this->config['health']['port'] ?? 8080;
            $this->healthServer = new HealthCheckServer($this->loop, $this->metrics, $this->logger);

            try {
                $this->healthServer->start($port);
            } catch (\Exception $e) {
                $this->logger->error('Failed to start health server, continuing without it', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Periodic device list refresh (24h)
        if (isset($this->config['cache']['device_refresh_interval'])) {
            $refreshInterval = $this->config['cache']['device_refresh_interval'];
            $this->loop->addPeriodicTimer($refreshInterval, function() {
                $this->logger->info('Periodic device list refresh triggered');

                foreach ($this->cloudClients as $client) {
                    $client->refreshDeviceList()->then(
                        function() {
                            $this->logger->debug('Device list refresh completed');
                        },
                        function(\Exception $e) {
                            $this->logger->error('Device list refresh failed', [
                                'error' => $e->getMessage()
                            ]);
                        }
                    );
                }
            });

            $this->logger->info('Periodic device refresh enabled', [
                'interval' => $refreshInterval . 's'
            ]);
        }

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
        if ($this->localBrokerClient !== null) {
            $this->localBrokerClient->publish(
                'fossibot/bridge/status',
                'offline',
                1
            );

            // Publish device offline status
            foreach ($this->cloudClients as $email => $client) {
                foreach ($client->getDevices() as $device) {
                    $mac = $device->getMqttId();
                    $this->localBrokerClient->publish(
                        "fossibot/$mac/availability",
                        'offline',
                        1
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
        if ($this->localBrokerClient !== null) {
            $this->localBrokerClient->disconnect();
        }

        // Stop health server
        if ($this->healthServer !== null) {
            $this->healthServer->stop();
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

            // Set cache instances (if configured)
            if ($this->tokenCache !== null) {
                $client->setTokenCache($this->tokenCache);
            }
            if ($this->deviceCache !== null) {
                $client->setDeviceCache($this->deviceCache);
            }

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

        // After all clients connected, update metrics
        $totalAccounts = count($this->config['accounts']);
        $connectedAccounts = count($this->cloudClients);
        $this->metrics->setAccountMetrics($totalAccounts, $connectedAccounts);

        // Count total devices
        $totalDevices = 0;
        foreach ($this->cloudClients as $client) {
            $totalDevices += count($client->getDevices());
        }
        $this->metrics->setDeviceMetrics($totalDevices, $totalDevices); // Assume all online initially
    }

    private function registerCloudClientEvents(AsyncCloudClient $client, string $email): void
    {
        $client->on('connect', function() use ($email, $client) {
            $this->logger->info('Cloud client connected', ['email' => $email]);

            // Publish availability for all devices
            foreach ($client->getDevices() as $device) {
                $status = $device->isOnline() ? 'online' : 'offline';
                $this->publishAvailability($device->getMqttId(), $status);
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

                // Update broker status in metrics
                $this->metrics->setLocalBrokerConnected(true);

                // Subscribe to command topics
                return $this->localBrokerClient->subscribe('fossibot/+/command', 1);
            })
            ->then(function() {
                // Publish availability for all devices from all connected clients
                foreach ($this->cloudClients as $client) {
                    if ($client->isConnected()) {
                        foreach ($client->getDevices() as $device) {
                            $status = $device->isOnline() ? 'online' : 'offline';
                            $this->publishAvailability($device->getMqttId(), $status);
                        }
                    }
                }

                // Publish initial bridge status
                $this->publishBridgeStatus();

                // Setup status publish timer (every 60s)
                $this->loop->addPeriodicTimer(
                    $this->config['bridge']['status_publish_interval'] ?? 60,
                    fn() => $this->publishBridgeStatus()
                );

                // Setup device state polling timer (every 30s)
                $this->pollingTimer = $this->loop->addPeriodicTimer(
                    $this->config['bridge']['device_poll_interval'] ?? 30,
                    fn() => $this->pollDeviceStates()
                );

                $this->logger->debug('Periodic timers started', [
                    'status_interval' => $this->config['bridge']['status_publish_interval'] ?? 60,
                    'polling_interval' => $this->config['bridge']['device_poll_interval'] ?? 30
                ]);
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
        $this->logger->debug('handleCloudMessage called', [
            'account' => $accountEmail,
            'topic' => $topic,
            'payload_length' => strlen($payload)
        ]);

        try {
            // Extract MAC
            $mac = $this->topicTranslator->extractMacFromCloudTopic($topic);
            $this->logger->debug('MAC extracted', ['mac' => $mac, 'topic' => $topic]);
            if ($mac === null) {
                $this->logger->warning('MAC extraction failed', ['topic' => $topic]);
                return;
            }

            // Parse Modbus
            $registers = $this->payloadTransformer->parseModbusPayload($payload);
            $this->logger->debug('Modbus parsed', ['register_count' => count($registers)]);
            if (empty($registers)) {
                $this->logger->warning('Modbus parsing failed or empty', ['payload_length' => strlen($payload)]);
                return;
            }

            // Optional: Log raw Register 41 and 56 values (configurable via LOG_RAW_REGISTERS)
            if (($this->config['debug']['log_raw_registers'] ?? false) && (isset($registers[41]) || isset($registers[56]))) {
                $topicType = str_contains($topic, '/client/04') ? 'IMMEDIATE' : 'POLLING';
                $logData = ['topic' => $topic];

                if (isset($registers[41])) {
                    $logData['register_41'] = $registers[41];
                    $logData['r41_hex'] = sprintf('0x%X', $registers[41]);
                    $logData['r41_binary'] = sprintf('0b%016b', $registers[41]);
                }

                if (isset($registers[56])) {
                    $logData['register_56'] = $registers[56];
                    $logData['soc'] = round($registers[56] / 1000 * 100, 1) . '%';
                }

                $this->logger->info("RAW Registers from {$topicType}", $logData);
            }

            // Detect if this is a command-triggered update (within 3s of last command)
            $wasCommandTriggered = false;
            $isImmediateResponse = str_contains($topic, '/client/04');
            if ($isImmediateResponse && isset($this->lastCommandSent[$mac])) {
                $timeSinceCommand = microtime(true) - $this->lastCommandSent[$mac];
                $wasCommandTriggered = $timeSinceCommand < 3.0; // 3 second window
            }

            // Update state (pass topic and command-triggered flag)
            $this->stateManager->updateDeviceState($mac, $registers, $topic, $wasCommandTriggered);

            // Get state and convert to JSON
            $state = $this->stateManager->getDeviceState($mac);
            $json = $this->payloadTransformer->stateToJson($state);

            // Publish to broker
            $brokerTopic = $this->topicTranslator->cloudToBroker($topic);
            $this->localBrokerClient->publish($brokerTopic, $json, 1);

            // Enhanced logging with source tracking
            $logData = [
                'mac' => $mac,
                'soc' => $state->soc . '%',
                'input' => $state->inputWatts . 'W',
                'output' => $state->outputWatts . 'W',
                'dc_input' => $state->dcInputWatts . 'W',
                'outputs' => [
                    'usb' => $state->usbOutput ? 'ON' : 'OFF',
                    'ac' => $state->acOutput ? 'ON' : 'OFF',
                    'dc' => $state->dcOutput ? 'ON' : 'OFF',
                    'led' => $state->ledOutput ? 'ON' : 'OFF'
                ],
                'settings' => [
                    'max_charging' => $state->maxChargingCurrent . 'A',
                    'discharge_limit' => $state->dischargeLowerLimit . '%',
                    'ac_limit' => $state->acChargingUpperLimit . '%'
                ],
                'timestamp' => $state->lastFullUpdate->format('H:i:s')
            ];

            // Optional: Add source information for /client/04 updates (configurable via LOG_UPDATE_SOURCE)
            if ($isImmediateResponse && ($this->config['debug']['log_update_source'] ?? false)) {
                $logData['source'] = $state->lastUpdateSource ?? 'unknown';
                $logData['update_type'] = $wasCommandTriggered ? 'COMMAND-TRIGGERED' : 'SPONTANEOUS';
            }

            $this->logger->info('Device state updated', $logData);

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

            // Track command timestamp for spontaneous vs. triggered detection
            $this->lastCommandSent[$mac] = microtime(true);

            $this->logger->info('Command forwarded to cloud', [
                'mac' => $mac,
                'command' => $command->getDescription(),
                'topic' => $cloudTopic,
                'payload_hex' => bin2hex($modbusPayload),
                'payload_length' => strlen($modbusPayload)
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
        if ($this->localBrokerClient === null) {
            $this->logger->debug('Skipping availability publish - broker not connected yet', [
                'mac' => $mac,
                'status' => $status
            ]);
            return;
        }

        $topic = "fossibot/$mac/availability";
        $this->localBrokerClient->publish($topic, $status, 1);

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
        $this->localBrokerClient->publish('fossibot/bridge/status', $json, 1);

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