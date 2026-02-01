<?php

declare(strict_types=1);

namespace SuperFPL\Api\Clients;

/**
 * Client for The Odds API (https://the-odds-api.com/)
 * Free tier: 500 requests/month
 */
class OddsApiClient
{
    private const BASE_URL = 'https://api.the-odds-api.com/v4';
    private const SPORT = 'soccer_epl';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $cacheDir = '/tmp'
    ) {}

    /**
     * Fetch odds for upcoming EPL fixtures.
     *
     * @param string[] $markets Markets to fetch (e.g., 'h2h', 'totals')
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingOdds(array $markets = ['h2h', 'totals']): array
    {
        $marketsParam = implode(',', $markets);
        $url = self::BASE_URL . "/sports/" . self::SPORT . "/odds"
            . "?apiKey={$this->apiKey}"
            . "&regions=uk"
            . "&markets={$marketsParam}"
            . "&oddsFormat=decimal";

        $response = $this->makeRequest($url);

        if ($response === null) {
            return [];
        }

        return $this->parseOddsResponse($response);
    }

    /**
     * Get remaining API quota.
     *
     * @return array{requests_remaining: int, requests_used: int}|null
     */
    public function getQuota(): ?array
    {
        // The Odds API returns quota info in response headers
        // We'll track it from the last request
        $quotaFile = $this->cacheDir . '/odds_api_quota.json';
        if (file_exists($quotaFile)) {
            $data = json_decode(file_get_contents($quotaFile), true);
            return $data ?: null;
        }
        return null;
    }

    /**
     * Parse odds response and convert to probabilities.
     *
     * @param array<int, array<string, mixed>> $response
     * @return array<int, array<string, mixed>>
     */
    private function parseOddsResponse(array $response): array
    {
        $fixtures = [];

        foreach ($response as $event) {
            $fixture = [
                'id' => $event['id'] ?? '',
                'home_team' => $event['home_team'] ?? '',
                'away_team' => $event['away_team'] ?? '',
                'commence_time' => $event['commence_time'] ?? '',
                'bookmakers' => [],
            ];

            // Get average odds across bookmakers
            $h2hOdds = $this->extractMarketOdds($event, 'h2h');
            $totalsOdds = $this->extractMarketOdds($event, 'totals');

            if (!empty($h2hOdds)) {
                $avgOdds = $this->averageOdds($h2hOdds);
                $probs = $this->oddsToProb($avgOdds);

                $fixture['home_win_prob'] = $probs['home'] ?? 0;
                $fixture['draw_prob'] = $probs['draw'] ?? 0;
                $fixture['away_win_prob'] = $probs['away'] ?? 0;

                // Estimate clean sheet probabilities from match odds
                // Rough approximation: CS prob correlates with win prob and low goals
                $fixture['home_cs_prob'] = $this->estimateCleanSheetProb(
                    $probs['home'] ?? 0,
                    $probs['away'] ?? 0,
                    $totalsOdds
                );
                $fixture['away_cs_prob'] = $this->estimateCleanSheetProb(
                    $probs['away'] ?? 0,
                    $probs['home'] ?? 0,
                    $totalsOdds
                );
            }

            if (!empty($totalsOdds)) {
                $fixture['expected_total_goals'] = $this->estimateTotalGoals($totalsOdds);
            }

            $fixtures[] = $fixture;
        }

        return $fixtures;
    }

    /**
     * Extract odds for a specific market from event data.
     *
     * @param array<string, mixed> $event
     * @return array<int, array<string, float>>
     */
    private function extractMarketOdds(array $event, string $market): array
    {
        $odds = [];

        foreach ($event['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $mkt) {
                if ($mkt['key'] !== $market) {
                    continue;
                }

                $outcomes = [];
                foreach ($mkt['outcomes'] ?? [] as $outcome) {
                    $outcomes[$outcome['name']] = (float) $outcome['price'];
                }

                if (!empty($outcomes)) {
                    $odds[] = $outcomes;
                }
            }
        }

        return $odds;
    }

    /**
     * Calculate average odds across bookmakers.
     *
     * @param array<int, array<string, float>> $allOdds
     * @return array<string, float>
     */
    private function averageOdds(array $allOdds): array
    {
        if (empty($allOdds)) {
            return [];
        }

        $sums = [];
        $counts = [];

        foreach ($allOdds as $bookmakerOdds) {
            foreach ($bookmakerOdds as $outcome => $odds) {
                if (!isset($sums[$outcome])) {
                    $sums[$outcome] = 0;
                    $counts[$outcome] = 0;
                }
                $sums[$outcome] += $odds;
                $counts[$outcome]++;
            }
        }

        $averages = [];
        foreach ($sums as $outcome => $sum) {
            $averages[$outcome] = $sum / $counts[$outcome];
        }

        return $averages;
    }

    /**
     * Convert decimal odds to probabilities (with overround removal).
     *
     * @param array<string, float> $odds ['Home Team' => 2.1, 'Draw' => 3.5, 'Away Team' => 3.8]
     * @return array<string, float> ['home' => 0.45, 'draw' => 0.28, 'away' => 0.27]
     */
    private function oddsToProb(array $odds): array
    {
        if (count($odds) < 3) {
            return [];
        }

        // Convert each odds to implied probability
        $impliedProbs = [];
        foreach ($odds as $outcome => $decimal) {
            $impliedProbs[$outcome] = 1 / $decimal;
        }

        // Calculate overround (total implied prob > 1)
        $overround = array_sum($impliedProbs);

        // Normalize to remove overround
        $normalized = [];
        foreach ($impliedProbs as $outcome => $prob) {
            $normalized[$outcome] = $prob / $overround;
        }

        // Map to standard keys
        $result = [];
        foreach ($normalized as $outcome => $prob) {
            $lower = strtolower($outcome);
            if (str_contains($lower, 'draw')) {
                $result['draw'] = round($prob, 4);
            } elseif (isset($result['home'])) {
                $result['away'] = round($prob, 4);
            } else {
                $result['home'] = round($prob, 4);
            }
        }

        return $result;
    }

    /**
     * Estimate clean sheet probability based on match odds and totals.
     */
    private function estimateCleanSheetProb(
        float $teamWinProb,
        float $opponentWinProb,
        array $totalsOdds
    ): float {
        // Base CS probability from defensive strength (inverse of opponent's attack)
        $baseCs = 0.25; // League average ~25% CS rate

        // Adjust based on opponent's win probability (stronger opponent = lower CS chance)
        $adjustedCs = $baseCs * (1 - ($opponentWinProb * 0.5));

        // If we have under goals data, factor that in
        if (!empty($totalsOdds)) {
            $expectedGoals = $this->estimateTotalGoals($totalsOdds);
            // Lower expected goals = higher CS probability
            if ($expectedGoals > 0) {
                $goalsMultiplier = max(0.5, min(1.5, 2.5 / $expectedGoals));
                $adjustedCs *= $goalsMultiplier;
            }
        }

        return round(min(0.6, max(0.05, $adjustedCs)), 4);
    }

    /**
     * Estimate expected total goals from over/under odds.
     *
     * @param array<int, array<string, float>> $totalsOdds
     */
    private function estimateTotalGoals(array $totalsOdds): float
    {
        if (empty($totalsOdds)) {
            return 2.5; // Default expected goals
        }

        $avgOdds = $this->averageOdds($totalsOdds);

        // Look for Over 2.5 odds
        $over25 = null;
        $under25 = null;

        foreach ($avgOdds as $outcome => $odds) {
            if (str_contains($outcome, 'Over')) {
                $over25 = $odds;
            } elseif (str_contains($outcome, 'Under')) {
                $under25 = $odds;
            }
        }

        if ($over25 !== null && $under25 !== null) {
            // Convert to probabilities
            $overProb = 1 / $over25;
            $underProb = 1 / $under25;
            $total = $overProb + $underProb;
            $normalizedOver = $overProb / $total;

            // Estimate goals: if over 2.5 is likely, expect more goals
            // over2.5 prob of 0.5 = 2.5 goals, 0.7 = ~3 goals, 0.3 = ~2 goals
            return round(2.5 + (($normalizedOver - 0.5) * 2), 2);
        }

        return 2.5;
    }

    /**
     * Make HTTP request to the API.
     *
     * @return array<int|string, mixed>|null
     */
    private function makeRequest(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Accept: application/json',
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            error_log("OddsApiClient: Failed to fetch from {$url}");
            return null;
        }

        // Parse quota from headers
        if (isset($http_response_header)) {
            $this->parseQuotaHeaders($http_response_header);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("OddsApiClient: Invalid JSON response");
            return null;
        }

        return $data;
    }

    /**
     * Parse and cache quota information from response headers.
     *
     * @param array<int, string> $headers
     */
    private function parseQuotaHeaders(array $headers): void
    {
        $quota = [];

        foreach ($headers as $header) {
            if (str_starts_with($header, 'x-requests-remaining:')) {
                $quota['requests_remaining'] = (int) trim(substr($header, 21));
            } elseif (str_starts_with($header, 'x-requests-used:')) {
                $quota['requests_used'] = (int) trim(substr($header, 16));
            }
        }

        if (!empty($quota)) {
            $quotaFile = $this->cacheDir . '/odds_api_quota.json';
            file_put_contents($quotaFile, json_encode($quota));
        }
    }
}
