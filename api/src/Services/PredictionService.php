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

        // Pre-compute penalty taker chains for all teams
        $this->engine->precomputePenaltyTakers($players, $teamGames);

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
                $totalIfFit = 0.0;
                $perGameMinsIfFit = 0.0;
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
                    $totalIfFit += $fixturePrediction['predicted_if_fit'];
                    // expected_mins_if_fit is per-player (same for all fixtures), not summed
                    $perGameMinsIfFit = $fixturePrediction['expected_mins_if_fit'];
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
                    'predicted_if_fit' => round($totalIfFit, 2),
                    'expected_mins_if_fit' => round($perGameMinsIfFit, 1),
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
                'availability' => $this->deriveAvailability($player),
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

        // Pre-compute penalty takers for the player's team
        $teamPlayers = $this->db->fetchAll(
            'SELECT * FROM players WHERE club_id = ? AND penalty_order IS NOT NULL ORDER BY penalty_order',
            [(int) $player['club_id']]
        );
        $this->engine->precomputePenaltyTakers($teamPlayers, $teamGames);

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
                pp.confidence,
                p.chance_of_playing,
                p.news
            FROM player_predictions pp
            JOIN players p ON pp.player_id = p.id
            WHERE pp.gameweek = ?
            AND pp.model_version = 'v2.0'
            AND pp.computed_at > datetime('now', '-6 hours')
            ORDER BY pp.predicted_points DESC";

        $results = $this->db->fetchAll($sql, [$gameweek]);

        // Add availability to each result
        foreach ($results as &$row) {
            $row['availability'] = $this->deriveAvailability($row);
            unset($row['chance_of_playing'], $row['news']);
        }

        return $results;
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
            'predicted_if_fit' => $prediction['predicted_if_fit'] ?? null,
            'expected_mins_if_fit' => $prediction['expected_mins_if_fit'] ?? null,
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
     * Snapshot current predictions for a gameweek (for historical accuracy tracking).
     * Uses INSERT OR IGNORE so re-running is idempotent.
     *
     * @return int Number of rows inserted
     */
    public function snapshotPredictions(int $gameweek): int
    {
        $before = (int) $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM prediction_snapshots WHERE gameweek = ?",
            [$gameweek]
        )['cnt'];

        $this->db->query(
            "INSERT OR IGNORE INTO prediction_snapshots (player_id, gameweek, predicted_points, confidence, breakdown, model_version, snapped_at)
            SELECT player_id, gameweek, predicted_points, confidence, '{}', model_version, datetime('now')
            FROM player_predictions
            WHERE gameweek = ? AND model_version = 'v2.0'",
            [$gameweek]
        );

        $after = (int) $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM prediction_snapshots WHERE gameweek = ?",
            [$gameweek]
        )['cnt'];

        return $after - $before;
    }

    /**
     * Get snapshot predictions for a past gameweek.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSnapshotPredictions(int $gameweek): array
    {
        return $this->db->fetchAll(
            "SELECT
                ps.player_id,
                p.web_name,
                p.club_id as team,
                p.position,
                p.now_cost,
                p.form,
                p.total_points,
                ps.predicted_points,
                ps.confidence,
                ps.breakdown
            FROM prediction_snapshots ps
            JOIN players p ON ps.player_id = p.id
            WHERE ps.gameweek = ?
            ORDER BY ps.predicted_points DESC",
            [$gameweek]
        );
    }

    /**
     * Compute accuracy stats for a gameweek by comparing snapshots to actual results.
     *
     * @return array{summary: array, buckets: array, players: array}
     */
    public function getAccuracy(int $gameweek): array
    {
        $rows = $this->db->fetchAll(
            "SELECT
                ps.player_id,
                p.web_name,
                ps.predicted_points,
                pgh.total_points as actual_points
            FROM prediction_snapshots ps
            JOIN players p ON ps.player_id = p.id
            JOIN player_gameweek_history pgh ON ps.player_id = pgh.player_id AND ps.gameweek = pgh.gameweek
            WHERE ps.gameweek = ?",
            [$gameweek]
        );

        if (empty($rows)) {
            return [
                'summary' => ['mae' => 0, 'bias' => 0, 'count' => 0],
                'buckets' => [],
                'players' => [],
            ];
        }

        $totalAbsError = 0.0;
        $totalBias = 0.0;
        $bucketData = [];
        $players = [];

        // Define buckets
        $bucketRanges = [
            ['range' => '0-2', 'min' => 0, 'max' => 2],
            ['range' => '2-5', 'min' => 2, 'max' => 5],
            ['range' => '5-8', 'min' => 5, 'max' => 8],
            ['range' => '8+', 'min' => 8, 'max' => PHP_FLOAT_MAX],
        ];

        foreach ($bucketRanges as $range) {
            $bucketData[$range['range']] = ['errors' => [], 'biases' => []];
        }

        foreach ($rows as $row) {
            $predicted = (float) $row['predicted_points'];
            $actual = (float) $row['actual_points'];
            $delta = $predicted - $actual;

            $totalAbsError += abs($delta);
            $totalBias += $delta;

            $players[] = [
                'player_id' => (int) $row['player_id'],
                'web_name' => $row['web_name'],
                'predicted' => $predicted,
                'actual' => $actual,
                'delta' => round($delta, 2),
            ];

            // Assign to bucket
            foreach ($bucketRanges as $range) {
                if ($predicted >= $range['min'] && $predicted < $range['max']) {
                    $bucketData[$range['range']]['errors'][] = abs($delta);
                    $bucketData[$range['range']]['biases'][] = $delta;
                    break;
                }
            }
        }

        $count = count($rows);

        $buckets = [];
        foreach ($bucketRanges as $range) {
            $bErrors = $bucketData[$range['range']]['errors'];
            $bBiases = $bucketData[$range['range']]['biases'];
            if (!empty($bErrors)) {
                $buckets[] = [
                    'range' => $range['range'],
                    'mae' => round(array_sum($bErrors) / count($bErrors), 2),
                    'bias' => round(array_sum($bBiases) / count($bBiases), 2),
                    'count' => count($bErrors),
                ];
            }
        }

        return [
            'summary' => [
                'mae' => round($totalAbsError / $count, 2),
                'bias' => round($totalBias / $count, 2),
                'count' => $count,
            ],
            'buckets' => $buckets,
            'players' => $players,
        ];
    }

    /**
     * Compute calibration curve from historical snapshots vs actuals.
     * Returns bucket-level adjustments for future calibration tuning.
     *
     * @param array<int> $gameweeks Gameweeks to include
     * @return array<int, array{range: string, avg_predicted: float, avg_actual: float, adjustment: float, count: int}>
     */
    public function computeCalibrationCurve(array $gameweeks): array
    {
        if (empty($gameweeks)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($gameweeks), '?'));
        $rows = $this->db->fetchAll(
            "SELECT ps.predicted_points, pgh.total_points as actual_points
            FROM prediction_snapshots ps
            JOIN player_gameweek_history pgh ON ps.player_id = pgh.player_id AND ps.gameweek = pgh.gameweek
            WHERE ps.gameweek IN ($placeholders)",
            $gameweeks
        );

        $bucketRanges = [
            ['range' => '0-1', 'min' => 0, 'max' => 1],
            ['range' => '1-2', 'min' => 1, 'max' => 2],
            ['range' => '2-3', 'min' => 2, 'max' => 3],
            ['range' => '3-4', 'min' => 3, 'max' => 4],
            ['range' => '4-5', 'min' => 4, 'max' => 5],
            ['range' => '5-6', 'min' => 5, 'max' => 6],
            ['range' => '6-8', 'min' => 6, 'max' => 8],
            ['range' => '8+', 'min' => 8, 'max' => PHP_FLOAT_MAX],
        ];

        $bucketData = [];
        foreach ($bucketRanges as $range) {
            $bucketData[$range['range']] = ['predicted' => [], 'actual' => []];
        }

        foreach ($rows as $row) {
            $predicted = (float) $row['predicted_points'];
            $actual = (float) $row['actual_points'];

            foreach ($bucketRanges as $range) {
                if ($predicted >= $range['min'] && $predicted < $range['max']) {
                    $bucketData[$range['range']]['predicted'][] = $predicted;
                    $bucketData[$range['range']]['actual'][] = $actual;
                    break;
                }
            }
        }

        $curve = [];
        foreach ($bucketRanges as $range) {
            $preds = $bucketData[$range['range']]['predicted'];
            $actuals = $bucketData[$range['range']]['actual'];
            if (!empty($preds)) {
                $avgPredicted = array_sum($preds) / count($preds);
                $avgActual = array_sum($actuals) / count($actuals);
                $curve[] = [
                    'range' => $range['range'],
                    'avg_predicted' => round($avgPredicted, 2),
                    'avg_actual' => round($avgActual, 2),
                    'adjustment' => round($avgActual - $avgPredicted, 2),
                    'count' => count($preds),
                ];
            }
        }

        return $curve;
    }

    /**
     * Derive player availability status from FPL data.
     */
    private function deriveAvailability(array $player): string
    {
        $cop = $player['chance_of_playing'] ?? null;
        $news = $player['news'] ?? '';

        if ($cop === null && empty($news)) {
            return 'available';
        }
        if ((int) $cop === 0 && $cop !== null) {
            if (stripos($news, 'suspend') !== false) {
                return 'suspended';
            }
            return 'unavailable';
        }
        if ($cop !== null && (int) $cop <= 25) {
            return 'injured';
        }
        if ($cop !== null && (int) $cop <= 75) {
            return 'doubtful';
        }
        return 'available';
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
