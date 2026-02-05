<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Endpoints;

use SuperFPL\FplClient\HttpClient;
use SuperFPL\FplClient\Models\Fixture;

class FixturesEndpoint
{
    public function __construct(
        private readonly HttpClient $httpClient
    ) {
    }

    /**
     * @return Fixture[]
     */
    public function all(): array
    {
        $data = $this->httpClient->get('fixtures/');
        return array_map(
            fn(array $fixture) => Fixture::fromArray($fixture),
            $data
        );
    }

    /**
     * @return Fixture[]
     */
    public function forGameweek(int $gameweek): array
    {
        $data = $this->httpClient->get("fixtures/?event={$gameweek}");
        return array_map(
            fn(array $fixture) => Fixture::fromArray($fixture),
            $data
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function getRaw(?int $gameweek = null, bool $useCache = true): array
    {
        $endpoint = $gameweek !== null
            ? "fixtures/?event={$gameweek}"
            : 'fixtures/';

        return $this->httpClient->get($endpoint, $useCache);
    }
}
