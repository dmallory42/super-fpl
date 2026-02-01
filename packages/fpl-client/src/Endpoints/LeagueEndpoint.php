<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Endpoints;

use SuperFPL\FplClient\HttpClient;

class LeagueEndpoint
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $leagueId
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function standings(int $page = 1): array
    {
        return $this->httpClient->get(
            "leagues-classic/{$this->leagueId}/standings/?page_standings={$page}"
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getLeagueInfo(): array
    {
        $data = $this->standings();
        return $data['league'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getResults(int $page = 1): array
    {
        $data = $this->standings($page);
        return $data['standings']['results'] ?? [];
    }

    public function hasNextPage(int $currentPage = 1): bool
    {
        $data = $this->standings($currentPage);
        return $data['standings']['has_next'] ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllResults(): array
    {
        $allResults = [];
        $page = 1;

        do {
            $results = $this->getResults($page);
            $allResults = array_merge($allResults, $results);
            $hasNext = $this->hasNextPage($page);
            $page++;
        } while ($hasNext && $page <= 100);

        return $allResults;
    }
}
