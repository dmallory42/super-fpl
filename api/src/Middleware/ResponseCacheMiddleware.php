<?php

declare(strict_types=1);

namespace SuperFPL\Api\Middleware;

use Closure;
use Maia\Core\Cache\ResponseCacheStore;
use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Middleware\Middleware;
use Maia\Core\Middleware\ResponseCacheMiddleware as MaiaResponseCacheMiddleware;

class ResponseCacheMiddleware implements Middleware
{
    private MaiaResponseCacheMiddleware $delegate;

    public function __construct(
        private readonly ResponseCacheStore $cacheStore,
        private readonly Config $config,
        private readonly string $namespace = 'default',
        private readonly int $ttlSeconds = 60
    ) {
        $this->delegate = new MaiaResponseCacheMiddleware(
            $cacheStore,
            $ttlSeconds,
            $namespace,
            fn(Request $request, string $namespace): string => $this->buildCacheKey($request, $namespace)
        );
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

        return $this->delegate->handle($request, $next);
    }

    private function buildCacheKey(Request $request, string $namespace): string
    {
        $databasePath = trim((string) $this->config->get('config.database.path', ''));
        $dbMtime = ($databasePath !== '' && file_exists($databasePath)) ? (string) filemtime($databasePath) : '0';
        $cachePath = trim((string) $this->config->get('config.cache.path', ''));
        $syncVersionPath = $cachePath !== '' ? rtrim($cachePath, '/\\') . '/sync_version.txt' : '';
        $syncVersion = ($syncVersionPath !== '' && file_exists($syncVersionPath))
            ? trim((string) file_get_contents($syncVersionPath))
            : '0';
        $query = $request->queryParams();
        if ($query !== []) {
            ksort($query);
        }
        $requestUri = $request->path();
        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $requestUri .= '?' . $queryString;
        }

        return 'resp:v1:' . sha1($namespace . '|' . $requestUri . '|' . $dbMtime . '|' . $syncVersion);
    }
}
