<?php

declare(strict_types=1);

namespace SuperFPL\FplClient;

use SuperFPL\FplClient\Cache\CacheInterface;
use SuperFPL\FplClient\Endpoints\BootstrapEndpoint;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;
use SuperFPL\FplClient\Endpoints\FixturesEndpoint;
use SuperFPL\FplClient\Endpoints\LeagueEndpoint;
use SuperFPL\FplClient\Endpoints\LiveEndpoint;
use SuperFPL\FplClient\Endpoints\PlayerEndpoint;

class FplClient
{
    private const BASE_URL = 'https://fantasy.premierleague.com/api/';

    private readonly HttpClient $httpClient;
    private ?BootstrapEndpoint $bootstrapEndpoint = null;
    private ?FixturesEndpoint $fixturesEndpoint = null;

    public function __construct(
        ?CacheInterface $cache = null,
        int $cacheTtl = 300,
        string $rateLimitDir = '/tmp'
    ) {
        $rateLimiter = new RateLimiter($rateLimitDir);
        $this->httpClient = new HttpClient(
            baseUrl: self::BASE_URL,
            rateLimiter: $rateLimiter,
            cache: $cache,
            cacheTtl: $cacheTtl
        );
    }

    public function bootstrap(): BootstrapEndpoint
    {
        if ($this->bootstrapEndpoint === null) {
            $this->bootstrapEndpoint = new BootstrapEndpoint($this->httpClient);
        }
        return $this->bootstrapEndpoint;
    }

    public function fixtures(): FixturesEndpoint
    {
        if ($this->fixturesEndpoint === null) {
            $this->fixturesEndpoint = new FixturesEndpoint($this->httpClient);
        }
        return $this->fixturesEndpoint;
    }

    public function entry(int $id): EntryEndpoint
    {
        return new EntryEndpoint($this->httpClient, $id);
    }

    public function live(int $gameweek): LiveEndpoint
    {
        return new LiveEndpoint($this->httpClient, $gameweek);
    }

    public function league(int $id): LeagueEndpoint
    {
        return new LeagueEndpoint($this->httpClient, $id);
    }

    public function player(int $id): PlayerEndpoint
    {
        return new PlayerEndpoint($this->httpClient, $id);
    }
}
