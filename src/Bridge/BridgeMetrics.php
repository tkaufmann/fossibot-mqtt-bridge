<?php

declare( strict_types=1 );

namespace Fossibot\Bridge;

/**
 * Metrics collector for bridge health monitoring.
 *
 * Tracks connection status, device counts, uptime, and resource usage.
 */
class BridgeMetrics {

	private int $startTime;
	private int $accountsTotal = 0;
	private int $accountsConnected = 0;
	private int $devicesTotal = 0;
	private int $devicesOnline = 0;
	private bool $localBrokerConnected = false;

	public function __construct() {
		$this->startTime = time();
	}

	/**
	 * Update account connection metrics.
	 */
	public function setAccountMetrics( int $total, int $connected ): void {
		$this->accountsTotal = $total;
		$this->accountsConnected = $connected;
	}

	/**
	 * Update device metrics.
	 */
	public function setDeviceMetrics( int $total, int $online ): void {
		$this->devicesTotal = $total;
		$this->devicesOnline = $online;
	}

	/**
	 * Set local broker connection status.
	 */
	public function setLocalBrokerConnected( bool $connected ): void {
		$this->localBrokerConnected = $connected;
	}

	/**
	 * Get current health status.
	 *
	 * @return array Health data
	 */
	public function getHealth(): array {
		$uptime = time() - $this->startTime;
		$memoryUsage = memory_get_usage( true );
		$memoryLimit = $this->parseMemoryLimit( ini_get( 'memory_limit' ) );

		// Determine overall health status
		$status = 'healthy';

		// Unhealthy if no accounts connected
		if ( $this->accountsTotal > 0 && $this->accountsConnected === 0 ) {
			$status = 'unhealthy';
		}

		// Degraded if some accounts disconnected
		if ( $this->accountsTotal > 0 && $this->accountsConnected < $this->accountsTotal ) {
			$status = 'degraded';
		}

		// Unhealthy if local broker disconnected
		if ( ! $this->localBrokerConnected ) {
			$status = 'unhealthy';
		}

		return [
			'status' => $status,
			'uptime' => $uptime,
			'accounts' => [
				'total' => $this->accountsTotal,
				'connected' => $this->accountsConnected,
				'disconnected' => $this->accountsTotal - $this->accountsConnected
			],
			'devices' => [
				'total' => $this->devicesTotal,
				'online' => $this->devicesOnline,
				'offline' => $this->devicesTotal - $this->devicesOnline
			],
			'mqtt' => [
				'cloud_clients' => $this->accountsConnected,
				'local_broker' => $this->localBrokerConnected ? 'connected' : 'disconnected'
			],
			'memory' => [
				'usage_mb' => round( $memoryUsage / 1024 / 1024, 2 ),
				'limit_mb' => $memoryLimit > 0 ? round( $memoryLimit / 1024 / 1024, 2 ) : null
			]
		];
	}

	/**
	 * Parse PHP memory_limit string to bytes.
	 */
	private function parseMemoryLimit( string $limit ): int {
		if ( $limit === '-1' ) {
			return -1; // Unlimited
		}

		$limit = trim( $limit );
		$last = strtolower( $limit[ strlen( $limit ) - 1 ] );
		$value = (int) $limit;

		switch ( $last ) {
			case 'g':
				$value *= 1024;
				// fall through
			case 'm':
				$value *= 1024;
				// fall through
			case 'k':
				$value *= 1024;
		}

		return $value;
	}
}
