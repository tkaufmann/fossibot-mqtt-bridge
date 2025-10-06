<?php

declare(strict_types=1);

namespace Fossibot;

use Fossibot\ValueObjects\AnonymousAuthRequest;
use Fossibot\ValueObjects\AnonymousToken;
use Fossibot\ValueObjects\AuthState;
use Fossibot\ValueObjects\DeviceInfo;
use Fossibot\ValueObjects\LoginRequest;
use Fossibot\ValueObjects\LoginToken;
use Fossibot\ValueObjects\MqttTokenRequest;
use Fossibot\ValueObjects\MqttToken;
use Fossibot\Device\Device;
use Fossibot\ValueObjects\DeviceListRequest;
use Fossibot\Commands\Command;
use Fossibot\Commands\CommandResponseType;
use Fossibot\Contracts\ResponseListener;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Exception;
use InvalidArgumentException;

/**
 * Manages Fossibot API authentication through 4 stages.
 *
 * Stage 1 (s1): Anonymous Authorization
 * - ✅ IMPLEMENTED: s1_performAnonymousAuth(), s1_generateRequest(), s1_generateSignature(), s1_sendRequest(), s1_parseResponse(), s1_handleError()
 * - Generates anonymous token valid for 10 minutes (600 seconds)
 * - Required for all subsequent API calls
 *
 * Stage 2 (s2): User Login with email/password
 * - TODO: s2_performLogin(), s2_generateRequest(), s2_parseResponse(), s2_handleError()
 * - Requires: AnonymousToken + email/password + DeviceInfo object
 * - Returns: LoginToken (user-specific authentication)
 * - Uses same endpoint with method: "serverless.function.runtime.invoke"
 * - Function target: "router", URL: "user/pub/login"
 *
 * Stage 3 (s3): MQTT Token acquisition
 * - TODO: s3_performMqttAuth(), s3_generateRequest(), s3_parseResponse(), s3_handleError()
 * - Requires: AnonymousToken + LoginToken
 * - Returns: MqttToken for WebSocket authentication
 * - Function target: "router", URL: "common/emqx.getAccessToken"
 *
 * Stage 4 (s4): Device Discovery via MQTT
 * - TODO: s4_connectMqtt(), s4_getDevices(), s4_handleError()
 * - Establishes WebSocket connection to mqtt.sydpower.com:8083/mqtt
 * - Username: MqttToken, Password: "helloyou"
 * - Discovers available devices and returns Device[] array
 * - Sets authState to FULLY_CONNECTED on success
 *
 * State Management:
 * - AuthState enum tracks current progress through stages
 * - isConnected() returns true only when FULLY_CONNECTED (all 4 stages complete)
 * - hasAnonymousToken(), isInStage1() etc. for granular state checking
 *
 * Error Handling:
 * - Each stage has specific error handling with detailed logging
 * - cURL errors mapped to specific messages (timeout, DNS, SSL, etc.)
 * - HTTP errors mapped to API-specific meanings (401=auth failed, 429=rate limit, etc.)
 * - Sets authState to FAILED on any error
 *
 * Required Value Objects:
 * - ✅ AnonymousAuthRequest, AnonymousToken (Stage 1)
 * - TODO: LoginRequest, LoginToken (Stage 2)
 * - TODO: MqttTokenRequest, MqttToken (Stage 3)
 * - TODO: Device (Stage 4)
 */
final class Connection
{
    private const DEFAULT_PAGE_SIZE = 100;
    private const API_REQUEST_TIMEOUT = 15;

    private LoggerInterface $logger;
    private AuthState $authState = AuthState::DISCONNECTED;
    private ?AnonymousToken $anonymousToken = null;
    private ?LoginToken $loginToken = null;
    private ?MqttToken $mqttToken = null;
    private ?MqttWebSocketClient $mqttClient = null;
    private array $devices = []; // Device[]
    private string $deviceId;

    public function __construct(
        private readonly string $email,
        private readonly string $password,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->deviceId = $this->generateDeviceId();
    }

    public function connect(): void
    {
        $this->logger->info('Starting Fossibot API connection process');

        $this->anonymousToken = $this->s1_performAnonymousAuth();
        $this->logger->info('Stage 1 completed: Anonymous token acquired');

        $this->loginToken = $this->s2_performLogin();
        $this->logger->info('Stage 2 completed: Login token acquired');

        $this->mqttToken = $this->s3_performMqttAuth();
        $this->logger->info('Stage 3 completed: MQTT token acquired');

        $this->authState = AuthState::FULLY_CONNECTED;
    }

    /**
     * Performs only authentication (Stages 1-3) without establishing MQTT connection.
     * Used by AsyncCloudClient to obtain tokens for async WebSocket connection.
     */
    public function authenticateOnly(): void
    {
        $this->logger->info('Starting Fossibot API authentication (token acquisition only)');

        $this->anonymousToken = $this->s1_performAnonymousAuth();
        $this->logger->debug('Stage 1 completed: Anonymous token acquired');

        $this->loginToken = $this->s2_performLogin();
        $this->logger->debug('Stage 2 completed: Login token acquired');

        $this->mqttToken = $this->s3_performMqttAuth();
        $this->logger->debug('Stage 3 completed: MQTT token acquired');

        $this->authState = AuthState::STAGE3_COMPLETED;
    }

