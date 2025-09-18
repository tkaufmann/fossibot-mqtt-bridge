<?php

declare( strict_types=1 );

namespace Fossibot;

use Fossibot\ValueObjects\AnonymousAuthRequest;
use Fossibot\ValueObjects\AnonymousToken;
use Fossibot\ValueObjects\AuthState;
use Fossibot\ValueObjects\DeviceInfo;
use Fossibot\ValueObjects\LoginRequest;
use Fossibot\ValueObjects\LoginToken;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
final class Connection {

	private LoggerInterface $logger;
	private AuthState $authState = AuthState::DISCONNECTED;
	private ?AnonymousToken $anonymousToken = NULL;
	private ?LoginToken $loginToken = NULL;
	private string $deviceId;

	public function __construct(
		private readonly string $email,
		private readonly string $password,
		?LoggerInterface $logger = NULL
	) {
		$this->logger = $logger ?? new NullLogger();
		$this->deviceId = $this->generateDeviceId();
	}

	public function connect(): void {
		$this->logger->info( 'Starting Fossibot API connection process' );

		$this->anonymousToken = $this->s1_performAnonymousAuth();
		$this->logger->info( 'Stage 1 completed: Anonymous token acquired' );

		$this->loginToken = $this->s2_performLogin();
		$this->logger->info( 'Stage 2 completed: Login token acquired' );
	}

	public function isConnected(): bool {
		return $this->authState === AuthState::FULLY_CONNECTED;
	}

	public function getAuthState(): AuthState {
		return $this->authState;
	}

	public function hasAnonymousToken(): bool {
		return $this->anonymousToken !== NULL;
	}

	public function hasLoginToken(): bool {
		return $this->loginToken !== NULL;
	}

	public function isInStage1(): bool {
		return $this->authState === AuthState::STAGE1_IN_PROGRESS;
	}

	public function isInStage2(): bool {
		return $this->authState === AuthState::STAGE2_IN_PROGRESS;
	}

	// Stage 1: Anonymous Authorization
	private function s1_performAnonymousAuth(): AnonymousToken {
		try {
			$this->authState = AuthState::STAGE1_IN_PROGRESS;
			$this->logger->debug( 'Starting Stage 1: Anonymous Authorization' );

			$request = $this->s1_generateRequest();
			$this->logger->debug( 'Generated anonymous auth request', [
				'method'    => $request->method,
				'timestamp' => $request->timestamp,
			] );

			$signature = $this->s1_generateSignature( $request );
			$this->logger->debug( 'Generated HMAC-MD5 signature', [
				'signature_length' => strlen( $signature ),
			] );

			$response = $this->s1_sendRequest( $request, $signature );
			$token    = $this->s1_parseResponse( $response );

			$this->authState = AuthState::STAGE1_COMPLETED;
			$this->logger->debug( 'Stage 1 completed successfully' );

			return $token;
		} catch ( \Exception $e ) {
			$this->s1_handleError( $e );
		}
	}

	private function s1_generateRequest(): AnonymousAuthRequest {
		return new AnonymousAuthRequest(
			method: "serverless.auth.user.anonymousAuthorize",
			params: "{}",
			spaceId: Config::getSpaceId(),
			timestamp: (int) ( microtime( TRUE ) * 1000 )
		);
	}

	private function s1_generateSignature( AnonymousAuthRequest $request ): string {
		return $this->generateSignature( $request->toArray() );
	}

	private function generateSignature( array $data ): string {
		// Sort keys alphabetically and filter empty values
		$items = [];
		foreach ( array_keys( $data ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$items[] = $key . '=' . $data[ $key ];
			}
		}
		sort( $items );

		$queryString = implode( '&', $items );

		return hash_hmac( 'md5', $queryString, Config::getClientSecret() );
	}

	private function s1_sendRequest( AnonymousAuthRequest $request, string $signature ): array {
		$headers = [
			'Content-Type: application/json',
			'x-serverless-sign: ' . $signature,
		];

		$ch = curl_init();
		curl_setopt_array( $ch, [
			CURLOPT_URL            => Config::getApiEndpoint(),
			CURLOPT_POST           => TRUE,
			CURLOPT_POSTFIELDS     => json_encode( $request->toArray() ),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HEADER         => TRUE,
		] );

		$response   = curl_exec( $ch );
		$httpCode   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$headerSize = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );

