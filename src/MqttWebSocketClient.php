<?php

declare( strict_types=1 );

namespace Fossibot;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WebSocket\Client;

/**
 * MQTT client over WebSocket for Fossibot API communication.
 */
final class MqttWebSocketClient {

	// MQTT Packet Types (Fixed Header)
	private const MQTT_CONNECT = 0x10;
	private const MQTT_CONNACK = 0x20;
	private const MQTT_DISCONNECT = 0xE0;

	// MQTT Protocol Constants
	private const MQTT_PROTOCOL_NAME = 'MQTT';
	private const MQTT_PROTOCOL_VERSION = 4;  // MQTT 3.1.1
	private const MQTT_CONNECT_FLAGS = 0xC2; // Username + Password + Clean Session
	private const MQTT_KEEPALIVE = 30;        // 30 seconds

	// MQTT CONNACK Return Codes
	private const CONNACK_ACCEPTED = 0x00;
	private const CONNACK_PROTOCOL_VERSION = 0x01;
	private const CONNACK_IDENTIFIER_REJECTED = 0x02;
	private const CONNACK_SERVER_UNAVAILABLE = 0x03;
	private const CONNACK_BAD_CREDENTIALS = 0x04;
	private const CONNACK_NOT_AUTHORIZED = 0x05;

	private ?Client $websocket = NULL;
	private LoggerInterface $logger;
	private bool $connected = FALSE;

	public function __construct( ?LoggerInterface $logger = NULL ) {
		$this->logger = $logger ?? new NullLogger();
	}

	public function connect( string $host, int $port, string $clientId, string $username, string $password ): void {
		try {
			$startTime = microtime( TRUE );

			$this->validateInputs( $host, $port, $clientId, $username, $password );

			$this->logger->debug( 'Establishing WebSocket connection for MQTT', [
				'host' => $host,
				'port' => $port,
				'client_id' => $clientId,
			] );

			// Create WebSocket URL
			$wsUrl = "ws://{$host}:{$port}/mqtt";

			// Connect to WebSocket with MQTT subprotocol
			$this->websocket = new Client( $wsUrl, [
				'headers' => [
					'Sec-WebSocket-Protocol' => 'mqtt',
				],
				'timeout' => 10,
			] );

			$wsConnectTime = microtime( TRUE );
			$this->logger->debug( 'WebSocket connection established', [
				'connection_time_ms' => round( ( $wsConnectTime - $startTime ) * 1000, 2 ),
			] );

			// Send MQTT CONNECT packet
			$this->sendMqttConnect( $clientId, $username, $password );

			// Wait for MQTT CONNACK
			$this->waitForConnAck();

			$totalTime = microtime( TRUE );
			$this->connected = TRUE;
			$this->logger->debug( 'MQTT connection established successfully', [
				'total_time_ms' => round( ( $totalTime - $startTime ) * 1000, 2 ),
			] );

		} catch ( \Exception $e ) {
			$this->handleConnectionError( $e );
		}
	}

	public function isConnected(): bool {
		return $this->connected && $this->websocket !== NULL;
	}

	public function disconnect(): void {
		if ( $this->websocket ) {
			$this->logger->debug( 'Sending MQTT DISCONNECT' );
			$this->sendMqttDisconnect();
			$this->websocket->close();
			$this->websocket = NULL;
		}
		$this->connected = FALSE;
	}

