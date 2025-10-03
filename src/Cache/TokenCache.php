<?php
declare(strict_types=1);

namespace Fossibot\Cache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-based cache for authentication tokens with TTL and safety margin.
 *
 * Stores tokens per account (identified by email) in JSON files.
 * Automatically invalidates expired tokens based on expires_at timestamp.
 * Supports three token stages: s1_anonymous, s2_login, s3_mqtt.
 */
class TokenCache
{
    private string $cacheDir;
    private LoggerInterface $logger;
    private int $safetyMargin;

    /**
     * @param string $cacheDir Directory for cache files (will be created if missing)
     * @param int $safetyMargin Seconds before expiry to treat token as expired (default: 300 = 5min)
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        string $cacheDir,
        int $safetyMargin = 300,
        ?LoggerInterface $logger = null
    ) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->safetyMargin = $safetyMargin;
        $this->logger = $logger ?? new NullLogger();

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
            $this->logger->debug('Created cache directory', [
                'path' => $this->cacheDir
            ]);
        }
    }

    /**
     * Get cached token with automatic expiry check.
     *
     * @param string $email Account email
     * @param string $stage Token stage (s1_anonymous, s2_login, s3_mqtt)
     * @return CachedToken|null Token if valid, null if expired/missing
     */
    public function getCachedToken(string $email, string $stage): ?CachedToken
    {
        $cached = $this->readFromDisk($email);

        if (!isset($cached[$stage])) {
            $this->logger->debug('Token cache miss', [
                'email' => $email,
                'stage' => $stage
            ]);
            return null;
        }

        $token = CachedToken::fromArray($cached[$stage]);

        // Check validity with safety margin
        if (!$token->isValid($this->safetyMargin)) {
            $this->logger->debug('Cached token expired or expiring soon', [
                'email' => $email,
                'stage' => $stage,
                'expires_at' => date('Y-m-d H:i:s', $token->expiresAt),
                'ttl_remaining' => $token->getTtlRemaining(),
                'safety_margin' => $this->safetyMargin
            ]);
            return null;
        }

        $this->logger->debug('Token cache hit', [
            'email' => $email,
            'stage' => $stage,
            'ttl_remaining' => $token->getTtlRemaining(),
            'cache_age' => $token->getAge()
        ]);

        return $token;
    }

    /**
     * Save token to cache.
     *
     * @param string $email Account email
     * @param string $stage Token stage (s1_anonymous, s2_login, s3_mqtt)
     * @param string $token Token string
     * @param int $expiresAt Unix timestamp when token expires
     */
    public function saveToken(
        string $email,
        string $stage,
        string $token,
        int $expiresAt
    ): void {
        $cached = $this->readFromDisk($email) ?? [];

        $cached[$stage] = [
            'token' => $token,
            'expires_at' => $expiresAt,
            'cached_at' => time()
        ];

        $this->writeToDisk($email, $cached);

        $this->logger->info('Token cached', [
            'email' => $email,
            'stage' => $stage,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'ttl' => $expiresAt - time()
        ]);
    }

    /**
     * Invalidate all tokens for account.
     *
     * @param string $email Account email
     */
    public function invalidate(string $email): void
    {
        $file = $this->getCacheFile($email);

        if (file_exists($file)) {
            unlink($file);
            $this->logger->info('Token cache invalidated', [
                'email' => $email
            ]);
        }
    }

    /**
     * Invalidate specific stage token.
     *
     * @param string $email Account email
     * @param string $stage Token stage to invalidate
     */
    public function invalidateStage(string $email, string $stage): void
    {
        $cached = $this->readFromDisk($email);

        if ($cached && isset($cached[$stage])) {
            unset($cached[$stage]);
            $this->writeToDisk($email, $cached);

            $this->logger->info('Stage token invalidated', [
                'email' => $email,
                'stage' => $stage
            ]);
        }
    }

    /**
     * Get cache file path for account.
     */
    private function getCacheFile(string $email): string
    {
        $hash = md5($email);
        return "{$this->cacheDir}/tokens_{$hash}.json";
    }

    /**
     * Read cache from disk with error handling.
     */
    private function readFromDisk(string $email): ?array
    {
        $file = $this->getCacheFile($email);

        if (!file_exists($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            $this->logger->warning('Failed to read token cache file', [
                'email' => $email,
                'file' => $file
            ]);
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Token cache file corrupted, ignoring', [
                'email' => $email,
                'file' => $file,
                'error' => json_last_error_msg()
            ]);
            return null;
        }

        return $data;
    }

    /**
     * Write cache to disk with atomic write.
     */
    private function writeToDisk(string $email, array $data): void
    {
        $file = $this->getCacheFile($email);
        $json = json_encode($data, JSON_PRETTY_PRINT);

        // Atomic write via temp file + rename
        $tempFile = $file . '.tmp';
        file_put_contents($tempFile, $json, LOCK_EX);
        chmod($tempFile, 0600); // Read/write only for owner
        rename($tempFile, $file);
    }
}