		if ( curl_errno( $ch ) ) {
			$error    = curl_error( $ch );
			$errorNum = curl_errno( $ch );
			curl_close( $ch );

			$errorMessage = match ( $errorNum ) {
				CURLE_OPERATION_TIMEDOUT => "API request timed out after 15 seconds",
				CURLE_COULDNT_RESOLVE_HOST => "Could not resolve API host: api.next.bspapp.com",
				CURLE_COULDNT_CONNECT => "Could not connect to API server",
				CURLE_SSL_CONNECT_ERROR => "SSL connection failed",
				default => "cURL error ({$errorNum}): {$error}",
			};

			$this->logger->error( 'cURL request failed', [
				'error_number' => $errorNum,
				'error_message' => $error,
				'endpoint' => Config::getApiEndpoint(),
			] );

			throw new \RuntimeException( $errorMessage );
		}

		curl_close( $ch );

		if ( $httpCode !== 200 ) {
			$errorMessage = match ( $httpCode ) {
				401 => "API authentication failed - invalid signature or credentials",
				403 => "API access forbidden - check API credentials",
				404 => "API endpoint not found",
				429 => "API rate limit exceeded - too many requests",
				500 => "API server internal error",
				502, 503, 504 => "API server temporarily unavailable",
				default => "HTTP error: {$httpCode}",
			};

			$this->logger->error( 'HTTP request failed', [
				'http_code' => $httpCode,
				'endpoint' => Config::getApiEndpoint(),
			] );

			throw new \RuntimeException( $errorMessage );
		}

		$responseHeaders = substr( $response, 0, $headerSize );
		$responseBody    = substr( $response, $headerSize );

		$this->logger->debug( 'Response Headers:', [ 'headers' => $responseHeaders ] );
		$this->logger->debug( 'Response Body:', [ 'body' => $responseBody ] );

