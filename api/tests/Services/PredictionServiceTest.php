<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Tests\Support\TestDatabase;
use SuperFPL\Api\Services\PredictionService;

class PredictionServiceTest extends TestCase
{
    private TestDatabase $db;
    private PredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new TestDatabase(':memory:');
        $this->db->init();
        $this->service = new PredictionService($this->db);
    }

    public function testGetPredictionsLoadsCachedRowsForRequestedGameweek(): void
    {
        $this->insertClub(1, 'Arsenal', 'ARS');
        $this->insertClub(2, 'Chelsea', 'CHE');
        $this->insertPlayer(10, 'Saka', 1, 3);
        $this->insertFixture(100, 25, 1, 2);
        $this->insertPrediction(10, 25, 6.8, 0.82);

        $predictions = $this->service->getPredictions(25);

        $this->assertCount(1, $predictions);
        $this->assertSame(10, (int) $predictions[0]['player_id']);
        $this->assertSame(6.8, (float) $predictions[0]['predicted_points']);
        $this->assertSame('Saka', (string) $predictions[0]['web_name']);
    }

    public function testGetPredictionsReturnsZeroForBlankGameweekTeams(): void
    {
        $this->insertClub(1, 'Arsenal', 'ARS');
        $this->insertPlayer(11, 'Odegaard', 1, 3);
        $this->insertPrediction(11, 26, 7.4, 0.79);

        $predictions = $this->service->getPredictions(26);

        $this->assertCount(1, $predictions);
        $this->assertSame(0.0, (float) $predictions[0]['predicted_points']);
        $this->assertSame(0.0, (float) $predictions[0]['expected_mins']);
    }

    public function testGetAccuracyBuildsSummaryBucketsAndPlayerRows(): void
    {
        $this->insertClub(1, 'Arsenal', 'ARS');
        $this->insertClub(2, 'Chelsea', 'CHE');
        $this->insertPlayer(21, 'PlayerA', 1, 3);
        $this->insertPlayer(22, 'PlayerB', 1, 4);
        $this->insertFixture(1, 24, 1, 2);

        $this->insertSnapshot(21, 24, 6.0);
        $this->insertSnapshot(22, 24, 2.0);

        $this->insertHistory(21, 24, 4); // delta +2
        $this->insertHistory(22, 24, 3); // delta -1

        $accuracy = $this->service->getAccuracy(24);

        $this->assertSame(2, (int) ($accuracy['summary']['count'] ?? 0));
        $this->assertSame(1.5, (float) ($accuracy['summary']['mae'] ?? 0.0));
        $this->assertSame(0.5, (float) ($accuracy['summary']['bias'] ?? 0.0));
        $this->assertNotEmpty($accuracy['buckets']);
        $this->assertCount(2, $accuracy['players']);
    }

    public function testSnapshotAndSnapshotLoadingWorkForExistingAndEmptyGameweeks(): void
    {
        $this->insertClub(1, 'Arsenal', 'ARS');
        $this->insertClub(2, 'Chelsea', 'CHE');
        $this->insertPlayer(31, 'Martinelli', 1, 3);
        $this->insertFixture(300, 27, 1, 2);
        $this->insertPrediction(31, 27, 5.9, 0.75);

        $inserted = $this->service->snapshotPredictions(27);
        $snapshotRows = $this->service->getSnapshotPredictions(27);

        $this->assertSame(1, $inserted);
        $this->assertCount(1, $snapshotRows);
        $this->assertSame(31, (int) $snapshotRows[0]['player_id']);
        $this->assertSame([], $this->service->getSnapshotPredictions(99));
    }

    public function testMissingGameweekReturnsSafeEmptyResponses(): void
    {
        $predictions = $this->service->getPredictions(99);
        $accuracy = $this->service->getAccuracy(99);

        $this->assertSame([], $predictions);
        $this->assertSame(0, (int) ($accuracy['summary']['count'] ?? -1));
        $this->assertSame([], $accuracy['players'] ?? null);
    }

    private function insertClub(int $id, string $name, string $shortName): void
    {
        $this->db->query(
            'INSERT INTO clubs (id, name, short_name) VALUES (?, ?, ?)',
            [$id, $name, $shortName]
        );
    }

    private function insertPlayer(int $id, string $name, int $clubId, int $position): void
    {
        $this->db->query(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists,
                expected_goals_conceded, ict_index, bps, bonus, starts, chance_of_playing, news, updated_at
            ) VALUES (
                ?, ?, ?, '', '', ?, ?, 80, 0, 0.0, 0.0, 0, 0, 0, 0, 0.0, 0.0, 0.0, 0.0, 0, 0, 0, 100, '', datetime('now')
            )",
            [$id, $id, $name, $clubId, $position]
        );
    }

    private function insertFixture(int $id, int $gameweek, int $homeClubId, int $awayClubId): void
    {
        $this->db->query(
            'INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, home_score, away_score, home_difficulty, away_difficulty, finished)
             VALUES (?, ?, ?, ?, datetime("now", "+1 day"), NULL, NULL, 3, 3, 0)',
            [$id, $gameweek, $homeClubId, $awayClubId]
        );
    }

    private function insertPrediction(int $playerId, int $gameweek, float $points, float $confidence): void
    {
        $this->db->query(
            "INSERT INTO player_predictions (
                player_id, gameweek, predicted_points, predicted_if_fit, expected_mins, expected_mins_if_fit,
                breakdown_json, if_fit_breakdown_json, confidence, model_version, computed_at
            ) VALUES (?, ?, ?, ?, 90, 90, '{}', '{}', ?, 'v2.0', datetime('now'))",
            [$playerId, $gameweek, $points, $points, $confidence]
        );
    }

    private function insertSnapshot(int $playerId, int $gameweek, float $points): void
    {
        $this->db->query(
            "INSERT INTO prediction_snapshots (
                player_id, gameweek, predicted_points, confidence, breakdown, model_version, snapshot_source, is_pre_deadline, snapped_at
            ) VALUES (?, ?, ?, 0.8, '{}', 'v2.0', 'test', 1, datetime('now'))",
            [$playerId, $gameweek, $points]
        );
    }

    private function insertHistory(int $playerId, int $gameweek, int $actualPoints): void
    {
        $this->db->query(
            'INSERT INTO player_gameweek_history (
                player_id, gameweek, fixture_id, opponent_team, was_home, minutes, goals_scored, assists, clean_sheets,
                goals_conceded, bonus, bps, total_points, expected_goals, expected_assists, expected_goals_conceded, value, selected
            ) VALUES (?, ?, 1, 2, 1, 90, 0, 0, 0, 0, 0, 0, ?, 0, 0, 0, 80, 100)',
            [$playerId, $gameweek, $actualPoints]
        );
    }
}
