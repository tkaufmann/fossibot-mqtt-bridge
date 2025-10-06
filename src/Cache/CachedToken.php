<?php

declare(strict_types=1);

namespace Fossibot\Cache;

/**
 * Immutable value object for cached authentication tokens.
 *
 * Stores token string, expiry timestamp, and cache creation time.
 */
final class CachedToken
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresAt,
        public readonly int $cachedAt
    ) {
    }

    /**
     * Check if token is still valid with safety margin.
     *
     * @param int $safetyMargin Seconds before expiry to treat as expired (default: 300 = 5min)
     * @return bool True if token is valid and not expiring soon
     */
    public function isValid(int $safetyMargin = 300): bool
    {
        $now = time();
        return $this->expiresAt > ($now + $safetyMargin);
    }

    /**
     * Get TTL remaining in seconds (without safety margin).
     */
    public function getTtlRemaining(): int
    {
        return max(0, $this->expiresAt - time());
    }

    /**
     * Get cache age in seconds.
     */
    public function getAge(): int
    {
        return time() - $this->cachedAt;
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'expires_at' => $this->expiresAt,
            'cached_at' => $this->cachedAt
        ];
    }

    /**
     * Create from array (JSON deserialization).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['token'],
            $data['expires_at'],
            $data['cached_at']
        );
    }
}
