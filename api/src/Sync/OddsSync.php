<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use SuperFPL\Api\Database;
use SuperFPL\Api\Clients\OddscheckerScraper;

/**
 * Syncs odds data from Oddschecker to the database.
 */
class OddsSync
{
    public function __construct(
        private readonly Database $db,
        private readonly OddscheckerScraper $scraper
    ) {}

    /**
     * Sync match odds for upcoming fixtures.
     *
     * @return array{fixtures: int, matched: int}
     */
    public function syncMatchOdds(): array
    {
        $matchOdds = $this->scraper->getMatchOdds();
        $fixtures = $this->getUpcomingFixtures();

        $matched = 0;

        foreach ($matchOdds as $odds) {
            $fixture = $this->matchFixture($odds['home_team'], $odds['away_team'], $fixtures);
            if ($fixture === null) {
                continue;
            }

            $this->db->upsert('fixture_odds', [
                'fixture_id' => $fixture['id'],
                'home_win_prob' => $odds['home_win_prob'],
                'draw_prob' => $odds['draw_prob'],
                'away_win_prob' => $odds['away_win_prob'],
                'home_cs_prob' => $odds['home_cs_prob'] ?? $this->estimateCleanSheetProb($odds, true),
                'away_cs_prob' => $odds['away_cs_prob'] ?? $this->estimateCleanSheetProb($odds, false),
                'expected_total_goals' => $odds['expected_total_goals'] ?? 2.5,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['fixture_id']);

            $matched++;
        }

        return [
            'fixtures' => count($matchOdds),
            'matched' => $matched,
        ];
    }

    /**
     * Sync goalscorer odds for a specific fixture.
     *
     * @return int Number of players with odds
     */
    public function syncGoalscorerOdds(int $fixtureId): int
    {
        $fixture = $this->db->fetchOne('SELECT * FROM fixtures WHERE id = ?', [$fixtureId]);
        if ($fixture === null) {
            return 0;
        }

        // Get team names
        $homeTeam = $this->db->fetchOne('SELECT name FROM clubs WHERE id = ?', [$fixture['home_club_id']]);
        $awayTeam = $this->db->fetchOne('SELECT name FROM clubs WHERE id = ?', [$fixture['away_club_id']]);

        if ($homeTeam === null || $awayTeam === null) {
            return 0;
        }

        $odds = $this->scraper->getGoalscorerOdds($homeTeam['name'], $awayTeam['name']);

        $count = 0;
        foreach ($odds as $playerName => $prob) {
            // Try to match player by name
            $player = $this->matchPlayerByName($playerName);
            if ($player === null) {
                continue;
            }

            $this->db->upsert('player_goalscorer_odds', [
                'player_id' => $player['id'],
                'fixture_id' => $fixtureId,
                'anytime_scorer_prob' => $prob,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['player_id', 'fixture_id']);

            $count++;
        }

        return $count;
    }

    /**
     * Sync goalscorer odds for all upcoming fixtures in a gameweek.
     *
     * @return array{fixtures: int, players: int}
     */
    public function syncGoalscorerOddsForGameweek(int $gameweek): array
    {
        $fixtures = $this->db->fetchAll(
            'SELECT id FROM fixtures WHERE gameweek = ? AND finished = 0',
            [$gameweek]
        );

        $totalPlayers = 0;
        foreach ($fixtures as $fixture) {
            $totalPlayers += $this->syncGoalscorerOdds((int) $fixture['id']);
            // Be respectful with rate limiting
            sleep(3);
        }

        return [
            'fixtures' => count($fixtures),
            'players' => $totalPlayers,
        ];
    }

    /**
     * Get upcoming fixtures (not finished).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getUpcomingFixtures(): array
    {
        return $this->db->fetchAll(
            "SELECT f.*, h.name as home_name, a.name as away_name
            FROM fixtures f
            JOIN clubs h ON f.home_club_id = h.id
            JOIN clubs a ON f.away_club_id = a.id
            WHERE f.finished = 0
            ORDER BY f.kickoff_time"
        );
    }

    /**
     * Match odds data to a fixture by team names.
     *
     * @param array<int, array<string, mixed>> $fixtures
     * @return array<string, mixed>|null
     */
    private function matchFixture(string $homeTeam, string $awayTeam, array $fixtures): ?array
    {
        $homeTeam = $this->normalizeTeamName($homeTeam);
        $awayTeam = $this->normalizeTeamName($awayTeam);

        foreach ($fixtures as $fixture) {
            $fixtureHome = $this->normalizeTeamName($fixture['home_name']);
            $fixtureAway = $this->normalizeTeamName($fixture['away_name']);

            if ($this->teamsMatch($homeTeam, $fixtureHome) && $this->teamsMatch($awayTeam, $fixtureAway)) {
                return $fixture;
            }
        }

        return null;
    }

    /**
     * Normalize team name for matching.
     */
    private function normalizeTeamName(string $name): string
    {
        $name = strtolower($name);
        // Remove common suffixes
        $name = preg_replace('/\s*(fc|afc|city|united|town|wanderers|rovers|albion)$/i', '', $name);
        // Remove special characters
        $name = preg_replace('/[^a-z0-9]/', '', $name);
        return trim($name);
    }

    /**
     * Check if two team names match.
     */
    private function teamsMatch(string $name1, string $name2): bool
    {
        // Exact match
        if ($name1 === $name2) {
            return true;
        }

        // One contains the other
        if (str_contains($name1, $name2) || str_contains($name2, $name1)) {
            return true;
        }

        // Levenshtein distance for fuzzy matching
        $distance = levenshtein($name1, $name2);
        $maxLen = max(strlen($name1), strlen($name2));
        return $maxLen > 0 && ($distance / $maxLen) < 0.3;
    }

    /**
     * Match a player by name (fuzzy).
     *
     * @return array<string, mixed>|null
     */
    private function matchPlayerByName(string $name): ?array
    {
        // Try exact web_name match first
        $player = $this->db->fetchOne(
            'SELECT id FROM players WHERE LOWER(web_name) = LOWER(?)',
            [$name]
        );

        if ($player !== null) {
            return $player;
        }

        // Try second_name match
        $parts = explode(' ', $name);
        $lastName = end($parts);

        $player = $this->db->fetchOne(
            'SELECT id FROM players WHERE LOWER(second_name) = LOWER(?)',
            [$lastName]
        );

        return $player;
    }

    /**
     * Estimate clean sheet probability from match odds.
     *
     * @param array<string, mixed> $odds
     */
    private function estimateCleanSheetProb(array $odds, bool $isHome): float
    {
        $winProb = $isHome ? ($odds['home_win_prob'] ?? 0.33) : ($odds['away_win_prob'] ?? 0.33);
        $drawProb = $odds['draw_prob'] ?? 0.25;

        // CS probability correlates with win/draw probability and low opponent attack
        // Rough estimate: base 25% CS rate, adjusted by team strength
        $baseCs = 0.25;
        $adjustment = $winProb * 0.3 + $drawProb * 0.5;

        return min(0.5, max(0.1, $baseCs + $adjustment));
    }
}
