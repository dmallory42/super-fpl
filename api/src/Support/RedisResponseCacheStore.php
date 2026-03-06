<?php

declare(strict_types=1);

namespace SuperFPL\Api\Support;

use Maia\Core\Cache\ResponseCacheStore;
use Predis\Client;

class RedisResponseCacheStore implements ResponseCacheStore
{
    public function __construct(
        private readonly ?Client $client
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->client instanceof Client;
    }

    public function get(string $key): ?string
    {
        if (!$this->client instanceof Client) {
            return null;
        }

        $value = $this->client->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, int $ttlSeconds, string $value): void
    {
        if (!$this->client instanceof Client) {
            return;
        }

        $this->client->setex($key, $ttlSeconds, $value);
    }
}
