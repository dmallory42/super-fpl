<?php

declare(strict_types=1);

namespace SuperFPL\FplClient;

use SuperFPL\FplClient\Cache\CacheInterface;

class HttpClient
{
    private const USER_AGENT = 'SuperFPL-Client/1.0 (PHP)';

    public function __construct(
        private readonly string $baseUrl,
        private readonly RateLimiter $rateLimiter,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 300
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $endpoint, bool $useCache = true): array
    {
        $url = $this->baseUrl . $endpoint;
        $cacheKey = 'fpl_' . md5($url);

        if ($useCache && $this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $this->rateLimiter->wait();

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json',
                ],
                'timeout' => 30,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \RuntimeException("Failed to fetch: {$url}");
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON response from: {$url}");
        }

        if ($useCache && $this->cache !== null) {
            $this->cache->set($cacheKey, $data, $this->cacheTtl);
        }

        return $data;
    }
}
