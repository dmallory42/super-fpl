<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

use SuperFPL\Api\Database;

/**
 * Derives team attacking/defensive strength from xG data.
 *
 * Used as a fallback when bookmaker odds are unavailable (e.g. fixtures
 * too far in the future for bookmakers to price). Computes multiplicative
 * fixture adjustments from team xGF/game and xGA/game.
 */
class TeamStrength
{
    /** @var array<int, float> xGFor per game by club_id */
    private array $xgfPerGame = [];

    /** @var array<int, float> xG Against per game by club_id */
    private array $xgaPerGame = [];

    private float $leagueAvgXGF = 1.3;
    private float $leagueAvgXGA = 1.3;
    private float $homeBoost = 1.08;
    private float $awayPenalty = 0.93;
    private bool $available = false;

    public function __construct(Database $db)
    {
        $this->loadTeamXGF($db);
        $this->loadTeamXGA($db);
        $this->computeLeagueAverages();
        $this->computeHomeAdvantage($db);
    }

    /**
     * Expected goals for $clubId when playing $opponentId at home/away.
     */
    public function getExpectedGoals(int $clubId, int $opponentId, bool $isHome): float
    {
        $teamXGF = $this->xgfPerGame[$clubId] ?? $this->leagueAvgXGF;
        $oppXGA = $this->xgaPerGame[$opponentId] ?? $this->leagueAvgXGA;
        $expected = $teamXGF * ($oppXGA / $this->leagueAvgXGA);
        $expected *= $isHome ? $this->homeBoost : $this->awayPenalty;
        return $expected;
    }

    /**
     * Expected goals against $clubId (opponent's attack vs team's defense).
     */
    public function getExpectedGoalsAgainst(int $clubId, int $opponentId, bool $isHome): float
    {
        $oppXGF = $this->xgfPerGame[$opponentId] ?? $this->leagueAvgXGF;
        $teamXGA = $this->xgaPerGame[$clubId] ?? $this->leagueAvgXGA;
        $expected = $oppXGF * ($teamXGA / $this->leagueAvgXGA);
        // Opponent is away if we're home
        $expected *= $isHome ? $this->awayPenalty : $this->homeBoost;
        return $expected;
    }

    public function getLeagueAvgXGF(): float
    {
        return $this->leagueAvgXGF;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Load team xGF from outfield players (position != 1).
     * Divide by finished fixtures count for per-game rate.
     */
    private function loadTeamXGF(Database $db): void
    {
        $teamXG = $db->fetchAll(
            'SELECT club_id, SUM(expected_goals) as total_xg
             FROM players WHERE position != 1
             GROUP BY club_id'
        );

        $teamGames = $this->getTeamGames($db);

        foreach ($teamXG as $row) {
            $clubId = (int) $row['club_id'];
            $games = $teamGames[$clubId] ?? 0;
            if ($games > 0) {
                $this->xgfPerGame[$clubId] = (float) $row['total_xg'] / $games;
            }
        }
    }

    /**
     * Load team xGA from GK expected_goals_conceded.
     * All GKs per team combined naturally handles rotation.
     */
    private function loadTeamXGA(Database $db): void
    {
        $teamXGC = $db->fetchAll(
            'SELECT club_id, SUM(expected_goals_conceded) as total_xgc
             FROM players WHERE position = 1
             GROUP BY club_id'
        );

        $teamGames = $this->getTeamGames($db);

        foreach ($teamXGC as $row) {
            $clubId = (int) $row['club_id'];
            $games = $teamGames[$clubId] ?? 0;
            if ($games > 0) {
                $this->xgaPerGame[$clubId] = (float) $row['total_xgc'] / $games;
            }
        }
    }

    /**
     * Count finished fixtures per team.
     */
    private function getTeamGames(Database $db): array
    {
        $rows = $db->fetchAll(
            'SELECT club_id, COUNT(*) as games FROM (
                SELECT home_club_id as club_id FROM fixtures WHERE finished = 1
                UNION ALL
                SELECT away_club_id as club_id FROM fixtures WHERE finished = 1
             ) GROUP BY club_id'
        );

        $games = [];
        foreach ($rows as $row) {
            $games[(int) $row['club_id']] = (int) $row['games'];
        }
        return $games;
    }

    private function computeLeagueAverages(): void
    {
        if (empty($this->xgfPerGame)) {
            return;
        }

        $this->leagueAvgXGF = array_sum($this->xgfPerGame) / count($this->xgfPerGame);

        if (!empty($this->xgaPerGame)) {
            $this->leagueAvgXGA = array_sum($this->xgaPerGame) / count($this->xgaPerGame);
        }

        // Sanity: avoid division by zero
        $this->leagueAvgXGF = max(0.5, $this->leagueAvgXGF);
        $this->leagueAvgXGA = max(0.5, $this->leagueAvgXGA);

        $this->available = count($this->xgfPerGame) >= 10;
    }

    /**
     * Compute home/away advantage from player_gameweek_history xG.
     */
    private function computeHomeAdvantage(Database $db): void
    {
        $row = $db->fetchOne(
            'SELECT
                SUM(CASE WHEN was_home = 1 THEN expected_goals ELSE 0 END) as home_xg,
                COUNT(DISTINCT CASE WHEN was_home = 1 THEN fixture_id END) as home_fixtures,
                SUM(CASE WHEN was_home = 0 THEN expected_goals ELSE 0 END) as away_xg,
                COUNT(DISTINCT CASE WHEN was_home = 0 THEN fixture_id END) as away_fixtures
             FROM player_gameweek_history'
        );

        if ($row === null) {
            return;
        }

        $homeFixtures = (int) ($row['home_fixtures'] ?? 0);
        $awayFixtures = (int) ($row['away_fixtures'] ?? 0);

        if ($homeFixtures < 50 || $awayFixtures < 50) {
            return; // Not enough data, keep defaults
        }

        $homeXgPerFixture = (float) $row['home_xg'] / $homeFixtures;
        $awayXgPerFixture = (float) $row['away_xg'] / $awayFixtures;
        $avgXgPerFixture = ($homeXgPerFixture + $awayXgPerFixture) / 2;

        if ($avgXgPerFixture > 0) {
            $this->homeBoost = $homeXgPerFixture / $avgXgPerFixture;
            $this->awayPenalty = $awayXgPerFixture / $avgXgPerFixture;

            // Clamp to reasonable range
            $this->homeBoost = max(1.0, min(1.2, $this->homeBoost));
            $this->awayPenalty = max(0.8, min(1.0, $this->awayPenalty));
        }
    }
}
