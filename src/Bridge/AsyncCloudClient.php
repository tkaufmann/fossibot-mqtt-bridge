<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Async MQTT client for Fossibot Cloud connection.
 *
 * Connects to Fossibot Cloud via MQTT over WebSocket using ReactPHP.
 * Emits events for messages, connection status, and errors.
 * One instance per Fossibot account.
 *
 * This client implements event-based MQTT communication without polling,
 * integrating the WebSocket stream directly with MQTT packet handlers.
 *
 * Events:
 * - 'connect' => function()
 * - 'message' => function(string $topic, string $payload)
 * - 'disconnect' => function()
 * - 'error' => function(\Exception $e)
 */
class AsyncCloudClient extends EventEmitter
{
    private string $email;
    private string $password;
    private LoopInterface $loop;
    private LoggerInterface $logger;

    private ?AsyncMqttClient $mqttClient = null;
    private bool $connected = false;

    private array $devices = [];

    // Reconnect state
    private bool $reconnecting = false;
    private int $reconnectAttempts = 0;
    private int $maxReconnectAttempts = 10;
    private array $backoffDelays = [5, 10, 15, 30, 45, 60]; // seconds
    private ?\React\EventLoop\TimerInterface $reconnectTimer = null;

    // Token expiry tracking
    private ?int $mqttTokenExpiresAt = null;
    private ?int $loginTokenExpiresAt = null;

    // Running state (prevents auto-reconnect during shutdown)
    private bool $running = true;

    // Authentication tokens (async auth)
    private ?string $anonymousToken = null;
    private ?string $loginToken = null;
    private ?string $mqttToken = null;
    private string $deviceId;

    // HTTP Browser (must persist to prevent GC cleanup during async requests)
    private ?\React\Http\Browser $browser = null;

    public function __construct(
        string $email,
        string $password,
        LoopInterface $loop,
        ?LoggerInterface $logger = null
    ) {
        $this->email = $email;
        $this->password = $password;
        $this->loop = $loop;
        $this->logger = $logger ?? new NullLogger();
        $this->deviceId = $this->generateDeviceId();
    }

    /**
     * Connect to Fossibot Cloud (async).
     *
     * @return PromiseInterface Resolves when connected
     */
    public function connect(): PromiseInterface
    {
        $this->logger->info('AsyncCloudClient connecting', [
            'email' => $this->email
        ]);

        // Phase 1: Authenticate (HTTP tokens only, Stages 1-3)
        return $this->authenticate()
            ->then(function() {
                // Phase 2: Discover devices (HTTP API)
                return $this->discoverDevices();
            })
            ->then(function() {
                // Phase 3: Connect MQTT (via AsyncMqttClient with WebSocket transport)
                return $this->connectMqtt();
            })
            ->then(function() {
                // MQTT connected, set flag before subscribing
                $this->connected = true;

                // Phase 4: Subscribe to device topics
                return $this->subscribeToDeviceTopics();
            })
            ->then(function() {
                $this->emit('connect');
                $this->logger->info('AsyncCloudClient connected successfully');
            })
            ->otherwise(function(\Exception $e) {
                $this->logger->error('AsyncCloudClient connect failed', [
                    'error' => $e->getMessage()
                ]);
                $this->emit('error', [$e]);
                throw $e;
            });
    }

    /**
     * Disconnect from cloud (async).
     */
    public function disconnect(): PromiseInterface
    {
        $this->logger->info('AsyncCloudClient disconnecting');

        $this->running = false; // Prevent auto-reconnect
        $this->connected = false;

        // Disconnect MQTT client
        if ($this->mqttClient !== null) {
            return $this->mqttClient->disconnect()
                ->then(function() {
                    $this->mqttClient = null;
                    $this->emit('disconnect');
                });
        }

        $this->emit('disconnect');
        return \React\Promise\resolve(null);
    }

