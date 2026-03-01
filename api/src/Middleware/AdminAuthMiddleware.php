<?php

declare(strict_types=1);

namespace SuperFPL\Api\Middleware;

use Closure;
use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Middleware\Middleware;

class AdminAuthMiddleware implements Middleware
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = trim((string) $this->config->get('config.security.admin_token', ''));
        if ($expectedToken === '') {
            return Response::json(['error' => 'Admin auth not configured'], 503);
        }

        $cookies = $this->parseCookieHeader((string) $request->header('cookie', ''));
        $session = trim((string) ($cookies['superfpl_admin'] ?? ''));
        $expectedSessionHash = hash('sha256', $expectedToken);

        if ($session === '' || !hash_equals($expectedSessionHash, $session)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $cookieToken = trim((string) ($cookies['XSRF-TOKEN'] ?? ''));
        $headerToken = trim((string) $request->header('x-xsrf-token', ''));

        if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
            return Response::json(['error' => 'Invalid CSRF token'], 403);
        }

        return $next($request);
    }

    /**
     * @return array<string, string>
     */
    private function parseCookieHeader(string $rawCookie): array
    {
        if ($rawCookie === '') {
            return [];
        }

        $cookies = [];

        foreach (explode(';', $rawCookie) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }

            $cookies[$name] = urldecode(trim($parts[1]));
        }

        return $cookies;
    }
}
