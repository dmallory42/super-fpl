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

    private int $cacheTtl;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $cacheDir = '/tmp',
        int $cacheTtlSeconds = 86400  // 24 hours default - limits to ~30 requests/month
    ) {
        $this->cacheTtl = $cacheTtlSeconds;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get match odds for upcoming EPL fixtures.
     * Compatible with OddsSync interface.
     *
     * @return array<int, array{
     *     home_team: string,
     *     away_team: string,
     *     home_win_prob: float,
     *     draw_prob: float,
     *     away_win_prob: float,
     *     home_cs_prob: float,
     *     away_cs_prob: float,
     *     expected_total_goals: float
     * }>
     */
    public function getMatchOdds(): array
    {
        return $this->getUpcomingOdds(['h2h', 'totals']);
    }

    /**
     * Get anytime goalscorer odds for upcoming EPL fixtures.
     * Note: Limited to US bookmakers per API docs.
     * Uses event-specific endpoint since player props aren't in bulk odds endpoint.
     *
     * @return array<int, array{
     *     event_id: string,
     *     home_team: string,
     *     away_team: string,
     *     players: array<string, float>
     * }>
     */
    public function getGoalscorerOdds(): array
    {
        // First, get list of upcoming events
        $eventsUrl = self::BASE_URL . "/sports/" . self::SPORT . "/events"
            . "?apiKey={$this->apiKey}";

        $events = $this->makeRequest($eventsUrl);

        if ($events === null || empty($events)) {
            return [];
        }

        $fixtures = [];

        // Fetch goalscorer odds for each event (uses caching to limit API calls)
        foreach ($events as $event) {
            $eventId = $event['id'] ?? '';
            if (empty($eventId)) {
                continue;
            }

            $oddsUrl = self::BASE_URL . "/sports/" . self::SPORT . "/events/{$eventId}/odds"
                . "?apiKey={$this->apiKey}"
                . "&regions=us"  // Player props limited to US bookmakers
                . "&markets=player_goal_scorer_anytime"
                . "&oddsFormat=decimal";

            $oddsResponse = $this->makeRequest($oddsUrl);

            if ($oddsResponse === null) {
                continue;
            }

            $players = $this->extractPlayerGoalscorerOdds($oddsResponse);
            if (!empty($players)) {
                $fixtures[] = [
                    'event_id' => $eventId,
                    'home_team' => $oddsResponse['home_team'] ?? $event['home_team'] ?? '',
                    'away_team' => $oddsResponse['away_team'] ?? $event['away_team'] ?? '',
                    'players' => $players,
                ];
            }
        }

        return $fixtures;
    }

    /**
     * Get anytime assist odds for upcoming EPL fixtures.
     * Uses event-specific endpoint with player_assists market.
     *
     * @return array<int, array{
     *     event_id: string,
     *     home_team: string,
     *     away_team: string,
     *     players: array<string, float>
     * }>
     */
    public function getAssistOdds(): array
    {
        // First, get list of upcoming events
        $eventsUrl = self::BASE_URL . "/sports/" . self::SPORT . "/events"
            . "?apiKey={$this->apiKey}";

        $events = $this->makeRequest($eventsUrl);

        if ($events === null || empty($events)) {
            return [];
        }

        $fixtures = [];

        foreach ($events as $event) {
            $eventId = $event['id'] ?? '';
            if (empty($eventId)) {
                continue;
            }

            $oddsUrl = self::BASE_URL . "/sports/" . self::SPORT . "/events/{$eventId}/odds"
                . "?apiKey={$this->apiKey}"
                . "&regions=us"
                . "&markets=player_assists"
                . "&oddsFormat=decimal";

            $oddsResponse = $this->makeRequest($oddsUrl);

            if ($oddsResponse === null) {
                continue;
            }

            $players = $this->extractPlayerAssistOdds($oddsResponse);
            if (!empty($players)) {
                $fixtures[] = [
                    'event_id' => $eventId,
                    'home_team' => $oddsResponse['home_team'] ?? $event['home_team'] ?? '',
                    'away_team' => $oddsResponse['away_team'] ?? $event['away_team'] ?? '',
                    'players' => $players,
                ];
            }
        }

        return $fixtures;
    }

    /**
     * Extract anytime assist probabilities from event data.
     *
     * @return array<string, float> Player name => probability
     */
    private function extractPlayerAssistOdds(array $event): array
    {
        $players = [];

        foreach ($event['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                if ($market['key'] !== 'player_assists') {
                    continue;
                }

                foreach ($market['outcomes'] ?? [] as $outcome) {
                    $playerName = $outcome['description'] ?? $outcome['name'] ?? '';
                    $price = (float) ($outcome['price'] ?? 0);

                    if ($playerName && $price > 1) {
                        $prob = (1 / $price) / 1.10;

                        if (isset($players[$playerName])) {
                            $players[$playerName] = ($players[$playerName] + $prob) / 2;
                        } else {
                            $players[$playerName] = round($prob, 4);
                        }
                    }
                }
            }
        }

        return $players;
    }

    /**
     * Extract anytime goalscorer probabilities from event data.
     * Handles both bulk and event-specific API response formats.
     *
     * @return array<string, float> Player name => probability
     */
    private function extractPlayerGoalscorerOdds(array $event): array
    {
        $players = [];

        foreach ($event['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                if ($market['key'] !== 'player_goal_scorer_anytime') {
                    continue;
                }

                foreach ($market['outcomes'] ?? [] as $outcome) {
                    // Player name is in 'description' for event-specific endpoint
                    // or 'name' for bulk endpoint
                    $playerName = $outcome['description'] ?? $outcome['name'] ?? '';
                    $price = (float) ($outcome['price'] ?? 0);

                    if ($playerName && $price > 1) {
                        // Convert odds to probability, remove ~10% overround
                        $prob = (1 / $price) / 1.10;

                        // Average if we already have odds for this player
                        if (isset($players[$playerName])) {
                            $players[$playerName] = ($players[$playerName] + $prob) / 2;
                        } else {
                            $players[$playerName] = round($prob, 4);
                        }
                    }
                }
            }
        }

        return $players;
    }

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
     *
     * Uses team's win probability as a proxy for defensive dominance.
     * Strong favorites (80%+ win prob) should have ~40-50% CS probability.
     */
    private function estimateCleanSheetProb(
        float $teamWinProb,
        float $opponentWinProb,
        array $totalsOdds
    ): float {
        // CS probability correlates with team strength and opponent weakness
        // Win prob of 0.80 (strong favorite) → ~45% CS
        // Win prob of 0.50 (even match) → ~28% CS
        // Win prob of 0.20 (underdog) → ~15% CS
        $baseCs = 0.15 + ($teamWinProb * 0.40);

        // Further adjust based on opponent weakness
        // Very weak opponent (low win prob) increases CS chance
        $opponentWeakness = 1 - $opponentWinProb;
        $adjustedCs = $baseCs * (0.7 + ($opponentWeakness * 0.4));

        // If we have under goals data, factor that in
        if (!empty($totalsOdds)) {
            $expectedGoals = $this->estimateTotalGoals($totalsOdds);
            // Lower expected goals = higher CS probability
            if ($expectedGoals > 0) {
                $goalsMultiplier = max(0.7, min(1.3, 2.5 / $expectedGoals));
                $adjustedCs *= $goalsMultiplier;
            }
        }

        return round(min(0.55, max(0.08, $adjustedCs)), 4);
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
     * Make HTTP request to the API with caching.
     *
     * @return array<int|string, mixed>|null
     */
    private function makeRequest(string $url): ?array
    {
        // Check cache first (default 24 hours to limit API usage)
        $cacheKey = md5($url);
        $cacheFile = $this->cacheDir . "/odds_cache_{$cacheKey}.json";

        if (file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $this->cacheTtl) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if ($cached !== null) {
                    error_log("OddsApiClient: Using cached data ({$cacheAge}s old, TTL: {$this->cacheTtl}s)");
                    return $cached;
                }
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Accept: application/json',
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            error_log("OddsApiClient: Failed to fetch from API");
            return null;
        }

        // Parse quota from headers (PHP 8.4+ compatible)
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? []);
        $this->parseQuotaHeaders($headers);

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("OddsApiClient: Invalid JSON response");
            return null;
        }

        // Cache successful response
        file_put_contents($cacheFile, json_encode($data));
        error_log("OddsApiClient: Fetched fresh data from API, cached for {$this->cacheTtl}s");

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