    /**
     * Initiates reconnection with smart tier-based strategy.
     *
     * @param bool $forceReauth Force Tier 2 (full re-auth) immediately
     * @return PromiseInterface
     */
    public function reconnect(bool $forceReauth = false): PromiseInterface
    {
        if ($this->reconnecting) {
            $this->logger->debug('Reconnection already in progress', [
                'email' => $this->email
            ]);
            return \React\Promise\resolve();
        }

        $this->reconnecting = true;
        $this->reconnectAttempts++;

        $this->logger->info('Starting reconnection attempt', [
            'email' => $this->email,
            'attempt' => $this->reconnectAttempts,
            'force_reauth' => $forceReauth
        ]);

        // Cancel any pending reconnect timer
        if ($this->reconnectTimer !== null) {
            $this->loop->cancelTimer($this->reconnectTimer);
            $this->reconnectTimer = null;
        }

        // Tier 1: Simple reconnect (unless forceReauth)
        if (!$forceReauth && $this->hasValidTokens()) {
            return $this->attemptSimpleReconnect()
                ->then(
                    fn() => $this->onReconnectSuccess(),
                    fn($error) => $this->onReconnectFailure($error, false)
                );
        }

        // Tier 2: Full re-authentication
        return $this->attemptFullReauth()
            ->then(
                fn() => $this->onReconnectSuccess(),
                fn($error) => $this->onReconnectFailure($error, true)
            );
    }

    /**
     * Check if client is connected.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get discovered devices.
     *
     * @return \Fossibot\Device\Device[]
     */
    public function getDevices(): array
    {
        return $this->devices;
    }

    /**
     * Subscribe to MQTT topic.
     */
    public function subscribe(string $topic, int $qos = 0): PromiseInterface
    {
        if (!$this->connected || $this->mqttClient === null) {
            throw new \RuntimeException('Cannot subscribe: not connected');
        }

        $this->logger->debug('Subscribing to topic', ['topic' => $topic]);
        return $this->mqttClient->subscribe($topic, $qos);
    }

    /**
     * Publish to MQTT topic.
     */
    public function publish(string $topic, string $payload, int $qos = 1): PromiseInterface
    {
        if (!$this->connected || $this->mqttClient === null) {
            throw new \RuntimeException('Cannot publish: not connected');
        }

        $this->logger->debug('Publishing to topic', [
            'topic' => $topic,
            'payload_length' => strlen($payload),
            'qos' => $qos
        ]);
        return $this->mqttClient->publish($topic, $payload, $qos);
    }

    // --- Private Methods ---

    /**
     * Tier 1: Simple reconnect using existing authentication tokens.
     */
    private function attemptSimpleReconnect(): PromiseInterface
    {
        $this->logger->debug('Attempting Tier 1: Simple reconnect', [
            'email' => $this->email
        ]);

        // Close existing MQTT client cleanly
        if ($this->mqttClient !== null) {
            $this->mqttClient->disconnect();
            $this->mqttClient = null;
        }

        $this->connected = false;

        // Reconnect with existing tokens
        return $this->connectMqtt()
            ->then(fn() => $this->resubscribeToDevices());
    }

    /**
     * Tier 2: Full re-authentication flow.
     */
    private function attemptFullReauth(): PromiseInterface
    {
        $this->logger->debug('Attempting Tier 2: Full re-authentication', [
            'email' => $this->email
        ]);

        // Clean slate
        if ($this->mqttClient !== null) {
            $this->mqttClient->disconnect();
            $this->mqttClient = null;
        }

        $this->connected = false;
        $this->clearAuthTokens();

        // Full authentication flow (same as initial connect())
        return $this->authenticate()
            ->then(fn() => $this->connectMqtt())
            ->then(fn() => $this->discoverDevices());
    }

    /**
     * Reconnection succeeded - reset state.
     */
    private function onReconnectSuccess(): void
    {
        $this->connected = true;
        $this->reconnecting = false;
        $this->reconnectAttempts = 0;

        $this->logger->info('Reconnection successful', [
            'email' => $this->email
        ]);

        $this->emit('reconnect');
    }

