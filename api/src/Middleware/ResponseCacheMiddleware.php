<?php

declare(strict_types=1);

namespace SuperFPL\Api\Middleware;

use Closure;
use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Middleware\Middleware;
use SuperFPL\Api\Support\ResponseCacheStore;

class ResponseCacheMiddleware implements Middleware
{
    public function __construct(
        private readonly ResponseCacheStore $cacheStore,
        private readonly Config $config,
        private readonly string $namespace = 'default',
        private readonly int $ttlSeconds = 60
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->method() !== 'GET'
            || (string) $request->query('nocache', '0') === '1'
            || (string) $request->query('refresh', '0') === '1'
            || !$this->cacheStore->isAvailable()
        ) {
            return $next($request)->withHeader('X-Response-Cache', 'BYPASS');
        }

        $cacheKey = $this->buildCacheKey($request);
        $cached = $this->cacheStore->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return Response::json($decoded)->withHeader('X-Response-Cache', 'HIT');
            }
        }

        $response = $next($request);
        if ($response->status() !== 200 || $response->body() === '') {
            return $response->withHeader('X-Response-Cache', 'BYPASS');
        }

        $this->cacheStore->set($cacheKey, $this->ttlSeconds, $response->body());

        return $response->withHeader('X-Response-Cache', 'MISS');
    }

    private function buildCacheKey(Request $request): string
    {
        $databasePath = trim((string) $this->config->get('config.database.path', ''));
        $dbMtime = ($databasePath !== '' && file_exists($databasePath)) ? (string) filemtime($databasePath) : '0';
        $cachePath = trim((string) $this->config->get('config.cache.path', ''));
        $syncVersionPath = $cachePath !== '' ? rtrim($cachePath, '/\\') . '/sync_version.txt' : '';
        $syncVersion = ($syncVersionPath !== '' && file_exists($syncVersionPath))
            ? trim((string) file_get_contents($syncVersionPath))
            : '0';
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? $request->path());

        return 'resp:v1:' . sha1($this->namespace . '|' . $requestUri . '|' . $dbMtime . '|' . $syncVersion);
    }
}
