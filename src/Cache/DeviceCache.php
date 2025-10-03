<?php
declare(strict_types=1);

namespace Fossibot\Cache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-based cache for device lists with TTL.
 *
 * Caches discovered devices per account to avoid repeated API calls.
 * Default TTL: 24 hours (devices change rarely).
 */
class DeviceCache
{
    private string $cacheDir;
    private LoggerInterface $logger;
    private int $ttl;

    /**
     * @param string $cacheDir Directory for cache files (will be created if missing)
     * @param int $ttl Cache lifetime in seconds (default: 86400 = 24 hours)
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        string $cacheDir,
        int $ttl = 86400,
        ?LoggerInterface $logger = null
    ) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->ttl = $ttl;
        $this->logger = $logger ?? new NullLogger();

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
            $this->logger->debug('Created device cache directory', [
                'path' => $this->cacheDir
            ]);
        }
    }

    /**
     * Get cached devices with TTL check.
     *
     * @param string $email Account email
     * @return array|null Array of Device objects if valid, null if expired/missing
     */
    public function getDevices(string $email): ?array
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            $this->logger->debug('Device cache miss', ['email' => $email]);
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            $this->logger->warning('Failed to read device cache', [
                'email' => $email,
                'file' => $file
            ]);
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Device cache corrupted, ignoring', [
                'email' => $email,
                'error' => json_last_error_msg()
            ]);
            return null;
        }

        $now = time();
        $age = $now - $data['cached_at'];

        // Check TTL
        if ($age > $this->ttl) {
            $this->logger->debug('Device cache expired', [
                'email' => $email,
                'age' => $age,
                'ttl' => $this->ttl
            ]);
            return null;
        }

        $this->logger->debug('Device cache hit', [
            'email' => $email,
            'device_count' => count($data['devices']),
            'age' => $age
        ]);

        // Deserialize Device objects
        return array_map(
            fn($deviceData) => \Fossibot\Device\Device::fromArray($deviceData),
            $data['devices']
        );
    }

    /**
     * Save devices to cache.
     *
     * @param string $email Account email
     * @param array $devices Array of Device objects
     */
    public function saveDevices(string $email, array $devices): void
    {
        $file = $this->getCacheFile($email);

        // Serialize Device objects to arrays
        $deviceArrays = array_map(
            fn($device) => $device->toArray(),
            $devices
        );

        $data = [
            'cached_at' => time(),
            'devices' => $deviceArrays
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT);

        // Atomic write
        $tempFile = $file . '.tmp';
        file_put_contents($tempFile, $json, LOCK_EX);
        chmod($tempFile, 0600);
        rename($tempFile, $file);

        $this->logger->info('Device list cached', [
            'email' => $email,
            'device_count' => count($devices),
            'ttl' => $this->ttl
        ]);
    }

    /**
     * Get cache age in seconds.
     *
     * @param string $email Account email
     * @return int|null Age in seconds, or null if cache doesn't exist
     */
    public function getAge(string $email): ?int
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return time() - $data['cached_at'];
    }

    /**
     * Invalidate device cache.
     *
     * @param string $email Account email
     */
    public function invalidate(string $email): void
    {
        $file = $this->getCacheFile($email);

        if (file_exists($file)) {
            unlink($file);
            $this->logger->info('Device cache invalidated', [
                'email' => $email
            ]);
        }
    }

    /**
     * Get cache file path for account.
     */
    private function getCacheFile(string $email): string
    {
        $hash = md5($email);
        return "{$this->cacheDir}/devices_{$hash}.json";
    }
}