    /**
     * Reconnection failed - schedule Tier 3 retry with exponential backoff.
     *
     * @param \Throwable $error The error that caused failure
     * @param bool $wasReauth Whether this was a Tier 2 (full reauth) attempt
     */
    private function onReconnectFailure(\Throwable $error, bool $wasReauth): void
    {
        $this->reconnecting = false;

        // Check if we should give up
        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
            $this->logger->error('Max reconnection attempts reached, giving up', [
                'email' => $this->email,
                'attempts' => $this->reconnectAttempts,
                'error' => $error->getMessage()
            ]);

            $this->emit('error', [$error]);
            return;
        }

        // Determine backoff delay
        $delayIndex = min($this->reconnectAttempts - 1, count($this->backoffDelays) - 1);
        $delay = $this->backoffDelays[$delayIndex];

        $this->logger->warning('Reconnection failed, scheduling retry', [
            'email' => $this->email,
            'attempt' => $this->reconnectAttempts,
            'next_retry_in_seconds' => $delay,
            'was_reauth' => $wasReauth,
            'error' => $error->getMessage()
        ]);

        // Tier 3: Schedule retry with exponential backoff
        $this->reconnectTimer = $this->loop->addTimer($delay, function() use ($wasReauth) {
            $this->reconnectTimer = null;

            // If simple reconnect failed, try full reauth next time
            $forceReauth = !$wasReauth;

            $this->reconnect($forceReauth);
        });