    public function isConnected(): bool
    {
        return $this->authState === AuthState::FULLY_CONNECTED;
    }

    public function getAuthState(): AuthState
    {
        return $this->authState;
    }

    public function hasAnonymousToken(): bool
    {
        return $this->anonymousToken !== null;
    }

    public function hasLoginToken(): bool
    {
        return $this->loginToken !== null;
    }

    public function hasMqttToken(): bool
    {
        return $this->mqttToken !== null;
    }

    public function getMqttToken(): array
    {
        if ($this->mqttToken === null) {
            throw new RuntimeException('MQTT token not available. Call connect() first.');
        }

        return [
            'username' => $this->mqttToken->accessToken,
            'password' => 'helloyou',
            'token' => $this->mqttToken->accessToken,
        ];
    }

    public function hasMqttClient(): bool
    {
        return $this->mqttClient !== null && $this->mqttClient->isConnected();
    }

    public function hasDevices(): bool
    {
        return !empty($this->devices);
    }


    public function isInStage1(): bool
    {
        return $this->authState === AuthState::STAGE1_IN_PROGRESS;
    }

    public function isInStage2(): bool
    {
        return $this->authState === AuthState::STAGE2_IN_PROGRESS;
    }

    public function isInStage3(): bool
    {
        return $this->authState === AuthState::STAGE3_IN_PROGRESS;
    }

    public function isInStage4(): bool
    {
        return $this->authState === AuthState::STAGE4_IN_PROGRESS;
    }

    // Stage 1: Anonymous Authorization
    private function s1_performAnonymousAuth(): AnonymousToken
    {
        try {
            $this->authState = AuthState::STAGE1_IN_PROGRESS;
            $this->logger->debug('Starting Stage 1: Anonymous Authorization');

            $request = $this->s1_generateRequest();
            $this->logger->debug('Generated anonymous auth request', [
                'method'    => $request->method,
                'timestamp' => $request->timestamp,
            ]);

            $signature = $this->s1_generateSignature($request);
            $this->logger->debug('Generated HMAC-MD5 signature', [
                'signature_length' => strlen($signature),
            ]);

            $response = $this->s1_sendRequest($request, $signature);
            $token    = $this->s1_parseResponse($response);

            $this->authState = AuthState::STAGE1_COMPLETED;
            $this->logger->debug('Stage 1 completed successfully');

            return $token;
        } catch (Exception $e) {
            $this->s1_handleError($e);
        }
    }

    private function s1_generateRequest(): AnonymousAuthRequest
    {
        return new AnonymousAuthRequest(
            method: "serverless.auth.user.anonymousAuthorize",
            params: "{}",
            spaceId: Config::getSpaceId(),
            timestamp: (int) ( microtime(true) * 1000 )
        );
    }

    private function s1_generateSignature(AnonymousAuthRequest $request): string
    {
        return $this->generateSignature($request->toArray());
    }

    private function generateSignature(array $data): string
    {
        // Sort keys alphabetically and filter empty values
        $items = [];
        foreach (array_keys($data) as $key) {
            if (! empty($data[ $key ])) {
                $items[] = $key . '=' . $data[ $key ];
            }
        }
        sort($items);

        $queryString = implode('&', $items);

        return hash_hmac('md5', $queryString, Config::getClientSecret());
    }