	private function sendMqttConnect( string $clientId, string $username, string $password ): void {
		// MQTT CONNECT packet structure (MQTT 3.1.1)
		$packet = pack( 'C', self::MQTT_CONNECT );

		// Variable header: Protocol Name + Version + Connect Flags + Keep Alive
		$protocolName = pack( 'n', strlen( self::MQTT_PROTOCOL_NAME ) ) . self::MQTT_PROTOCOL_NAME;
		$protocolVersion = pack( 'C', self::MQTT_PROTOCOL_VERSION );
		$connectFlags = pack( 'C', self::MQTT_CONNECT_FLAGS );
		$keepAlive = pack( 'n', self::MQTT_KEEPALIVE );

		// Payload: Client ID + Username + Password
		$clientIdBytes = pack( 'n', strlen( $clientId ) ) . $clientId;
		$usernameBytes = pack( 'n', strlen( $username ) ) . $username;
		$passwordBytes = pack( 'n', strlen( $password ) ) . $password;

		$payload = $clientIdBytes . $usernameBytes . $passwordBytes;
		$variableHeader = $protocolName . $protocolVersion . $connectFlags . $keepAlive;

		// Calculate remaining length
		$remainingLength = strlen( $variableHeader ) + strlen( $payload );
		$remainingLengthBytes = $this->encodeRemainingLength( $remainingLength );

		$fullPacket = $packet . $remainingLengthBytes . $variableHeader . $payload;

		$this->logger->debug( 'Sending MQTT CONNECT packet', [
			'packet_size' => strlen( $fullPacket ),
			'client_id' => $clientId,
			'username' => substr( $username, 0, 20 ) . '...',
		] );

		$this->websocket->send( $fullPacket, 'binary' );
	}

	private function waitForConnAck(): void {
		$this->logger->debug( 'Waiting for MQTT CONNACK response' );

		$response = $this->websocket->receive();

		if ( strlen( $response ) < 4 ) {
			throw new \RuntimeException( 'Invalid MQTT CONNACK response - too short' );
		}

		$fixedHeader = unpack( 'C', $response[0] )[1];
		$remainingLength = unpack( 'C', $response[1] )[1];
		$connectAckFlags = unpack( 'C', $response[2] )[1];
		$returnCode = unpack( 'C', $response[3] )[1];

		$this->logger->debug( 'Received MQTT CONNACK', [
			'fixed_header' => sprintf( '0x%02X', $fixedHeader ),
			'remaining_length' => $remainingLength,
			'ack_flags' => $connectAckFlags,
			'return_code' => $returnCode,
		] );

		if ( $fixedHeader !== self::MQTT_CONNACK ) {
			throw new \RuntimeException( 'Expected MQTT CONNACK packet, got: ' . sprintf( '0x%02X', $fixedHeader ) );
		}

		if ( $returnCode !== self::CONNACK_ACCEPTED ) {
			$errorMessages = [
				self::CONNACK_PROTOCOL_VERSION => 'Connection Refused: unacceptable protocol version',
				self::CONNACK_IDENTIFIER_REJECTED => 'Connection Refused: identifier rejected',
				self::CONNACK_SERVER_UNAVAILABLE => 'Connection Refused: server unavailable',
				self::CONNACK_BAD_CREDENTIALS => 'Connection Refused: bad user name or password',
				self::CONNACK_NOT_AUTHORIZED => 'Connection Refused: not authorized',
			];

			$error = $errorMessages[$returnCode] ?? "Unknown error code: {$returnCode}";
			throw new \RuntimeException( "MQTT connection failed: {$error}" );
		}

		$this->logger->debug( 'MQTT CONNACK successful - connection established' );
	}

	private function sendMqttDisconnect(): void {
		// MQTT DISCONNECT packet: Fixed header only
		$packet = pack( 'CC', self::MQTT_DISCONNECT, 0x00 ); // DISCONNECT + remaining length 0
		$this->websocket->send( $packet, 'binary' );
	}

	private function encodeRemainingLength( int $length ): string {
		$encoded = '';
		do {
			$byte = $length % 128;
			$length = intval( $length / 128 );
			if ( $length > 0 ) {
				$byte |= 0x80;
			}
			$encoded .= pack( 'C', $byte );
		} while ( $length > 0 );

		return $encoded;
	}

