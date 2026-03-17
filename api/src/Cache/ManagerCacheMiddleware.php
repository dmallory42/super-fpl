<?php

declare(strict_types=1);

namespace SuperFPL\Api\Cache;

use Maia\Core\Middleware\ResponseCacheMiddleware;

/**
 * Named alias so the DI container can hold a separate TTL config for manager/season-review endpoints.
 */
class ManagerCacheMiddleware extends ResponseCacheMiddleware
{
}