		$decoded = json_decode( $responseBody, TRUE );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( "JSON decode error: " . json_last_error_msg() );
		}

		return $decoded;
	}

	private function s1_parseResponse( array $response ): AnonymousToken {
		if ( ! isset( $response['data'] ) ) {
			$this->logger->error( 'Invalid API response structure', [
				'response' => $response,
			] );
			throw new \RuntimeException( "API response missing 'data' field" );
		}

		if ( ! isset( $response['data']['accessToken'] ) ) {
			$this->logger->error( 'Missing accessToken in API response', [
				'response_data' => $response['data'],
			] );
			throw new \RuntimeException( "API response missing accessToken" );
		}

		$token = $response['data']['accessToken'];
		$this->logger->debug( 'Anonymous Token acquired:', [
			'token_length'  => strlen( $token ),
			'token_prefix'  => substr( $token, 0, 20 ) . '...',
			'full_response' => $response,
		] );

		return new AnonymousToken( $token );
	}

	private function s1_handleError( \Exception $e ): void {
		$this->authState = AuthState::FAILED;
		$this->logger->error( 'Stage 1 failed', [ 'error' => $e->getMessage() ] );
		throw $e;
	}

	// Stage 2: User Login
	private function s2_performLogin(): LoginToken {
		try {
			$this->authState = AuthState::STAGE2_IN_PROGRESS;
			$this->logger->debug( 'Starting Stage 2: User Login' );

			$request = $this->s2_generateRequest();
			$this->logger->debug( 'Generated login request', [
				'email' => $this->email,
				'device_id_length' => strlen( $this->deviceId ),
			] );

			$signature = $this->generateSignature( $request->toArray() );
			$this->logger->debug( 'Generated login signature', [
				'signature_length' => strlen( $signature ),
			] );

			$response = $this->s2_sendRequest( $request, $signature );
			$token = $this->s2_parseResponse( $response );

			$this->authState = AuthState::STAGE2_COMPLETED;
			$this->logger->debug( 'Stage 2 completed successfully' );

			return $token;
		} catch ( \Exception $e ) {
			$this->s2_handleError( $e );
		}
	}

	private function s2_generateRequest(): LoginRequest {
		$deviceInfo = new DeviceInfo( deviceId: $this->deviceId );

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
			params: json_encode( $params ),
			spaceId: Config::getSpaceId(),
			timestamp: (int) ( microtime( TRUE ) * 1000 ),
			token: $this->anonymousToken->accessToken
		);
	}

	private function s2_sendRequest( LoginRequest $request, string $signature ): array {
		$headers = [
			'Content-Type: application/json',
			'x-serverless-sign: ' . $signature,
		];

		$ch = curl_init();
		curl_setopt_array( $ch, [
			CURLOPT_URL => Config::getApiEndpoint(),
			CURLOPT_POST => TRUE,
			CURLOPT_POSTFIELDS => json_encode( $request->toArray() ),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_HEADER => TRUE,
		] );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$headerSize = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );

		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			$errorNum = curl_errno( $ch );
			curl_close( $ch );

			$errorMessage = match ( $errorNum ) {
				CURLE_OPERATION_TIMEDOUT => "Login request timed out after 15 seconds",
				CURLE_COULDNT_RESOLVE_HOST => "Could not resolve API host for login",
				CURLE_COULDNT_CONNECT => "Could not connect to API server for login",
				CURLE_SSL_CONNECT_ERROR => "SSL connection failed during login",
				default => "Login cURL error ({$errorNum}): {$error}",
			};

			$this->logger->error( 'Login request failed', [
				'error_number' => $errorNum,
				'error_message' => $error,
				'endpoint' => Config::getApiEndpoint(),
				'email' => $this->email,
			] );

			throw new \RuntimeException( $errorMessage );
		}

		curl_close( $ch );

		if ( $httpCode !== 200 ) {
			$errorMessage = match ( $httpCode ) {
				401 => "Login failed - invalid email or password",
				403 => "Login forbidden - account may be locked",
				404 => "Login endpoint not found",
				429 => "Too many login attempts - rate limited",
				500 => "Server error during login",
				502, 503, 504 => "Login service temporarily unavailable",
				default => "Login HTTP error: {$httpCode}",
			};

			$this->logger->error( 'Login HTTP request failed', [
				'http_code' => $httpCode,
				'endpoint' => Config::getApiEndpoint(),
				'email' => $this->email,
			] );

			throw new \RuntimeException( $errorMessage );
		}

		$responseHeaders = substr( $response, 0, $headerSize );
		$responseBody = substr( $response, $headerSize );

		$this->logger->debug( 'Login Response Headers:', [ 'headers' => $responseHeaders ] );
		$this->logger->debug( 'Login Response Body:', [ 'body' => $responseBody ] );

		$decoded = json_decode( $responseBody, TRUE );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( "Login JSON decode error: " . json_last_error_msg() );
		}

		return $decoded;
	}

	private function s2_parseResponse( array $response ): LoginToken {
		if ( ! isset( $response['data'] ) ) {
			$this->logger->error( 'Invalid login response structure', [
				'response' => $response,
			] );
			throw new \RuntimeException( "Login response missing 'data' field" );
		}

		if ( ! isset( $response['data']['token'] ) ) {
			$this->logger->error( 'Missing login token in response', [
				'response_data' => $response['data'],
			] );
			throw new \RuntimeException( "Login response missing token" );
		}

		$token = $response['data']['token'];
		$this->logger->debug( 'Login Token acquired:', [
			'token_length' => strlen( $token ),
			'token_prefix' => substr( $token, 0, 20 ) . '...',
			'full_response' => $response,
		] );

		return new LoginToken( $token );
	}

	private function s2_handleError( \Exception $e ): void {
		$this->authState = AuthState::FAILED;
		$this->logger->error( 'Stage 2 failed', [ 'error' => $e->getMessage(), 'email' => $this->email ] );
		throw $e;
	}

	private function generateDeviceId(): string {
		return bin2hex( random_bytes( 16 ) );
	}

}