	private function validateInputs( string $host, int $port, string $clientId, string $username, string $password ): void {
		if ( empty( $host ) ) {
			throw new \InvalidArgumentException( 'MQTT host cannot be empty' );
		}

		if ( $port <= 0 || $port > 65535 ) {
			throw new \InvalidArgumentException( "Invalid MQTT port: {$port}" );
		}

		if ( empty( $clientId ) || strlen( $clientId ) > 23 ) {
			throw new \InvalidArgumentException( "MQTT client ID must be 1-23 characters, got " . strlen( $clientId ) . " characters: {$clientId}" );
		}

		if ( empty( $username ) ) {
			throw new \InvalidArgumentException( 'MQTT username cannot be empty' );
		}

		if ( empty( $password ) ) {
			throw new \InvalidArgumentException( 'MQTT password cannot be empty' );
		}

		// Client ID should match our expected pattern: c_{8-hex}_{6-timestamp}
		if ( ! preg_match( '/^c_[0-9a-f]{8}_\d{6}$/', $clientId ) ) {
			$this->logger->warning( 'Client ID does not match expected pattern', [
				'client_id' => $clientId,
				'expected_pattern' => 'c_{8-hex}_{6-timestamp}',
			] );
		}
	}

	private function handleConnectionError( \Exception $e ): void {
		$this->connected = FALSE;
		$this->websocket = NULL;

		$errorType = match ( TRUE ) {
			str_contains( $e->getMessage(), 'timed out' ) => 'Connection timeout',
			str_contains( $e->getMessage(), 'resolve' ) => 'DNS resolution failed',
			str_contains( $e->getMessage(), 'SSL' ) || str_contains( $e->getMessage(), 'TLS' ) => 'SSL/TLS error',
			str_contains( $e->getMessage(), 'WebSocket' ) => 'WebSocket handshake failed',
			str_contains( $e->getMessage(), 'MQTT' ) => 'MQTT protocol error',
			default => 'Unknown connection error',
		};

		$this->logger->error( 'MQTT WebSocket connection failed', [
			'error_type' => $errorType,
			'error_message' => $e->getMessage(),
			'error_class' => get_class( $e ),
		] );

		throw new \RuntimeException( "MQTT WebSocket connection failed: {$errorType} - {$e->getMessage()}", 0, $e );
	}

	/**
	 * Publish a message to an MQTT topic.
	 *
	 * @param string $topic MQTT topic to publish to
	 * @param string $payload Binary payload to send
	 * @throws \RuntimeException If not connected or publish fails
	 */
	public function publish( string $topic, string $payload ): void {
		if ( ! $this->connected || $this->websocket === NULL ) {
			throw new \RuntimeException( 'Cannot publish: MQTT client not connected' );
		}

		if ( empty( $topic ) ) {
			throw new \InvalidArgumentException( 'MQTT topic cannot be empty' );
		}

		try {
			$packet = $this->buildPublishPacket( $topic, $payload );

			$this->logger->debug( 'Sending MQTT PUBLISH packet', [
				'topic' => $topic,
				'payload_size' => strlen( $payload ),
				'packet_size' => strlen( $packet ),
			] );

			$this->websocket->send( $packet, 'binary' );

			$this->logger->debug( 'MQTT PUBLISH sent successfully', [
				'topic' => $topic,
				'payload_size' => strlen( $payload ),
			] );

		} catch ( \Exception $e ) {
			$this->logger->error( 'MQTT publish failed', [
				'topic' => $topic,
				'payload_size' => strlen( $payload ),
				'error' => $e->getMessage(),
				'error_class' => get_class( $e ),
			] );

			throw new \RuntimeException( "MQTT publish failed: {$e->getMessage()}", 0, $e );
		}
	}

	/**
	 * Build MQTT PUBLISH packet.
	 *
	 * @param string $topic MQTT topic
	 * @param string $payload Message payload
	 * @return string Binary MQTT packet
	 */
	private function buildPublishPacket( string $topic, string $payload ): string {
		$packet = '';

		// Variable Header: Topic Name
		$packet .= pack( 'n', strlen( $topic ) ); // Topic length (2 bytes)
		$packet .= $topic;                        // Topic string

		// Payload
		$packet .= $payload;

		// Fixed Header
		$fixedHeader = chr( 0x30 ); // PUBLISH, QoS 0, No retain, No dup
		$fixedHeader .= $this->encodeRemainingLength( strlen( $packet ) );

		return $fixedHeader . $packet;
	}

}