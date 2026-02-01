<?php

declare(strict_types=1);

namespace SuperFPL\Api\Clients;

/**
 * Scraper for Oddschecker to get match odds and goalscorer odds.
 * Uses respectful scraping practices with rate limiting.
 */
class OddscheckerScraper
{
    private const BASE_URL = 'https://www.oddschecker.com';
    private const EPL_PATH = '/football/english/premier-league';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';

    private string $cacheDir;
    private int $requestDelay;

    public function __construct(
        string $cacheDir = '/tmp/oddschecker',
        int $requestDelayMs = 2000
    ) {
        $this->cacheDir = $cacheDir;
        $this->requestDelay = $requestDelayMs;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get match odds for upcoming EPL fixtures.
     *
     * @return array<int, array{home_team: string, away_team: string, home_win_prob: float, draw_prob: float, away_win_prob: float}>
     */
    public function getMatchOdds(): array
    {
        $html = $this->fetchPage(self::EPL_PATH);
        if ($html === null) {
            return [];
        }

        return $this->parseMatchOdds($html);
    }

    /**
     * Get anytime goalscorer odds for a specific fixture.
     *
     * @return array<string, float> Player name => probability
     */
    public function getGoalscorerOdds(string $homeTeam, string $awayTeam): array
    {
        $fixtureSlug = $this->createFixtureSlug($homeTeam, $awayTeam);
        $path = self::EPL_PATH . "/{$fixtureSlug}/anytime-scorecast";

        $html = $this->fetchPage($path);
        if ($html === null) {
            // Try alternate path format
            $path = self::EPL_PATH . "/{$fixtureSlug}/first-goalscorer";
            $html = $this->fetchPage($path);
        }

        if ($html === null) {
            return [];
        }

        return $this->parseGoalscorerOdds($html);
    }

    /**
     * Parse match odds from the EPL page HTML.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseMatchOdds(string $html): array
    {
        $fixtures = [];

        // Look for match cards with odds data
        // Pattern: home team name, away team name, and 1X2 odds
        preg_match_all(
            '/<div[^>]*class="[^"]*(?:match-card|event-card)[^"]*"[^>]*>.*?<\/div>/s',
            $html,
            $matches
        );

        // Alternative: Look for betting table rows
        preg_match_all(
            '/data-home-team="([^"]+)"[^>]*data-away-team="([^"]+)"[^>]*data-odds-home="([^"]+)"[^>]*data-odds-draw="([^"]+)"[^>]*data-odds-away="([^"]+)"/s',
            $html,
            $dataMatches,
            PREG_SET_ORDER
        );

        foreach ($dataMatches as $match) {
            $homeOdds = (float) $match[3];
            $drawOdds = (float) $match[4];
            $awayOdds = (float) $match[5];

            if ($homeOdds > 0 && $drawOdds > 0 && $awayOdds > 0) {
                $probs = $this->oddsToProb($homeOdds, $drawOdds, $awayOdds);
                $fixtures[] = [
                    'home_team' => $match[1],
                    'away_team' => $match[2],
                    'home_win_prob' => $probs['home'],
                    'draw_prob' => $probs['draw'],
                    'away_win_prob' => $probs['away'],
                ];
            }
        }

        // If structured data not found, try parsing text content
        if (empty($fixtures)) {
            $fixtures = $this->parseMatchOddsFromText($html);
        }

        return $fixtures;
    }

    /**
     * Fallback: Parse match odds from text content.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseMatchOddsFromText(string $html): array
    {
        $fixtures = [];

        // Look for team names and odds patterns
        // This is a simplified parser - real implementation would need DOM parsing
        preg_match_all(
            '/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s+v\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/u',
            strip_tags($html),
            $teamMatches,
            PREG_SET_ORDER
        );

        foreach ($teamMatches as $match) {
            // For now, use default probabilities
            // Real implementation would need to find the associated odds
            $fixtures[] = [
                'home_team' => trim($match[1]),
                'away_team' => trim($match[2]),
                'home_win_prob' => 0.40,
                'draw_prob' => 0.25,
                'away_win_prob' => 0.35,
            ];
        }

        return $fixtures;
    }

    /**
     * Parse goalscorer odds from fixture page.
     *
     * @return array<string, float> Player name => probability
     */
    private function parseGoalscorerOdds(string $html): array
    {
        $players = [];

        // Look for player names with odds
        preg_match_all(
            '/data-player="([^"]+)"[^>]*data-odds="([^"]+)"/s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $playerName = $match[1];
            $odds = (float) $match[2];

            if ($odds > 1) {
                // Convert odds to probability with overround removal
                $players[$playerName] = $this->singleOddsToProb($odds);
            }
        }

        // Alternative: parse from visible text
        if (empty($players)) {
            preg_match_all(
                '/([A-Z][a-z]+\s+[A-Z][a-z]+)\s+(\d+\/\d+|\d+\.\d+)/u',
                strip_tags($html),
                $textMatches,
                PREG_SET_ORDER
            );

            foreach ($textMatches as $match) {
                $playerName = $match[1];
                $odds = $this->parseFractionalOdds($match[2]);
                if ($odds > 1) {
                    $players[$playerName] = $this->singleOddsToProb($odds);
                }
            }
        }

        return $players;
    }

    /**
     * Convert decimal odds to probabilities with overround removal.
     *
     * @return array{home: float, draw: float, away: float}
     */
    private function oddsToProb(float $homeOdds, float $drawOdds, float $awayOdds): array
    {
        // Calculate implied probabilities
        $homeProb = 1 / $homeOdds;
        $drawProb = 1 / $drawOdds;
        $awayProb = 1 / $awayOdds;

        // Remove overround by normalizing
        $total = $homeProb + $drawProb + $awayProb;

        return [
            'home' => round($homeProb / $total, 4),
            'draw' => round($drawProb / $total, 4),
            'away' => round($awayProb / $total, 4),
        ];
    }

    /**
     * Convert single decimal odds to probability.
     * Assumes ~10% overround for anytime scorer markets.
     */
    private function singleOddsToProb(float $odds): float
    {
        $implied = 1 / $odds;
        // Remove estimated overround
        return round($implied / 1.10, 4);
    }

    /**
     * Parse fractional odds (e.g., "5/2") to decimal.
     */
    private function parseFractionalOdds(string $odds): float
    {
        if (str_contains($odds, '/')) {
            $parts = explode('/', $odds);
            if (count($parts) === 2) {
                $numerator = (float) $parts[0];
                $denominator = (float) $parts[1];
                if ($denominator > 0) {
                    return ($numerator / $denominator) + 1;
                }
            }
        }

        // Already decimal
        return (float) $odds;
    }

    /**
     * Create a URL slug for a fixture.
     */
    private function createFixtureSlug(string $homeTeam, string $awayTeam): string
    {
        $home = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $homeTeam));
        $away = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $awayTeam));
        return "{$home}-v-{$away}";
    }

    /**
     * Fetch a page with caching and rate limiting.
     */
    private function fetchPage(string $path): ?string
    {
        // Check cache first (1 hour TTL)
        $cacheKey = md5($path);
        $cacheFile = "{$this->cacheDir}/{$cacheKey}.html";

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            return file_get_contents($cacheFile);
        }

        // Rate limit
        $lastRequestFile = "{$this->cacheDir}/last_request";
        if (file_exists($lastRequestFile)) {
            $elapsed = (microtime(true) - (float) file_get_contents($lastRequestFile)) * 1000;
            if ($elapsed < $this->requestDelay) {
                usleep((int) (($this->requestDelay - $elapsed) * 1000));
            }
        }

        $url = self::BASE_URL . $path;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: en-GB,en;q=0.9',
                ],
                'timeout' => 30,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);
        file_put_contents($lastRequestFile, microtime(true));

        if ($html === false) {
            error_log("OddscheckerScraper: Failed to fetch {$url}");
            return null;
        }

        // Cache the result
        file_put_contents($cacheFile, $html);

        return $html;
    }
}
