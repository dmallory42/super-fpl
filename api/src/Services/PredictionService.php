<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\Api\Prediction\PredictionEngine;

class PredictionService
{
    private Database $db;
    private PredictionEngine $engine;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->engine = new PredictionEngine($db);
    }

    /**
     * Get predictions for all players for a specific gameweek.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPredictions(int $gameweek): array
    {
        // First check if we have cached predictions (v2.0 only)
        $cached = $this->getCachedPredictions($gameweek);
        if (!empty($cached)) {
            return $cached;
        }

        // Generate fresh predictions
        return $this->generatePredictions($gameweek);
    }

    /**
     * Generate predictions for all players.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generatePredictions(int $gameweek): array
    {
        $players = $this->db->fetchAll('SELECT * FROM players');
        $fixtures = $this->getFixturesForGameweek($gameweek);
        $fixtureOdds = $this->getFixtureOdds($gameweek);
        $goalscorerOdds = $this->getGoalscorerOdds($gameweek);
        $assistOdds = $this->getAssistOdds($gameweek);
        $teamGames = $this->getTeamGames();

        $predictions = [];

        foreach ($players as $player) {
            $clubId = (int) $player['club_id'];
            $playerId = (int) $player['id'];
            $playerTeamGames = $teamGames[$clubId] ?? 24;

            // Find ALL player fixtures (handles DGWs)
            $playerFixtures = $this->findPlayerFixtures($clubId, $fixtures);

            if (empty($playerFixtures)) {
                // No fixture - blank gameweek for this player
                $prediction = $this->engine->predict($player, null, null, null, null, $playerTeamGames);
                $fixtureInfo = null;
            } else {
                // Sum predictions across all fixtures (DGW support)
                $totalPoints = 0.0;
                $totalConfidence = 0.0;
                $combinedBreakdown = [];
                $fixtureInfoList = [];

                foreach ($playerFixtures as $fixture) {
                    $odds = $fixtureOdds[$fixture['id']] ?? null;
                    $key = $playerId . '_' . $fixture['id'];
                    $playerGoalscorerOdds = $goalscorerOdds[$key] ?? null;
                    $playerAssistOdds = $assistOdds[$key] ?? null;

                    $fixturePrediction = $this->engine->predict(
                        $player,
                        $fixture,
                        $odds,
                        $playerGoalscorerOdds,
                        $playerAssistOdds,
                        $playerTeamGames
                    );
                    $totalPoints += $fixturePrediction['predicted_points'];
                    $totalConfidence += $fixturePrediction['confidence'];

                    // Merge breakdown (sum values)
                    foreach ($fixturePrediction['breakdown'] as $bkey => $value) {
                        $combinedBreakdown[$bkey] = ($combinedBreakdown[$bkey] ?? 0) + $value;
                    }

                    $fixtureInfoList[] = [
                        'opponent' => $fixture['home_club_id'] === $clubId
                            ? $fixture['away_club_id']
                            : $fixture['home_club_id'],
                        'is_home' => $fixture['home_club_id'] === $clubId,
                        'difficulty' => $fixture['home_club_id'] === $clubId
                            ? $fixture['home_difficulty']
                            : $fixture['away_difficulty'],
                    ];
                }

                $prediction = [
                    'predicted_points' => round($totalPoints, 2),
                    'confidence' => round($totalConfidence / count($playerFixtures), 2),
                    'breakdown' => $combinedBreakdown,
                ];

                // For single fixture, return object; for DGW return array
                $fixtureInfo = count($fixtureInfoList) === 1 ? $fixtureInfoList[0] : $fixtureInfoList;
            }

            $predictions[] = [
                'player_id' => $player['id'],
                'web_name' => $player['web_name'],
                'team' => $clubId,
                'position' => $player['position'],
                'now_cost' => $player['now_cost'],
                'form' => $player['form'],
                'total_points' => $player['total_points'],
                'predicted_points' => $prediction['predicted_points'],
                'confidence' => $prediction['confidence'],
                'breakdown' => $prediction['breakdown'],
                'fixture' => $fixtureInfo,
                'fixture_count' => count($playerFixtures),
            ];

            // Cache the prediction
            $this->cachePrediction($player['id'], $gameweek, $prediction);
        }

        // Sort by predicted points descending
        usort($predictions, fn($a, $b) => $b['predicted_points'] <=> $a['predicted_points']);

        return $predictions;
    }

    /**
     * Get prediction for a single player.
     *
     * @return array<string, mixed>|null
     */
    public function getPlayerPrediction(int $playerId, int $gameweek): ?array
    {
        $player = $this->db->fetchOne('SELECT * FROM players WHERE id = ?', [$playerId]);
        if ($player === null) {
            return null;
        }

        $fixtures = $this->getFixturesForGameweek($gameweek);
        $playerFixtures = $this->findPlayerFixtures((int) $player['club_id'], $fixtures);
        $fixtureOdds = $this->getFixtureOdds($gameweek);
        $goalscorerOdds = $this->getGoalscorerOdds($gameweek);
        $assistOdds = $this->getAssistOdds($gameweek);
        $teamGames = $this->getTeamGames();
        $playerTeamGames = $teamGames[(int) $player['club_id']] ?? 24;

        if (empty($playerFixtures)) {
            $prediction = $this->engine->predict($player, null, null, null, null, $playerTeamGames);
        } else {
            // Sum predictions across all fixtures (DGW support)
            $totalPoints = 0.0;
            $totalConfidence = 0.0;
            $combinedBreakdown = [];

            foreach ($playerFixtures as $fixture) {
                $odds = $fixtureOdds[$fixture['id']] ?? null;
                $key = $playerId . '_' . $fixture['id'];
                $playerGoalscorerOdds = $goalscorerOdds[$key] ?? null;
                $playerAssistOdds = $assistOdds[$key] ?? null;

                $fixturePrediction = $this->engine->predict(
                    $player,
                    $fixture,
                    $odds,
                    $playerGoalscorerOdds,
                    $playerAssistOdds,
                    $playerTeamGames
                );
                $totalPoints += $fixturePrediction['predicted_points'];
                $totalConfidence += $fixturePrediction['confidence'];

                foreach ($fixturePrediction['breakdown'] as $k => $value) {
                    $combinedBreakdown[$k] = ($combinedBreakdown[$k] ?? 0) + $value;
                }
            }

            $prediction = [
                'predicted_points' => round($totalPoints, 2),
                'confidence' => round($totalConfidence / count($playerFixtures), 2),
                'breakdown' => $combinedBreakdown,
            ];
        }

        return [
            'player_id' => $player['id'],
            'web_name' => $player['web_name'],
            'gameweek' => $gameweek,
            'predicted_points' => $prediction['predicted_points'],
            'confidence' => $prediction['confidence'],
            'breakdown' => $prediction['breakdown'],
            'fixture_count' => count($playerFixtures),
        ];
    }

    /**
     * Get cached predictions for a gameweek.
     * Only returns v2.0 predictions to invalidate old cache.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCachedPredictions(int $gameweek): array
    {
        $sql = "SELECT
                pp.player_id,
                p.web_name,
                p.club_id as team,
                p.position,
                p.now_cost,
                p.form,
                p.total_points,
                pp.predicted_points,
                pp.confidence
            FROM player_predictions pp
            JOIN players p ON pp.player_id = p.id
            WHERE pp.gameweek = ?
            AND pp.model_version = 'v2.0'
            AND pp.computed_at > datetime('now', '-6 hours')
            ORDER BY pp.predicted_points DESC";

        return $this->db->fetchAll($sql, [$gameweek]);
    }

    /**
     * Cache a prediction in the database.
     *
     * @param array<string, mixed> $prediction
     */
    private function cachePrediction(int $playerId, int $gameweek, array $prediction): void
    {
        $this->db->upsert('player_predictions', [
            'player_id' => $playerId,
            'gameweek' => $gameweek,
            'predicted_points' => $prediction['predicted_points'],
            'confidence' => $prediction['confidence'],
            'model_version' => 'v2.0',
            'computed_at' => date('Y-m-d H:i:s'),
        ], ['player_id', 'gameweek']);
    }

    /**
     * Get fixtures for a gameweek.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFixturesForGameweek(int $gameweek): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM fixtures WHERE gameweek = ?',
            [$gameweek]
        );
    }

    /**
     * Get fixture odds for a gameweek.
     *
     * @return array<int, array<string, mixed>> Keyed by fixture_id
     */
    private function getFixtureOdds(int $gameweek): array
    {
        $odds = $this->db->fetchAll(
            "SELECT fo.*
            FROM fixture_odds fo
            JOIN fixtures f ON fo.fixture_id = f.id
            WHERE f.gameweek = ?",
            [$gameweek]
        );

        $result = [];
        foreach ($odds as $row) {
            $result[$row['fixture_id']] = $row;
        }
        return $result;
    }

    /**
     * Get player goalscorer odds for a gameweek.
     *
     * @return array<string, array<string, mixed>> Keyed by "player_id_fixture_id"
     */
    private function getGoalscorerOdds(int $gameweek): array
    {
        $odds = $this->db->fetchAll(
            "SELECT pgo.*
            FROM player_goalscorer_odds pgo
            JOIN fixtures f ON pgo.fixture_id = f.id
            WHERE f.gameweek = ?",
            [$gameweek]
        );

        $result = [];
        foreach ($odds as $row) {
            $key = $row['player_id'] . '_' . $row['fixture_id'];
            $result[$key] = $row;
        }
        return $result;
    }

    /**
     * Get player assist odds for a gameweek.
     *
     * @return array<string, array<string, mixed>> Keyed by "player_id_fixture_id"
     */
    private function getAssistOdds(int $gameweek): array
    {
        $odds = $this->db->fetchAll(
            "SELECT pao.*
            FROM player_assist_odds pao
            JOIN fixtures f ON pao.fixture_id = f.id
            WHERE f.gameweek = ?",
            [$gameweek]
        );

        $result = [];
        foreach ($odds as $row) {
            $key = $row['player_id'] . '_' . $row['fixture_id'];
            $result[$key] = $row;
        }
        return $result;
    }

    /**
     * Calculate team games dynamically from finished fixtures.
     *
     * @return array<int, int> club_id => count of finished fixtures
     */
    private function getTeamGames(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT club_id, COUNT(*) as games FROM (
                SELECT home_club_id as club_id FROM fixtures WHERE finished = 1
                UNION ALL
                SELECT away_club_id as club_id FROM fixtures WHERE finished = 1
            ) GROUP BY club_id"
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['club_id']] = (int) $row['games'];
        }
        return $result;
    }

    /**
     * Find ALL fixtures for a player's club in a gameweek (handles DGWs).
     *
     * @param array<int, array<string, mixed>> $fixtures
     * @return array<int, array<string, mixed>>
     */
    private function findPlayerFixtures(int $clubId, array $fixtures): array
    {
        $playerFixtures = [];
        foreach ($fixtures as $fixture) {
            if ($fixture['home_club_id'] === $clubId || $fixture['away_club_id'] === $clubId) {
                $playerFixtures[] = $fixture;
            }
        }
        return $playerFixtures;
    }

    /**
     * Get methodology documentation for prediction model.
     *
     * @return array<string, mixed>
     */
    public static function getMethodology(): array
    {
        return [
            'version' => 'v2.0',
            'overview' => 'Odds-first prediction model: bookmaker odds are the primary signal, '
                . 'xG/xA are trusted secondary signals regressed to career baselines, '
                . 'and arbitrary multipliers have been replaced with proper statistical derivations.',
            'scoring_rules' => [
                'goals' => 'GK/DEF: 6pts, MID: 5pts, FWD: 4pts',
                'assists' => '3pts all positions',
                'clean_sheets' => 'GK/DEF: 4pts, MID: 1pt, FWD: 0pts',
                'appearance' => '2pts for 60+ mins, 1pt for <60 mins',
                'bonus' => '1-3pts based on BPS ranking',
                'saves' => 'GK only: 1pt per 3 saves',
                'goals_conceded' => 'GK/DEF only: -1pt per 2 goals conceded',
                'cards' => 'Yellow: -1pt, Red: -3pts, Own goal: -2pts, Pen miss: -2pts',
                'defensive_contribution' => 'DEF: 2pts for 10+ DC, MID/FWD: 2pts for 12+ DC',
            ],
            'probability_models' => [
                'minutes' => [
                    'description' => 'Probability of playing based on appearance rate and start rate',
                    'factors' => ['appearances/team_games ratio', 'average minutes per appearance', 'start rate'],
                    'note' => 'Team games calculated dynamically from finished fixtures. '
                        . 'Only uses chance_of_playing when it equals 0 (confirmed out)',
                ],
                'goals' => [
                    'description' => 'Odds-first goal prediction with career regression',
                    'priority' => [
                        '1. Scorer odds → inverse Poisson (90% odds / 10% regressed xG)',
                        '2. Season xG/90 regressed to career baseline, fixture-adjusted from match odds',
                        '3. Historical goals per 90 (no position weight suppression)',
                    ],
                ],
                'assists' => [
                    'description' => 'Odds-first assist prediction with career regression',
                    'priority' => [
                        '1. Assist odds → inverse Poisson (90% odds / 10% regressed xA)',
                        '2. Season xA/90 regressed to career baseline, fixture-adjusted from match odds',
                        '3. Historical assists per 90',
                    ],
                ],
                'clean_sheets' => [
                    'description' => 'Clean sheet probability from odds or derived from match odds',
                    'priority' => [
                        '1. Direct CS odds (80% odds / 20% xGC-based), no separate home boost',
                        '2. Derive from match odds: opponent xG via Poisson P(0 goals)',
                        '3. Historical CS rate from actuals',
                    ],
                ],
                'bonus' => [
                    'description' => 'Sigmoid on BPS per 90, boosted by expected goals/assists',
                    'factors' => ['BPS per 90', 'expected goals (+24 BPS)', 'expected assists (+15 BPS)', 'historical bonus rate'],
                    'blend' => '60% BPS-based, 40% historical',
                ],
                'cards' => [
                    'description' => 'Per-90 rates for yellow cards, red cards, own goals, penalty misses',
                    'factors' => ['yellow_cards/90', 'red_cards/90', 'own_goals/90', 'penalties_missed/90'],
                ],
                'defensive_contribution' => [
                    'description' => 'Poisson CDF probability of reaching DC threshold',
                    'factors' => ['DC per 90', 'conditional minutes (not probability-weighted)'],
                ],
            ],
            'fixture_adjustments' => [
                'odds_based' => 'Derives team expected goals from total goals and home/away share',
                'formula' => 'homeShare = homeWinProb + 0.5 * drawProb; teamXG = totalGoals * share; '
                    . 'multiplier = teamXG / leagueAvgTeamXG',
                'no_double_counting' => 'When odds are present, no separate home advantage applied',
            ],
            'regression_to_mean' => [
                'method' => 'Career xG/90 and xA/90 from player_season_history',
                'weight' => 'Linear ramp: min(1.0, currentMinutes / 1800)',
                'blend' => 'effectiveRate = (current * w) + (historical * (1 - w))',
            ],
            'confidence_calculation' => [
                'base' => 0.5,
                'minutes_bonus' => '+0.15 for 450+ mins, +0.10 for 270+ mins',
                'xg_data_bonus' => '+0.10 when xG data available',
                'odds_data_bonus' => '+0.10 for fixture odds, +0.05 for goalscorer odds, +0.05 for assist odds',
                'maximum' => 0.95,
            ],
            'bug_fixes_in_v2' => [
                'GC penalty only applies to GK/DEF (was incorrectly applied to MID)',
                'DC uses conditional minutes (minsIfPlaying * prob_60), not probability-weighted expected_mins',
                'Team games calculated dynamically, not hardcoded',
                'chance_of_playing handles string "0" correctly',
                'No home advantage double-counting when odds present',
            ],
        ];
    }
}