    private function s1_sendRequest(AnonymousAuthRequest $request, string $signature): array
    {
        $headers = [
            'Content-Type: application/json',
            'x-serverless-sign: ' . $signature,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => Config::getApiEndpoint(),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($request->toArray()),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADER         => true,
        ]);

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if (curl_errno($ch)) {
            $error    = curl_error($ch);
            $errorNum = curl_errno($ch);
            curl_close($ch);

            $errorMessage = match ($errorNum) {
                CURLE_OPERATION_TIMEDOUT => "API request timed out after 15 seconds",
                CURLE_COULDNT_RESOLVE_HOST => "Could not resolve API host: api.next.bspapp.com",
                CURLE_COULDNT_CONNECT => "Could not connect to API server",
                CURLE_SSL_CONNECT_ERROR => "SSL connection failed",
                default => "cURL error ({$errorNum}): {$error}",
            };

            $this->logger->error('cURL request failed', [
                'error_number' => $errorNum,
                'error_message' => $error,
                'endpoint' => Config::getApiEndpoint(),
            ]);

            throw new RuntimeException($errorMessage);
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            $errorMessage = match ($httpCode) {
                401 => "API authentication failed - invalid signature or credentials",
                403 => "API access forbidden - check API credentials",
                404 => "API endpoint not found",
                429 => "API rate limit exceeded - too many requests",
                500 => "API server internal error",
                502, 503, 504 => "API server temporarily unavailable",
                default => "HTTP error: {$httpCode}",
            };

            $this->logger->error('HTTP request failed', [
                'http_code' => $httpCode,
                'endpoint' => Config::getApiEndpoint(),
            ]);

            throw new RuntimeException($errorMessage);
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody    = substr($response, $headerSize);

        $this->logger->debug('Response Headers:', [ 'headers' => $responseHeaders ]);
        $this->logger->debug('Response Body:', [ 'body' => $responseBody ]);

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function s1_parseResponse(array $response): AnonymousToken
    {
        if (! isset($response['data'])) {
            $this->logger->error('Invalid API response structure', [
                'response' => $response,
            ]);
            throw new RuntimeException("API response missing 'data' field");
        }

        if (! isset($response['data']['accessToken'])) {
            $this->logger->error('Missing accessToken in API response', [
                'response_data' => $response['data'],
            ]);
            throw new RuntimeException("API response missing accessToken");
        }

        $token = $response['data']['accessToken'];
        $this->logger->debug('Anonymous Token acquired:', [
            'token_length'  => strlen($token),
            'token_prefix'  => substr($token, 0, 20) . '...',
            'full_response' => $response,
        ]);

        return new AnonymousToken($token);
    }

    private function s1_handleError(Exception $e): void
    {
        $this->authState = AuthState::FAILED;
        $this->logger->error('Stage 1 failed', [ 'error' => $e->getMessage() ]);
        throw $e;
    }

    // Stage 2: User Login
    private function s2_performLogin(): LoginToken
    {
        try {
            $this->authState = AuthState::STAGE2_IN_PROGRESS;
            $this->logger->debug('Starting Stage 2: User Login');

            $request = $this->s2_generateRequest();
            $this->logger->debug('Generated login request', [
                'email' => $this->email,
                'device_id_length' => strlen($this->deviceId),
            ]);

            $signature = $this->generateSignature($request->toArray());
            $this->logger->debug('Generated login signature', [
                'signature_length' => strlen($signature),
            ]);

            $response = $this->s2_sendRequest($request, $signature);
            $token = $this->s2_parseResponse($response);

            $this->authState = AuthState::STAGE2_COMPLETED;
            $this->logger->debug('Stage 2 completed successfully');

            return $token;
        } catch (Exception $e) {
            $this->s2_handleError($e);
        }
    }

    private function s2_generateRequest(): LoginRequest
    {
        $deviceInfo = new DeviceInfo(deviceId: $this->deviceId);

        $functionArgs = [
            '$url' => 'user/pub/login',
            'data' => [
                'locale' => 'en',
                'username' => $this->email,
                'password' => $this->password,
            ],
            'clientInfo' => $deviceInfo->toArray(),
            'uniIdToken' => $this->anonymousToken->accessToken,
        ];

        $params = [
            'functionTarget' => 'router',
            'functionArgs' => $functionArgs,
        ];

        return new LoginRequest(
            method: "serverless.function.runtime.invoke",
            params: json_encode($params),
            spaceId: Config::getSpaceId(),
            timestamp: (int) ( microtime(true) * 1000 ),
            token: $this->anonymousToken->accessToken
        );
    }

    private function s2_sendRequest(LoginRequest $request, string $signature): array
    {
        $headers = [
            'Content-Type: application/json',
            'x-serverless-sign: ' . $signature,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => Config::getApiEndpoint(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request->toArray()),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $errorNum = curl_errno($ch);
            curl_close($ch);

            $errorMessage = match ($errorNum) {
                CURLE_OPERATION_TIMEDOUT => "Login request timed out after 15 seconds",
                CURLE_COULDNT_RESOLVE_HOST => "Could not resolve API host for login",
                CURLE_COULDNT_CONNECT => "Could not connect to API server for login",
                CURLE_SSL_CONNECT_ERROR => "SSL connection failed during login",
                default => "Login cURL error ({$errorNum}): {$error}",
            };

            $this->logger->error('Login request failed', [
                'error_number' => $errorNum,
                'error_message' => $error,
                'endpoint' => Config::getApiEndpoint(),
                'email' => $this->email,
            ]);

            throw new RuntimeException($errorMessage);
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            $errorMessage = match ($httpCode) {
                401 => "Login failed - invalid email or password",
                403 => "Login forbidden - account may be locked",
                404 => "Login endpoint not found",
                429 => "Too many login attempts - rate limited",
                500 => "Server error during login",
                502, 503, 504 => "Login service temporarily unavailable",
                default => "Login HTTP error: {$httpCode}",
            };

            $this->logger->error('Login HTTP request failed', [
                'http_code' => $httpCode,
                'endpoint' => Config::getApiEndpoint(),
                'email' => $this->email,
            ]);

            throw new RuntimeException($errorMessage);
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        $this->logger->debug('Login Response Headers:', [ 'headers' => $responseHeaders ]);
        $this->logger->debug('Login Response Body:', [ 'body' => $responseBody ]);

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Login JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function s2_parseResponse(array $response): LoginToken
    {
        if (! isset($response['data'])) {
            $this->logger->error('Invalid login response structure', [
                'response' => $response,
            ]);
            throw new RuntimeException("Login response missing 'data' field");
        }

        if (! isset($response['data']['token'])) {
            $this->logger->error('Missing login token in response', [
                'response_data' => $response['data'],
            ]);
            throw new RuntimeException("Login response missing token");
        }

        $token = $response['data']['token'];
        $this->logger->debug('Login Token acquired:', [
            'token_length' => strlen($token),
            'token_prefix' => substr($token, 0, 20) . '...',
            'full_response' => $response,
        ]);

        return new LoginToken($token);
    }

    private function s2_handleError(Exception $e): void
    {
        $this->authState = AuthState::FAILED;
        $this->logger->error('Stage 2 failed', [ 'error' => $e->getMessage(), 'email' => $this->email ]);
        throw $e;
    }

    // Stage 3: MQTT Token acquisition
    private function s3_performMqttAuth(): MqttToken
    {
        try {
            $this->authState = AuthState::STAGE3_IN_PROGRESS;
            $this->logger->debug('Starting Stage 3: MQTT Token acquisition');

            $request = $this->s3_generateRequest();
            $this->logger->debug('Generated MQTT token request', [
                'endpoint' => 'common/emqx.getAccessToken',
            ]);

            $signature = $this->generateSignature($request->toArray());
            $this->logger->debug('Generated MQTT token signature', [
                'signature_length' => strlen($signature),
            ]);

            $response = $this->s3_sendRequest($request, $signature);
            $token = $this->s3_parseResponse($response);

            $this->authState = AuthState::STAGE3_COMPLETED;
            $this->logger->debug('Stage 3 completed successfully');

            return $token;
        } catch (Exception $e) {
            $this->s3_handleError($e);
        }
    }

    private function s3_generateRequest(): MqttTokenRequest
    {
        $deviceInfo = new DeviceInfo(deviceId: $this->deviceId);

        $functionArgs = [
            '$url' => 'common/emqx.getAccessToken',
            'data' => [
                'locale' => 'en',
            ],
            'clientInfo' => $deviceInfo->toArray(),
            'uniIdToken' => $this->loginToken->token,
        ];

        $params = [
            'functionTarget' => 'router',
            'functionArgs' => $functionArgs,
        ];

        return new MqttTokenRequest(
            method: "serverless.function.runtime.invoke",
            params: json_encode($params),
            spaceId: Config::getSpaceId(),
            timestamp: (int) ( microtime(true) * 1000 ),
            token: $this->anonymousToken->accessToken
        );
    }

    private function s3_sendRequest(MqttTokenRequest $request, string $signature): array
    {
        $headers = [
            'Content-Type: application/json',
            'x-serverless-sign: ' . $signature,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => Config::getApiEndpoint(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request->toArray()),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $errorNum = curl_errno($ch);
            curl_close($ch);

            $errorMessage = match ($errorNum) {
                CURLE_OPERATION_TIMEDOUT => "MQTT token request timed out after 15 seconds",
                CURLE_COULDNT_RESOLVE_HOST => "Could not resolve API host for MQTT token",
                CURLE_COULDNT_CONNECT => "Could not connect to API server for MQTT token",
                CURLE_SSL_CONNECT_ERROR => "SSL connection failed during MQTT token request",
                default => "MQTT token cURL error ({$errorNum}): {$error}",
            };

            $this->logger->error('MQTT token request failed', [
                'error_number' => $errorNum,
                'error_message' => $error,
                'endpoint' => Config::getApiEndpoint(),
            ]);

            throw new RuntimeException($errorMessage);
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            $errorMessage = match ($httpCode) {
                401 => "MQTT token request failed - invalid authentication",
                403 => "MQTT token request forbidden - check token validity",
                404 => "MQTT token endpoint not found",
                429 => "Too many MQTT token requests - rate limited",
                500 => "Server error during MQTT token request",
                502, 503, 504 => "MQTT token service temporarily unavailable",
                default => "MQTT token HTTP error: {$httpCode}",
            };

            $this->logger->error('MQTT token HTTP request failed', [
                'http_code' => $httpCode,
                'endpoint' => Config::getApiEndpoint(),
            ]);

            throw new RuntimeException($errorMessage);
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        $this->logger->debug('MQTT Token Response Headers:', [ 'headers' => $responseHeaders ]);
        $this->logger->debug('MQTT Token Response Body:', [ 'body' => $responseBody ]);

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("MQTT token JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function s3_parseResponse(array $response): MqttToken
    {
        if (! isset($response['data'])) {
            $this->logger->error('Invalid MQTT token response structure', [
                'response' => $response,
            ]);
            throw new RuntimeException("MQTT token response missing 'data' field");
        }

        if (! isset($response['data']['access_token'])) {
            $this->logger->error('Missing MQTT access_token in response', [
                'response_data' => $response['data'],
            ]);
            throw new RuntimeException("MQTT token response missing access_token");
        }

        $token = $response['data']['access_token'];
        $this->logger->debug('MQTT Token acquired:', [
            'token_length' => strlen($token),
            'token_prefix' => substr($token, 0, 20) . '...',
            'full_response' => $response,
        ]);

        return new MqttToken($token);
    }

    private function s3_handleError(Exception $e): void
    {
        $this->authState = AuthState::FAILED;
        $this->logger->error('Stage 3 failed', [ 'error' => $e->getMessage() ]);
        throw $e;
    }

    // Stage 4: MQTT WebSocket Connection
    private function s4_connectMqtt(): MqttWebSocketClient
    {
        try {
            $this->authState = AuthState::STAGE4_IN_PROGRESS;
            $this->logger->debug('Starting Stage 4: MQTT WebSocket connection');

            $clientId = $this->s4_generateClientId();
            $this->logger->debug('Generated MQTT client ID', [
                'client_id' => $clientId,
            ]);

            $client = new MqttWebSocketClient($this->logger);

            $this->logger->debug('Attempting MQTT WebSocket connection', [
                'host' => Config::getMqttHost(),
                'port' => 8083,
                'username' => substr($this->mqttToken->accessToken, 0, 20) . '...',
                'client_id' => $clientId,
            ]);

            $client->connect(
                Config::getMqttHost(),        // Host
                8083,                         // Port
                $clientId,                    // Client ID
                $this->mqttToken->accessToken, // Username = MQTT token
                "helloyou"                    // Password = fixed constant
            );

            $this->logger->debug('MQTT WebSocket connection established successfully');

            // First discover devices (set FULLY_CONNECTED temporarily for device discovery)
            $this->authState = AuthState::FULLY_CONNECTED;
            $devices = $this->getDevices();
            $this->logger->debug('Device discovery completed', ['device_count' => count($devices)]);

            // Setup response subscriptions for all discovered devices
            $this->setupResponseSubscriptions($client);

            $this->authState = AuthState::FULLY_CONNECTED;
            $this->logger->debug('Stage 4 completed successfully - FULLY_CONNECTED');

            return $client;
        } catch (Exception $e) {
            $this->s4_handleError($e);
        }
    }

    private function s4_generateClientId(): string
    {
        // Generate shorter hex string for MQTT 23-char limit
        // Format: c_{8-hex}_{6-timestamp} = 1+1+8+1+6 = 17 chars (within 23 limit)
        $hexString = '';
        for ($i = 0; $i < 8; $i++) {
            $hexString .= dechex(random_int(0, 15));
        }

        // Use last 6 digits of timestamp to keep it short
        $timestampMs = (int) ( microtime(true) * 1000 );
        $shortTimestamp = substr((string) $timestampMs, -6);

        return "c_{$hexString}_{$shortTimestamp}";
    }

    private function s4_handleError(Exception $e): void
    {
        $this->authState = AuthState::FAILED;

        $errorType = match (true) {
            str_contains($e->getMessage(), 'timeout') => 'MQTT WebSocket connection timeout',
            str_contains($e->getMessage(), 'resolve') => 'MQTT host DNS resolution failed',
            str_contains($e->getMessage(), 'WebSocket') => 'MQTT WebSocket handshake failed',
            str_contains($e->getMessage(), 'MQTT') => 'MQTT protocol negotiation failed',
            str_contains($e->getMessage(), 'InvalidArgument') => 'MQTT connection parameters invalid',
            default => 'MQTT WebSocket connection failed',
        };

        $this->logger->error('Stage 4 failed', [
            'error' => $e->getMessage(),
            'error_type' => $errorType,
            'error_class' => get_class($e),
            'mqtt_host' => Config::getMqttHost(),
        ]);

        throw new RuntimeException($errorType . ': ' . $e->getMessage(), 0, $e);
    }

    /**
     * Setup MQTT response subscriptions for all devices.
     *
     * Subscribes to response topics as specified in SYSTEM.md:
     * - {device_mac}/device/response/client/+ (catches /04 and /data)
     * - {device_mac}/device/response/state
     *
     * @param MqttWebSocketClient $client Connected MQTT client
     * @throws \RuntimeException If subscription fails
     */
    private function setupResponseSubscriptions(MqttWebSocketClient $client): void
    {
        try {
            $devices = $this->getDevices();
            $subscriptionCount = 0;

            foreach ($devices as $device) {
                $mac = $device->getMqttId();
                $responseTopics = [
                    "{$mac}/device/response/client/+",    // Catches both /04 and /data
                    // NOTE: Temporarily removed state subscription due to MQTT race condition
                    // TODO: Fix MQTT parser buffer handling and re-enable state subscription
                ];

                foreach ($responseTopics as $topic) {
                    $client->subscribe($topic);
                    $subscriptionCount++;
                    $this->logger->debug('Subscribed to response topic', [
                        'topic' => $topic,
                        'device_mac' => $mac
                    ]);
                }
            }

            // Setup message callback for response handling
            $this->setupResponseCallback($client);

            $this->logger->info('MQTT response subscriptions setup completed', [
                'device_count' => count($devices),
                'subscription_count' => $subscriptionCount,
                'subscribed_devices' => array_map(fn(Device $d) => $d->getMqttId(), $devices)
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to setup response subscriptions', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
            throw new RuntimeException("Failed to setup MQTT response subscriptions: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Setup response callback to handle incoming MQTT messages.
     *
     * @param MqttWebSocketClient $client Connected MQTT client
     */
    private function setupResponseCallback(MqttWebSocketClient $client): void
    {
        // Create response listener for message handling
        $responseListener = new class ($this->logger) implements ResponseListener {
            private LoggerInterface $logger;
            private Connection $connection;

            public function __construct(LoggerInterface $logger)
            {
                $this->logger = $logger;
            }

            public function setConnection(Connection $connection): void
            {
                $this->connection = $connection;
            }

            public function onResponse(string $topic, array $registers, string $macAddress): void
            {
                if (isset($this->connection)) {
                    // Convert registers back to payload format for existing handleMqttMessage method
                    $payload = $this->registersToPayload($registers);
                    $this->connection->handleMqttMessage($topic, $payload);
                }
            }

            public function onError(string $topic, string $error, string $macAddress): void
            {
                $this->logger->error('MQTT response error', [
                    'topic' => $topic,
                    'error' => $error,
                    'mac_address' => $macAddress
                ]);
            }

            public function onConnectionStateChanged(string $topic, bool $connected, string $macAddress): void
            {
                $this->logger->debug('MQTT connection state changed', [
                    'topic' => $topic,
                    'connected' => $connected,
                    'mac_address' => $macAddress
                ]);
            }

            private function registersToPayload(array $registers): string
            {
                // Convert register array back to binary payload
                // Each register = 2 bytes, high byte first
                $payload = '';
                for ($i = 0; $i < 81; $i++) {
                    $value = $registers[$i] ?? 0;
                    $high = ($value >> 8) & 0xFF;
                    $low = $value & 0xFF;
                    $payload .= chr($high) . chr($low);
                }
                return $payload;
            }
        };

        $responseListener->setConnection($this);
        $client->addResponseListener($responseListener);

        $this->logger->debug('Response callback setup completed');
    }

    /**
     * Handle incoming MQTT messages and route to appropriate handlers.
     *
     * @param string $topic MQTT topic the message was received on
     * @param string $payload Binary message payload
     */
    public function handleMqttMessage(string $topic, string $payload): void
    {
        $this->logger->debug('Received MQTT message', [
            'topic' => $topic,
            'payload_size' => strlen($payload)
        ]);

        // Route based on topic pattern as per SYSTEM.md
        if (preg_match('/([0-9a-f]{12})\/device\/response\/client\/04/', $topic, $matches)) {
            $this->handleOutputCommandResponse($matches[1], $payload);
        } elseif (preg_match('/([0-9a-f]{12})\/device\/response\/client\/data/', $topic, $matches)) {
            $this->handleSettingsCommandResponse($matches[1], $payload);
        } elseif (preg_match('/([0-9a-f]{12})\/device\/response\/state/', $topic, $matches)) {
            $this->handleStateResponse($matches[1], $payload);
        } else {
            $this->logger->warning('Received message on unhandled topic', [
                'topic' => $topic,
                'payload_size' => strlen($payload)
            ]);
        }
    }

    /**
     * Handle output command response from /client/04 topic.
     *
     * Parses Register 41 bitfield for output states as per SYSTEM.md.
     * Expected: 81 registers, Register 41 contains output status bitfield.
     *
     * @param string $macAddress Device MAC address (12 hex chars)
     * @param string $payload Binary MQTT payload containing 81 registers
     */
    private function handleOutputCommandResponse(string $macAddress, string $payload): void
    {
        try {
            $registers = $this->parseRegisterPayload($payload);

            if (count($registers) !== 81) {
                $this->logger->warning('Invalid register count in /client/04 response', [
                    'mac' => $macAddress,
                    'expected' => 81,
                    'received' => count($registers),
                    'topic' => "{$macAddress}/device/response/client/04"
                ]);
                return;
            }

            $outputStates = $this->parseOutputBitfield($registers[41]);

            $this->logger->info('Output command response received', [
                'mac' => $macAddress,
                'topic' => "{$macAddress}/device/response/client/04",
                'register_41_value' => $registers[41],
                'register_41_binary' => str_pad(decbin($registers[41]), 16, '0', STR_PAD_LEFT),
                'usb_output' => $outputStates['usb'],
                'dc_output' => $outputStates['dc'],
                'ac_output' => $outputStates['ac'],
                'led_output' => $outputStates['led']
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to handle output command response', [
                'mac' => $macAddress,
                'payload_size' => strlen($payload),
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
        }
    }

    /**
     * Handle settings command response from /client/data topic.
     *
     * Parses settings from registers 20, 57, 59-68 as per SYSTEM.md.
     * Expected: 81 registers, focus on power management and timeout settings.
     *
     * @param string $macAddress Device MAC address (12 hex chars)
     * @param string $payload Binary MQTT payload containing 81 registers
     */
    private function handleSettingsCommandResponse(string $macAddress, string $payload): void
    {
        try {
            $registers = $this->parseRegisterPayload($payload);

            if (count($registers) !== 81) {
                $this->logger->warning('Invalid register count in /client/data response', [
                    'mac' => $macAddress,
                    'expected' => 81,
                    'received' => count($registers),
                    'topic' => "{$macAddress}/device/response/client/data"
                ]);
                return;
            }

            // Parse power management settings as per SYSTEM.md
            $settings = [
                'max_charging_current' => $registers[20] ?? null,    // 1-20 Amperes
                'discharge_limit' => $registers[66] ?? null,         // 0-1000 tenths (10.0% = 100)
                'ac_charge_limit' => $registers[67] ?? null,         // 0-1000 tenths (100.0% = 1000)
                'ac_silent' => $registers[57] ?? null,               // Boolean: 1=enabled, 0=disabled
                'usb_standby' => $registers[59] ?? null,             // Values: 0,3,5,10,30 minutes
                'ac_standby' => $registers[60] ?? null,              // Values: 0,480,960,1440 minutes
                'dc_standby' => $registers[61] ?? null,              // Values: 0,480,960,1440 minutes
                'screen_rest' => $registers[62] ?? null,             // Values: 0,180,300,600,1800 seconds
                'sleep_time' => $registers[68] ?? null               // Values: 5,10,30,480 minutes (NEVER 0!)
            ];

            // Convert tenths to percentages for readability
            $readableSettings = $settings;
            if (isset($settings['discharge_limit'])) {
                $readableSettings['discharge_limit_percent'] = $settings['discharge_limit'] / 10.0;
            }
            if (isset($settings['ac_charge_limit'])) {
                $readableSettings['ac_charge_limit_percent'] = $settings['ac_charge_limit'] / 10.0;
            }

            $this->logger->info('Settings command response received', [
                'mac' => $macAddress,
                'topic' => "{$macAddress}/device/response/client/data",
                'settings_raw' => array_filter($settings, fn($v) => $v !== null),
                'settings_readable' => array_filter($readableSettings, fn($v) => $v !== null)
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to handle settings command response', [
                'mac' => $macAddress,
                'payload_size' => strlen($payload),
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
        }
    }

    /**
     * Handle state response from /state topic.
     *
     * @param string $macAddress Device MAC address (12 hex chars)
     * @param string $payload Binary MQTT payload
     */
    private function handleStateResponse(string $macAddress, string $payload): void
    {
        $this->logger->debug('State response received', [
            'mac' => $macAddress,
            'topic' => "{$macAddress}/device/response/state",
            'payload_size' => strlen($payload)
        ]);

        // TODO: Implement state response parsing if needed
        // For now, just log the reception
    }

    /**
     * Parse binary MQTT payload to 81 register array.
     *
     * Converts binary payload to array of 16-bit registers.
     * Each register = 2 bytes, high byte first (big-endian).
     *
     * @param string $payload Binary MQTT payload
     * @return array Array of 81 registers (16-bit integers)
     * @throws \InvalidArgumentException If payload size is invalid
     */
    private function parseRegisterPayload(string $payload): array
    {
        $expectedSize = 81 * 2; // 81 registers × 2 bytes each = 162 bytes
        $actualSize = strlen($payload);

        if ($actualSize < $expectedSize) {
            throw new InvalidArgumentException(
                "Payload too small: expected at least {$expectedSize} bytes, got {$actualSize}"
            );
        }

        $bytes = unpack('C*', $payload);
        $registers = [];

        for ($i = 0; $i < 81; $i++) {
            $high = $bytes[($i * 2) + 1] ?? 0;
            $low = $bytes[($i * 2) + 2] ?? 0;
            $registers[$i] = ($high << 8) | $low;  // Big-endian: high byte first
        }

        return $registers;
    }

    /**
     * Parse Register 41 output bitfield as per SYSTEM.md.
     *
     * Reads binary string from right (LSB first):
     * - USB Output = Bit 6
     * - DC Output = Bit 5
     * - AC Output = Bit 4
     * - LED Output = Bit 3
     *
     * @param int $register41 Register 41 value (16-bit integer)
     * @return array Associative array with output states
     */
    private function parseOutputBitfield(int $register41): array
    {
        // Convert to 16-bit binary string, padded with leading zeros
        $binary = str_pad(decbin($register41), 16, '0', STR_PAD_LEFT);

        // Read from right (LSB first) as per SYSTEM.md
        return [
            'usb' => $binary[15 - 6] === '1',  // Bit 6 from right
            'dc' => $binary[15 - 5] === '1',   // Bit 5 from right
            'ac' => $binary[15 - 4] === '1',   // Bit 4 from right
            'led' => $binary[15 - 3] === '1'   // Bit 3 from right
        ];
    }

    /**
     * Retrieve list of devices associated with user account.
     *
     * @return Device[] Array of Device objects
     * @throws \RuntimeException If connection not established or API fails
     * @throws \InvalidArgumentException If request parameters are invalid
     */
    public function getDevices(): array
    {
        // Requires at least Stage 3 (MQTT token) for API authentication
        if ($this->authState !== AuthState::STAGE3_COMPLETED && $this->authState !== AuthState::FULLY_CONNECTED) {
            throw new RuntimeException('Cannot get devices: Authentication incomplete. Call authenticateOnly() or connect() first.');
        }

        try {
            $this->logger->debug('Starting device list retrieval');

            $requestData = $this->buildDeviceListRequest();
            $response = $this->sendApiRequest($requestData);
            $devices = $this->parseDeviceListResponse($response);

            $this->logger->debug('Device list retrieved successfully', [
                'device_count' => count($devices),
                'devices' => array_map(fn(Device $d) => $d->getDeviceName(), $devices),
            ]);

            $this->devices = $devices; // Cache the devices
            return $devices;
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Invalid device list request parameters', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (RuntimeException $e) {
            $this->handleDeviceListError($e);
        }
    }

    private function buildDeviceListRequest(): array
    {
        $deviceListRequest = new DeviceListRequest();
        $clientInfo = new DeviceInfo(deviceId: $this->deviceId);

        $functionArgs = $deviceListRequest->toFunctionArgs($clientInfo, $this->loginToken->token);
        $params = json_encode([
            'functionTarget' => 'router',
            'functionArgs' => $functionArgs,
        ]);

        $this->logger->debug('Building device list request', [
            'url' => $functionArgs['$url'],
            'page_size' => $functionArgs['data']['pageSize'],
        ]);

        return [
            'method' => 'serverless.function.runtime.invoke',
            'params' => $params,
            'spaceId' => Config::getSpaceId(),
            'timestamp' => (int) ( microtime(true) * 1000 ),
            'token' => $this->anonymousToken->accessToken,
        ];
    }

    /**
     * Parse device list API response into Device objects.
     *
     * @param string $response Raw JSON response from API
     * @return Device[] Array of Device objects
     * @throws \RuntimeException If response format is invalid
     */
    private function parseDeviceListResponse(string $response): array
    {
        $responseData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in device list response: ' . json_last_error_msg());
        }

        if (!isset($responseData['data'])) {
            throw new RuntimeException('Device list response missing data field');
        }

        $rows = $responseData['data']['rows'] ?? [];
        if (empty($rows)) {
            $this->logger->warning('No devices found in account');
            return [];
        }

        $devices = [];
        foreach ($rows as $deviceData) {
            try {
                $devices[] = Device::fromApiResponse($deviceData);
            } catch (Exception $e) {
                $this->logger->warning('Failed to parse device data', [
                    'device_data' => $deviceData,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other devices
            }
        }

        return $devices;
    }

    /**
     * Handle device list retrieval errors with specific error categorization.
     *
     * @param \Exception $e The caught exception
     * @throws \RuntimeException Always throws with categorized error message
     * @return never
     */
    private function handleDeviceListError(Exception $e): never
    {
        $errorType = match (true) {
            str_contains($e->getMessage(), 'timeout') => 'Device list request timeout',
            str_contains($e->getMessage(), 'resolve') => 'DNS resolution failed',
            str_contains($e->getMessage(), 'HTTP') => 'Device list HTTP error',
            str_contains($e->getMessage(), 'JSON') => 'Device list response parsing failed',
            str_contains($e->getMessage(), 'Connection not established') => 'Authentication required',
            default => 'Device list retrieval failed',
        };

        $this->logger->error('Device list retrieval failed', [
            'error' => $e->getMessage(),
            'error_type' => $errorType,
            'error_class' => get_class($e),
        ]);

        throw new RuntimeException($errorType . ': ' . $e->getMessage(), 0, $e);
    }

    private function sendApiRequest(array $data): string
    {
        $signature = $this->generateSignature($data);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => Config::getApiEndpoint(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-serverless-sign: ' . $signature,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("API request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("API request HTTP error: {$httpCode}");
        }

        return $response;
    }

    /**
     * Send a command to a device via MQTT.
     *
     * Converts the command to binary payload and publishes it to the device's
     * MQTT topic. Validates connection state before sending.
     *
     * @param string $macAddress Device MAC address without colons (12 hex chars)
     * @param Command $command Command to execute on the device
     * @throws \RuntimeException If MQTT client not connected or send fails
     * @throws \InvalidArgumentException If MAC address is empty
     */
    public function sendCommand(string $macAddress, Command $command): void
    {
        if (!$this->hasMqttClient()) {
            throw new RuntimeException('Cannot send command: MQTT client not connected. Call connect() first.');
        }

        if (empty($macAddress)) {
            throw new InvalidArgumentException('MAC address cannot be empty');
        }

        $topic = $macAddress . '/client/request/data';
        $payload = $command->getModbusBytes();

        $this->logger->info('Sending MQTT command', [
            'mac_address' => $macAddress,
            'topic' => $topic,
            'command_description' => $command->getDescription(),
            'target_register' => $command->getTargetRegister(),
            'response_type' => $command->getResponseType()->name,
            'payload_hex' => implode(' ', array_map(fn($b) => sprintf('%02X', $b), $payload)),
            'payload_size' => count($payload)
        ]);

        try {
            // Convert byte array to binary string for MQTT
            $binaryPayload = '';
            foreach ($payload as $byte) {
                $binaryPayload .= chr($byte);
            }

            // Publish to MQTT
            $this->mqttClient->publish($topic, $binaryPayload);

            $this->logger->debug('MQTT command sent successfully', [
                'mac_address' => $macAddress,
                'topic' => $topic,
                'command_description' => $command->getDescription()
            ]);

            // Log response expectations based on CommandResponseType
            switch ($command->getResponseType()) {
                case CommandResponseType::IMMEDIATE:
                    $this->logger->info('Command sent - expecting immediate response', [
                        'mac_address' => $macAddress,
                        'command_description' => $command->getDescription(),
                        'expected_response_topic' => "{$macAddress}/device/response/client/04",
                        'expected_timing' => 'Within seconds',
                        'response_content' => 'Register 41 bitfield with output states'
                    ]);
                    break;

                case CommandResponseType::DELAYED:
                    $this->logger->info('Settings command sent - expecting delayed response', [
                        'mac_address' => $macAddress,
                        'command_description' => $command->getDescription(),
                        'expected_response_topic' => "{$macAddress}/device/response/client/data",
                        'expected_timing' => '~30 seconds (periodic update)',
                        'response_content' => 'Settings registers 20, 57, 59-68'
                    ]);
                    break;

                case CommandResponseType::READ_RESPONSE:
                    $this->logger->info('Read command sent - expecting data response', [
                        'mac_address' => $macAddress,
                        'command_description' => $command->getDescription(),
                        'expected_response_topic' => "{$macAddress}/device/response/client/data",
                        'expected_timing' => 'Within seconds',
                        'response_content' => 'All 81 registers'
                    ]);
                    break;

                default:
                    $this->logger->warning('Unknown response type for command', [
                        'mac_address' => $macAddress,
                        'command_description' => $command->getDescription(),
                        'response_type' => $command->getResponseType()->name
                    ]);
                    break;
            }
        } catch (Exception $e) {
            $this->logger->error('MQTT command send failed', [
                'mac_address' => $macAddress,
                'topic' => $topic,
                'command_description' => $command->getDescription(),
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);

            throw new RuntimeException(
                "Failed to send command via MQTT: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function generateDeviceId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
