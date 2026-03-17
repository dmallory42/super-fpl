<?php

declare(strict_types=1);

namespace SuperFPL\Api\Cache;

use Maia\Core\Middleware\ResponseCacheMiddleware;

/**
 * Named alias so the DI container can hold a separate TTL config for live endpoints.
 */
class LiveCacheMiddleware extends ResponseCacheMiddleware
{
}
