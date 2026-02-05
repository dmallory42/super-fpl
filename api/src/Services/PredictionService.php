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
        $this->engine = new PredictionEngine();
    }

    /**
     * Get predictions for all players for a specific gameweek.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPredictions(int $gameweek): array
    {
        // First check if we have cached predictions
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

        $predictions = [];

        foreach ($players as $player) {
            $clubId = (int) $player['club_id'];
            $playerId = (int) $player['id'];

            // Find player's fixture
            $fixture = $this->findPlayerFixture($clubId, $fixtures);
            $odds = $fixture ? ($fixtureOdds[$fixture['id']] ?? null) : null;

            // Get player-specific goalscorer odds
            $playerGoalscorerOdds = null;
            if ($fixture) {
                $key = $playerId . '_' . $fixture['id'];
                $playerGoalscorerOdds = $goalscorerOdds[$key] ?? null;
            }

            // Generate prediction
            $prediction = $this->engine->predict($player, $fixture, $odds, $playerGoalscorerOdds);

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
                'fixture' => $fixture ? [
                    'opponent' => $fixture['home_club_id'] === $clubId
                        ? $fixture['away_club_id']
                        : $fixture['home_club_id'],
                    'is_home' => $fixture['home_club_id'] === $clubId,
                    'difficulty' => $fixture['home_club_id'] === $clubId
                        ? $fixture['home_difficulty']
                        : $fixture['away_difficulty'],
                ] : null,
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
        $fixture = $this->findPlayerFixture((int) $player['club_id'], $fixtures);
        $odds = $fixture ? $this->getFixtureOdds($gameweek)[$fixture['id']] ?? null : null;

        // Get player-specific goalscorer odds
        $playerGoalscorerOdds = null;
        if ($fixture) {
            $goalscorerOdds = $this->getGoalscorerOdds($gameweek);
            $key = $playerId . '_' . $fixture['id'];
            $playerGoalscorerOdds = $goalscorerOdds[$key] ?? null;
        }

        $prediction = $this->engine->predict($player, $fixture, $odds, $playerGoalscorerOdds);

        return [
            'player_id' => $player['id'],
            'web_name' => $player['web_name'],
            'gameweek' => $gameweek,
            'predicted_points' => $prediction['predicted_points'],
            'confidence' => $prediction['confidence'],
            'breakdown' => $prediction['breakdown'],
        ];
    }

    /**
     * Get cached predictions for a gameweek.
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
            'model_version' => 'v1.0',
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
     * Find a player's fixture from the list.
     *
     * @param array<int, array<string, mixed>> $fixtures
     * @return array<string, mixed>|null
     */
    private function findPlayerFixture(int $clubId, array $fixtures): ?array
    {
        foreach ($fixtures as $fixture) {
            if ($fixture['home_club_id'] === $clubId || $fixture['away_club_id'] === $clubId) {
                return $fixture;
            }
        }
        return null;
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
            'overview' => 'Points predicted using probability models for each FPL scoring action',
            'scoring_rules' => [
                'goals' => 'GK/DEF: 6pts, MID: 5pts, FWD: 4pts',
                'assists' => '3pts all positions',
                'clean_sheets' => 'GK/DEF: 4pts, MID: 1pt, FWD: 0pts',
                'appearance' => '2pts for 60+ mins, 1pt for <60 mins',
                'bonus' => '1-3pts based on BPS ranking',
                'saves' => 'GK only: 1pt per 3 saves',
                'goals_conceded' => 'GK/DEF: -1pt per 2 goals conceded',
                'defensive_contribution' => 'DEF: 2pts for 10+ DC, MID/FWD: 2pts for 12+ DC',
            ],
            'probability_models' => [
                'minutes' => [
                    'description' => 'Probability of playing based on historical start rate',
                    'factors' => ['starts/team_games ratio', 'average minutes when playing'],
                    'note' => 'Only uses chance_of_playing when it equals 0 (confirmed out)',
                ],
                'goals' => [
                    'description' => 'Goal probability using Poisson distribution on xG per 90',
                    'factors' => ['expected_goals', 'minutes played', 'bookmaker win probability', 'goalscorer odds when available'],
                ],
                'assists' => [
                    'description' => 'Assist probability using Poisson distribution on xA per 90',
                    'factors' => ['expected_assists', 'historical assist rate', 'bookmaker win probability'],
                ],
                'clean_sheets' => [
                    'description' => 'Clean sheet probability blending bookmaker odds with xGC-based estimate',
                    'factors' => ['team xGC', 'bookmaker CS probability (60% weight)', 'xGC-based estimate (40% weight)'],
                ],
                'bonus' => [
                    'description' => 'Expected bonus using sigmoid on BPS per 90',
                    'factors' => ['bps', 'minutes', 'historical bonus rate'],
                ],
                'defensive_contribution' => [
                    'description' => 'Probability of earning DC bonus using Poisson distribution',
                    'factors' => ['defensive_contribution_per_90', 'expected_minutes', 'position-based threshold'],
                ],
            ],
            'fixture_adjustments' => [
                'odds_based' => 'Uses bookmaker win probability to derive attack/defense multipliers',
                'win_prob_mapping' => 'Win prob ~0.15-0.65 maps to multiplier 0.75-1.25 for attacks',
                'home_advantage' => '+10% goals/assists, +15% clean sheet probability',
                'fallback' => 'When no odds available, uses neutral multiplier (1.0)',
            ],
            'confidence_calculation' => [
                'base' => 0.5,
                'minutes_bonus' => '+0.15 for 450+ mins, +0.10 for 270+ mins',
                'xg_data_bonus' => '+0.10 when xG data available',
                'odds_data_bonus' => '+0.10 for fixture odds, +0.10 for goalscorer odds',
                'maximum' => 0.95,
            ],
            'limitations' => [
                'Does not account for tactical changes or rotation',
                'Availability modifier fixed at 100% (future: injury modeling)',
                'DGW/BGW handling is separate from base predictions',
            ],
        ];
    }
}
