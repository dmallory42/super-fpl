<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Endpoints;

use SuperFPL\FplClient\HttpClient;

class PlayerEndpoint
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $playerId
    ) {
    }

    /**
     * Get player summary including history.
     *
     * @return array{fixtures: array, history: array, history_past: array}
     */
    public function getSummary(): array
    {
        return $this->httpClient->get("element-summary/{$this->playerId}/");
    }

    /**
     * Get player's gameweek history for current season.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(): array
    {
        $summary = $this->getSummary();
        return $summary['history'] ?? [];
    }

    /**
     * Get player's past season history.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistoryPast(): array
    {
        $summary = $this->getSummary();
        return $summary['history_past'] ?? [];
    }
}
