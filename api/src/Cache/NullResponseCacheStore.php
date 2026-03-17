<?php

declare(strict_types=1);

namespace SuperFPL\Api\Cache;

use Maia\Core\Cache\ResponseCacheStore;

class NullResponseCacheStore implements ResponseCacheStore
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function get(string $key): ?string
    {
        return null;
    }

    public function set(string $key, int $ttlSeconds, string $value): void
    {
    }
}