        $this->emit('reconnect_scheduled', [$delay]);
    }

    /**
     * Checks if cached authentication tokens are still valid.
     */
    private function hasValidTokens(): bool
    {
        // Check if we have MQTT token
        if ($this->mqttToken === null) {
            return false;
        }

        $now = time();

        // Check MQTT token expiry (primary concern, ~3 days)
        if ($this->mqttTokenExpiresAt !== null && $this->mqttTokenExpiresAt <= $now) {
            $this->logger->debug('MQTT token expired', [
                'email' => $this->email,
                'expired_at' => date('Y-m-d H:i:s', $this->mqttTokenExpiresAt)
            ]);
            return false;
        }

        // Check login token expiry (~14 years, rarely expires)
        if ($this->loginTokenExpiresAt !== null && $this->loginTokenExpiresAt <= $now) {
            $this->logger->debug('Login token expired', [
                'email' => $this->email,
                'expired_at' => date('Y-m-d H:i:s', $this->loginTokenExpiresAt)
            ]);
            return false;
        }

        return true;
    }

    /**
     * Clears all cached authentication tokens.
     */
    private function clearAuthTokens(): void
    {
        $this->mqttTokenExpiresAt = null;
        $this->loginTokenExpiresAt = null;
    }

    /**
     * Re-subscribes to all device topics after reconnection.
     */
    private function resubscribeToDevices(): PromiseInterface
    {
        $this->logger->debug('Re-subscribing to device topics', [
            'email' => $this->email,
            'device_count' => count($this->devices)
        ]);

        foreach ($this->devices as $device) {
            $mac = $device->getMqttId();
            $topic = "$mac/device/response/+";
            $this->subscribe($topic);
        }

        return \React\Promise\resolve();
    }

    /**
     * Performs 3-stage authentication (async).
     *
     * Authenticates with Fossibot Cloud API and acquires all required tokens.
     * This replaces the synchronous Connection::authenticateOnly() call.
     *
     * @return PromiseInterface Resolves when all tokens acquired
     */
    /**
     * Connect MQTT client using AsyncMqttClient with WebSocket transport.
     */
    private function connectMqtt(): PromiseInterface
    {
        $this->logger->debug('Connecting MQTT client via WebSocket transport');

        // Create WebSocket transport
        $transport = new WebSocketTransport(
            $this->loop,
            'ws://mqtt.sydpower.com:8083/mqtt',
            ['mqtt'],
            $this->logger
        );

        // Create AsyncMqttClient with transport
        $clientId = 'fossibot_async_' . uniqid();
        $this->mqttClient = new AsyncMqttClient(
            $transport,
            $this->loop,
            $this->logger,
            $clientId,
            $this->mqttToken,  // JWT as username
            'helloyou'         // Password
        );

        // Forward MQTT client events to AsyncCloudClient events
        $this->mqttClient->on('message', function ($topic, $payload) {
            $this->emit('message', [$topic, $payload]);
        });

        $this->mqttClient->on('disconnect', function () {
            $this->logger->warning('MQTT client disconnected');
            $this->connected = false;
            $this->emit('disconnect');

            // Auto-reconnect if not manually disconnected
            if ($this->running && !$this->reconnecting) {
                $this->loop->futureTick(function() {
                    $this->reconnect(false); // Try Tier 1 first
                });
            }
        });

        $this->mqttClient->on('error', function (\Exception $e) {
            $this->logger->error('MQTT client error', ['error' => $e->getMessage()]);
            $this->emit('error', [$e]);
        });

        // Connect MQTT client
        return $this->mqttClient->connect();
    }

    private function authenticate(): PromiseInterface
    {
        $this->logger->info('Starting async authentication', [
            'email' => $this->email
        ]);

        // Create Browser only once and store as class property to prevent GC cleanup
        if ($this->browser === null) {
            $this->browser = $this->createBrowser();
        }

        // Stage 1: Anonymous Token
        return $this->stage1_getAnonymousToken($this->browser)
            ->then(function(string $anonToken) {
                $this->anonymousToken = $anonToken;
                $this->logger->info('Stage 1 completed: Anonymous token acquired');

                // Stage 2: Login Token
                return $this->stage2_login($this->browser, $anonToken);
            })
            ->then(function(string $loginToken) {
                $this->loginToken = $loginToken;
                $this->logger->info('Stage 2 completed: Login token acquired');

                // Stage 3: MQTT Token
                return $this->stage3_getMqttToken($this->browser, $this->anonymousToken, $loginToken);
            })
            ->then(function(string $mqttToken) {
                $this->mqttToken = $mqttToken;
                $this->logger->info('Stage 3 completed: MQTT token acquired', [
                    'expires_at' => $this->mqttTokenExpiresAt ? date('Y-m-d H:i:s', $this->mqttTokenExpiresAt) : 'unknown'
                ]);
            });
    }

    /**
     * Discovers devices via async HTTP API call.
     *
     * Fetches device list from Fossibot Cloud API.
     * Requires authentication tokens from authenticate().
     *
     * @return PromiseInterface Resolves when devices discovered
     */
    private function discoverDevices(): PromiseInterface
    {
        // Reuse existing browser instance (created in authenticate())
        if ($this->browser === null) {
            $this->browser = $this->createBrowser();
        }

        return $this->fetchDevices($this->browser, $this->anonymousToken, $this->loginToken)
            ->then(function(array $devices) {
                $this->devices = $devices;
                return null;
            });
    }

    private function subscribeToDeviceTopics(): PromiseInterface
    {
        try {
            // Subscribe to MQTT topics for all discovered devices
            foreach ($this->devices as $device) {
                $mac = $device->getMqttId();
                // Subscribe to all response topics:
                // - {mac}/device/response/client/+ (catches /04 and /data)
                // - {mac}/device/response/state
                $this->subscribe("$mac/device/response/client/+");
                $this->subscribe("$mac/device/response/state");
            }

            $this->logger->debug('Subscribed to device topics', [
                'device_count' => count($this->devices)
            ]);

            return \React\Promise\resolve(null);
        } catch (\Exception $e) {
            $this->logger->error('Topic subscription failed', [
                'error' => $e->getMessage()
            ]);
            return \React\Promise\reject($e);
        }
    }

    /**
     * Extracts expiry timestamp from JWT token.
     */
    private function extractJwtExpiry(string $jwt): ?int
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        // Decode JWT payload (second part)
        $payload = json_decode(base64_decode($parts[1]), true);

        return $payload['exp'] ?? null;
    }

    // ========================================================================
    // Async Authentication Implementation (Stages 1-3)
    // ========================================================================

    /**
     * Stage 1: Acquire anonymous token (async).
     *
     * @param \React\Http\Browser $browser HTTP client
     * @return PromiseInterface Resolves with anonymous token string
     */
    private function stage1_getAnonymousToken(\React\Http\Browser $browser): PromiseInterface
    {
        $this->logger->debug('Stage 1: Requesting anonymous token');

        $requestData = [
            'method' => 'serverless.auth.user.anonymousAuthorize',
            'params' => '{}',
            'spaceId' => \Fossibot\Config::getSpaceId(),
            'timestamp' => (int)(microtime(true) * 1000),
        ];

        $signature = $this->generateSignature($requestData);

        $this->logger->debug('Stage 1: About to call $browser->post()', [
            'url' => \Fossibot\Config::getApiEndpoint(),
            'browser_class' => get_class($browser),
        ]);

        $promise = $browser->post(
            \Fossibot\Config::getApiEndpoint(),
            [
                'Content-Type' => 'application/json',
                'x-serverless-sign' => $signature,
            ],
            json_encode($requestData)
        );

        $this->logger->debug('Stage 1: $browser->post() returned', [
            'promise_class' => get_class($promise),
        ]);

        return $promise->then(
            function (\Psr\Http\Message\ResponseInterface $response) {
                $this->logger->debug('Stage 1: Then handler called (success)', [
                    'status' => $response->getStatusCode(),
                ]);

                $body = (string)$response->getBody();
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Stage 1: JSON decode error - ' . json_last_error_msg());
                }

                if (!isset($data['data']['accessToken'])) {
                    throw new \RuntimeException('Stage 1: Missing accessToken in response');
                }

                $token = $data['data']['accessToken'];
                $this->logger->debug('Stage 1 completed', [
                    'token_length' => strlen($token),
                    'expires_in' => $data['data']['expiresInSecond'] ?? 'unknown',
                ]);

                return $token;
            },
            function (\Exception $e) {
                $this->logger->debug('Stage 1: Rejection handler called', [
                    'error_class' => get_class($e),
                ]);

                $this->logger->error('Stage 1 failed', [
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                throw new \RuntimeException('Stage 1 (Anonymous Token) failed: ' . $e->getMessage(), 0, $e);
            }
        );
    }

    /**
     * Stage 2: User login (async).
     *
     * @param \React\Http\Browser $browser HTTP client
     * @param string $anonymousToken Token from Stage 1
     * @return PromiseInterface Resolves with login token string
     */
    private function stage2_login(\React\Http\Browser $browser, string $anonymousToken): PromiseInterface
    {
        $this->logger->debug('Stage 2: Logging in', ['email' => $this->email]);

        $deviceInfo = new \Fossibot\ValueObjects\DeviceInfo(deviceId: $this->deviceId);

        $functionArgs = [
            '$url' => 'user/pub/login',
            'data' => [
                'locale' => 'en',
                'username' => $this->email,
                'password' => $this->password,
            ],
            'clientInfo' => $deviceInfo->toArray(),
            'uniIdToken' => $anonymousToken,
        ];

        $requestData = [
            'method' => 'serverless.function.runtime.invoke',
            'params' => json_encode([
                'functionTarget' => 'router',
                'functionArgs' => $functionArgs,
            ]),
            'spaceId' => \Fossibot\Config::getSpaceId(),
            'timestamp' => (int)(microtime(true) * 1000),
            'token' => $anonymousToken,
        ];

        $signature = $this->generateSignature($requestData);

        return $browser->post(
            \Fossibot\Config::getApiEndpoint(),
            [
                'Content-Type' => 'application/json',
                'x-serverless-sign' => $signature,
            ],
            json_encode($requestData)
        )->then(
            function (\Psr\Http\Message\ResponseInterface $response) {
                $body = (string)$response->getBody();
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Stage 2: JSON decode error - ' . json_last_error_msg());
                }

                if (!isset($data['data']['token'])) {
                    throw new \RuntimeException('Stage 2: Missing token in response');
                }

                $token = $data['data']['token'];
                $this->logger->debug('Stage 2 completed', [
                    'token_length' => strlen($token),
                    'email' => $this->email,
                ]);

                // Store login token expiry if available
                if (isset($data['data']['tokenExpired'])) {
                    $this->loginTokenExpiresAt = (int)($data['data']['tokenExpired'] / 1000);
                }

                return $token;
            },
            function (\Exception $e) {
                $this->logger->error('Stage 2 failed', [
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                    'email' => $this->email,
                ]);
                throw new \RuntimeException('Stage 2 (Login) failed: ' . $e->getMessage(), 0, $e);
            }
        );
    }

    /**
     * Stage 3: Acquire MQTT token (async).
     *
     * @param \React\Http\Browser $browser HTTP client
     * @param string $anonymousToken Token from Stage 1
     * @param string $loginToken Token from Stage 2
     * @return PromiseInterface Resolves with MQTT token string
     */
    private function stage3_getMqttToken(\React\Http\Browser $browser, string $anonymousToken, string $loginToken): PromiseInterface
    {
        $this->logger->debug('Stage 3: Requesting MQTT token');

        $deviceInfo = new \Fossibot\ValueObjects\DeviceInfo(deviceId: $this->deviceId);

        $functionArgs = [
            '$url' => 'common/emqx.getAccessToken',
            'data' => [
                'locale' => 'en',
            ],
            'clientInfo' => $deviceInfo->toArray(),
            'uniIdToken' => $loginToken,
        ];

        $requestData = [
            'method' => 'serverless.function.runtime.invoke',
            'params' => json_encode([
                'functionTarget' => 'router',
                'functionArgs' => $functionArgs,
            ]),
            'spaceId' => \Fossibot\Config::getSpaceId(),
            'timestamp' => (int)(microtime(true) * 1000),
            'token' => $anonymousToken,
        ];

        $signature = $this->generateSignature($requestData);

        return $browser->post(
            \Fossibot\Config::getApiEndpoint(),
            [
                'Content-Type' => 'application/json',
                'x-serverless-sign' => $signature,
            ],
            json_encode($requestData)
        )->then(
            function (\Psr\Http\Message\ResponseInterface $response) {
                $body = (string)$response->getBody();
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Stage 3: JSON decode error - ' . json_last_error_msg());
                }

                if (!isset($data['data']['access_token'])) {
                    throw new \RuntimeException('Stage 3: Missing access_token in response');
                }

                $token = $data['data']['access_token'];
                $expiry = $this->extractJwtExpiry($token);

                $this->logger->debug('Stage 3 completed', [
                    'token_length' => strlen($token),
                    'expires_at' => $expiry ? date('Y-m-d H:i:s', $expiry) : 'unknown',
                ]);

                // Store MQTT token expiry
                $this->mqttTokenExpiresAt = $expiry;

                return $token;
            },
            function (\Exception $e) {
                $this->logger->error('Stage 3 failed', [
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                throw new \RuntimeException('Stage 3 (MQTT Token) failed: ' . $e->getMessage(), 0, $e);
            }
        );
    }

    /**
     * Fetch device list from API (async).
     *
     * @param \React\Http\Browser $browser HTTP client
     * @param string $anonymousToken Token from Stage 1
     * @param string $loginToken Token from Stage 2
     * @return PromiseInterface Resolves with Device[] array
     */
    private function fetchDevices(\React\Http\Browser $browser, string $anonymousToken, string $loginToken): PromiseInterface
    {
        $this->logger->debug('Fetching device list');

        $deviceInfo = new \Fossibot\ValueObjects\DeviceInfo(deviceId: $this->deviceId);

        $functionArgs = [
            '$url' => 'client/device/kh/getList',
            'data' => [
                'locale' => 'en',
                'pageIndex' => 1,
                'pageSize' => 100,
            ],
            'clientInfo' => $deviceInfo->toArray(),
            'uniIdToken' => $loginToken,
        ];

        $requestData = [
            'method' => 'serverless.function.runtime.invoke',
            'params' => json_encode([
                'functionTarget' => 'router',
                'functionArgs' => $functionArgs,
            ]),
            'spaceId' => \Fossibot\Config::getSpaceId(),
            'timestamp' => (int)(microtime(true) * 1000),
            'token' => $anonymousToken,
        ];

        $signature = $this->generateSignature($requestData);

        return $browser->post(
            \Fossibot\Config::getApiEndpoint(),
            [
                'Content-Type' => 'application/json',
                'x-serverless-sign' => $signature,
            ],
            json_encode($requestData)
        )->then(
            function (\Psr\Http\Message\ResponseInterface $response) {
                $body = (string)$response->getBody();
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Device list: JSON decode error - ' . json_last_error_msg());
                }

                $this->logger->debug('Device list API response', [
                    'status' => $response->getStatusCode(),
                    'has_data' => isset($data['data']),
                    'response_keys' => array_keys($data ?? []),
                    'full_response' => $data,
                ]);

                if (!isset($data['data'])) {
                    throw new \RuntimeException('Device list: Missing data field in response');
                }

                $rows = $data['data']['rows'] ?? [];
                if (empty($rows)) {
                    $this->logger->warning('No devices found in account', [
                        'data_keys' => array_keys($data['data']),
                        'data_content' => $data['data'],
                    ]);
                    return [];
                }

                // Parse devices
                $devices = [];
                foreach ($rows as $deviceData) {
                    try {
                        $devices[] = \Fossibot\ValueObjects\Device::fromApiResponse($deviceData);
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to parse device', [
                            'error' => $e->getMessage(),
                            'device_data' => $deviceData,
                        ]);
                    }
                }

                $this->logger->info('Devices discovered via HTTP API', [
                    'count' => count($devices),
                ]);

                return $devices;
            },
            function (\Exception $e) {
                $this->logger->error('Device discovery failed', [
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                throw new \RuntimeException('Device discovery failed: ' . $e->getMessage(), 0, $e);
            }
        );
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Creates configured HTTP Browser for async API calls.
     *
     * Configures DNS resolver and socket connector for reliable HTTP requests.
     */
    private function createBrowser(): \React\Http\Browser
    {
        $this->logger->debug('createBrowser: Starting', [
            'loop_class' => get_class($this->loop),
        ]);

        // Configure DNS resolver to use Google DNS (8.8.8.8)
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $dns = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);

        $this->logger->debug('createBrowser: DNS resolver created', [
            'dns_class' => get_class($dns),
        ]);

        // TLS context for HTTPS certificate validation
        $context = [
            'tls' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => __DIR__ . '/../../config/cacert.pem'
            ]
        ];

        // Create socket connector with TLS context, DNS resolver and timeout
        $socketConnector = new \React\Socket\Connector($context + [
            'dns' => $dns,
            'timeout' => 15.0,
        ]);

        $this->logger->debug('createBrowser: Socket connector created', [
            'connector_class' => get_class($socketConnector),
        ]);

        // Create Browser with configured connector
        $browser = new \React\Http\Browser($socketConnector, $this->loop);

        $this->logger->debug('createBrowser: Browser created', [
            'browser_class' => get_class($browser),
        ]);

        return $browser;
    }

    /**
     * Generates HMAC-MD5 signature for API requests.
     *
     * @param array $data Request data to sign
     * @return string HMAC-MD5 signature (hex)
     */
    private function generateSignature(array $data): string
    {
        // Sort keys alphabetically and filter empty values
        $items = [];
        foreach (array_keys($data) as $key) {
            if (!empty($data[$key])) {
                $items[] = $key . '=' . $data[$key];
            }
        }
        sort($items);

        $queryString = implode('&', $items);

        return hash_hmac('md5', $queryString, \Fossibot\Config::getClientSecret());
    }

    /**
     * Generates unique device ID (32-char hex string).
     *
     * Emulates Android device identifier for API compatibility.
     *
     * @return string 32-character hexadecimal device ID
     */
    private function generateDeviceId(): string
    {
        return bin2hex(random_bytes(16));
    }
}