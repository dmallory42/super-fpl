<?php

declare(strict_types=1);

namespace SuperFPL\Api\Cache;

use Maia\Core\Cache\ResponseCacheStore;
use Predis\Client as RedisClient;
use Throwable;

class RedisResponseCacheStore implements ResponseCacheStore
{
    public function __construct(
        private readonly RedisClient $redis
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            $this->redis->ping();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->redis->get($key);
            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $key, int $ttlSeconds, string $value): void
    {
        try {
            $this->redis->setex($key, $ttlSeconds, $value);
        } catch (Throwable) {
            // Silently fail — cache is best-effort
        }
    }
}
