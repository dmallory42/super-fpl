<?php

declare(strict_types=1);

namespace SuperFPL\Api\Support;

interface ResponseCacheStore
{
    public function isAvailable(): bool;

    public function get(string $key): ?string;

    public function set(string $key, int $ttlSeconds, string $value): void;
}
