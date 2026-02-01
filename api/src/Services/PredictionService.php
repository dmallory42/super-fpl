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

        $predictions = [];

        foreach ($players as $player) {
            $clubId = (int) $player['club_id'];

            // Find player's fixture
            $fixture = $this->findPlayerFixture($clubId, $fixtures);
            $odds = $fixture ? ($fixtureOdds[$fixture['id']] ?? null) : null;

            // Generate prediction
            $prediction = $this->engine->predict($player, $fixture, $odds);

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

        $prediction = $this->engine->predict($player, $fixture, $odds);

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
}
