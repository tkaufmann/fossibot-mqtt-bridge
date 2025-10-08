<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Metrics collector for bridge health monitoring.
 *
 * Tracks connection status, device counts, uptime, and resource usage.
 */
class BridgeMetrics
{
    private int $startTime;
    private int $accountsTotal = 0;
    private int $accountsConnected = 0;
    private int $devicesTotal = 0;
    private int $devicesOnline = 0;
    private bool $localBrokerConnected = false;

    // Spontaneous update tracking (per device MAC address)
    private array $lastSpontaneousUpdate = [];      // MAC => timestamp
    private array $spontaneousUpdateIntervals = []; // MAC => [interval1, interval2, ...]
    private int $maxIntervalsPerDevice = 100;       // Keep last 100 intervals

    public function __construct()
    {
        $this->startTime = time();
    }

    /**
     * Update account connection metrics.
     */
    public function setAccountMetrics(int $total, int $connected): void
    {
        $this->accountsTotal = $total;
        $this->accountsConnected = $connected;
    }

    /**
     * Update device metrics.
     */
    public function setDeviceMetrics(int $total, int $online): void
    {
        $this->devicesTotal = $total;
        $this->devicesOnline = $online;
    }

    /**
     * Set local broker connection status.
     */
    public function setLocalBrokerConnected(bool $connected): void
    {
        $this->localBrokerConnected = $connected;
    }

    /**
     * Get current health status.
     *
     * @return array Health data
     */
    public function getHealth(): array
    {
        $uptime = time() - $this->startTime;
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));

        // Determine overall health status
        $status = 'healthy';

        // Unhealthy if no accounts connected
        if ($this->accountsTotal > 0 && $this->accountsConnected === 0) {
            $status = 'unhealthy';
        }

        // Degraded if some accounts disconnected
        if ($this->accountsTotal > 0 && $this->accountsConnected < $this->accountsTotal) {
            $status = 'degraded';
        }

        // Unhealthy if local broker disconnected
        if (! $this->localBrokerConnected) {
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
                'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'limit_mb' => $memoryLimit > 0 ? round($memoryLimit / 1024 / 1024, 2) : null
            ]
        ];
    }

    /**
     * Record a spontaneous device update (not triggered by our commands).
     */
    public function recordSpontaneousUpdate(string $mac): void
    {
        $now = time();

        // If we have a previous update, calculate interval
        if (isset($this->lastSpontaneousUpdate[$mac])) {
            $interval = $now - $this->lastSpontaneousUpdate[$mac];

            // Initialize array if needed
            if (!isset($this->spontaneousUpdateIntervals[$mac])) {
                $this->spontaneousUpdateIntervals[$mac] = [];
            }

            // Add interval and keep only last N
            $this->spontaneousUpdateIntervals[$mac][] = $interval;
            if (count($this->spontaneousUpdateIntervals[$mac]) > $this->maxIntervalsPerDevice) {
                array_shift($this->spontaneousUpdateIntervals[$mac]);
            }
        }

        $this->lastSpontaneousUpdate[$mac] = $now;
    }

    /**
     * Get spontaneous update statistics for a device.
     *
     * @return array{count: int, min: int|null, max: int|null, avg: float|null, last: int|null}
     */
    public function getSpontaneousUpdateStats(string $mac): array
    {
        if (!isset($this->spontaneousUpdateIntervals[$mac]) || empty($this->spontaneousUpdateIntervals[$mac])) {
            return [
                'count' => 0,
                'min' => null,
                'max' => null,
                'avg' => null,
                'last' => null
            ];
        }

        $intervals = $this->spontaneousUpdateIntervals[$mac];

        return [
            'count' => count($intervals),
            'min' => min($intervals),
            'max' => max($intervals),
            'avg' => round(array_sum($intervals) / count($intervals), 1),
            'last' => end($intervals)
        ];
    }

    /**
     * Get all spontaneous update statistics.
     *
     * @return array MAC => stats
     */
    public function getAllSpontaneousUpdateStats(): array
    {
        $stats = [];
        foreach (array_keys($this->lastSpontaneousUpdate) as $mac) {
            $stats[$mac] = $this->getSpontaneousUpdateStats($mac);
        }
        return $stats;
    }

    /**
     * Parse PHP memory_limit string to bytes.
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1; // Unlimited
        }

        $limit = trim($limit);
        $last = strtolower($limit[ strlen($limit) - 1 ]);
        $value = (int) $limit;

        switch ($last) {
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
