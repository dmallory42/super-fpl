<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\PredictionService;

class PredictionSnapshotTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $pdo = $this->db->getPdo();

        $pdo->exec("
            CREATE TABLE clubs (
                id INTEGER PRIMARY KEY,
                name TEXT,
                short_name TEXT
            )
        ");

        $pdo->exec("
            CREATE TABLE players (
                id INTEGER PRIMARY KEY,
                code INTEGER,
                web_name TEXT,
                first_name TEXT,
                second_name TEXT,
                club_id INTEGER,
                position INTEGER,
                now_cost INTEGER,
                total_points INTEGER,
                form REAL,
                minutes INTEGER,
                goals_scored INTEGER,
                assists INTEGER,
                clean_sheets INTEGER,
                expected_goals REAL,
                expected_assists REAL,
                expected_goals_conceded REAL,
                bps INTEGER,
                bonus INTEGER,
                starts INTEGER,
                appearances INTEGER DEFAULT 0,
                chance_of_playing INTEGER,
                news TEXT,
                defensive_contribution_per_90 REAL DEFAULT 0,
                saves INTEGER DEFAULT 0,
                yellow_cards INTEGER DEFAULT 0,
                red_cards INTEGER DEFAULT 0,
                own_goals INTEGER DEFAULT 0,
                penalties_missed INTEGER DEFAULT 0,
                penalties_saved INTEGER DEFAULT 0,
                goals_conceded INTEGER DEFAULT 0,
                selected_by_percent REAL DEFAULT 0,
                ict_index REAL DEFAULT 0,
                penalties_order INTEGER DEFAULT 0,
                penalties_taken INTEGER DEFAULT 0,
                defensive_contribution INTEGER DEFAULT 0,
                xmins_override INTEGER DEFAULT NULL,
                understat_id INTEGER,
                updated_at TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE fixtures (
                id INTEGER PRIMARY KEY,
                gameweek INTEGER,
                home_club_id INTEGER,
                away_club_id INTEGER,
                kickoff_time TIMESTAMP,
                home_score INTEGER,
                away_score INTEGER,
                home_difficulty INTEGER,
                away_difficulty INTEGER,
                finished BOOLEAN
            )
        ");

        $pdo->exec("
            CREATE TABLE player_predictions (
                player_id INTEGER,
                gameweek INTEGER,
                predicted_points REAL,
                confidence REAL,
                model_version TEXT,
                computed_at TIMESTAMP,
                PRIMARY KEY (player_id, gameweek)
            )
        ");

        $pdo->exec("
            CREATE TABLE prediction_snapshots (
                player_id INTEGER,
                gameweek INTEGER,
                predicted_points REAL,
                confidence REAL,
                breakdown TEXT,
                model_version TEXT,
                snapped_at TIMESTAMP,
                PRIMARY KEY (player_id, gameweek)
            )
        ");

        $pdo->exec("
            CREATE TABLE player_gameweek_history (
                player_id INTEGER,
                gameweek INTEGER,
                fixture_id INTEGER,
                opponent_team INTEGER,
                was_home BOOLEAN,
                minutes INTEGER,
                goals_scored INTEGER,
                assists INTEGER,
                clean_sheets INTEGER,
                goals_conceded INTEGER,
                bonus INTEGER,
                bps INTEGER,
                total_points INTEGER,
                expected_goals REAL,
                expected_assists REAL,
                expected_goals_conceded REAL,
                value INTEGER,
                selected INTEGER,
                PRIMARY KEY (player_id, gameweek)
            )
        ");

        $pdo->exec("
            CREATE TABLE fixture_odds (
                fixture_id INTEGER PRIMARY KEY,
                home_win_prob REAL,
                draw_prob REAL,
                away_win_prob REAL,
                home_cs_prob REAL,
                away_cs_prob REAL,
                expected_total_goals REAL,
                updated_at TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE player_goalscorer_odds (
                player_id INTEGER,
                fixture_id INTEGER,
                anytime_scorer_prob REAL,
                updated_at TIMESTAMP,
                PRIMARY KEY (player_id, fixture_id)
            )
        ");

        $pdo->exec("
            CREATE TABLE player_assist_odds (
                player_id INTEGER,
                fixture_id INTEGER,
                anytime_assist_prob REAL,
                updated_at TIMESTAMP,
                PRIMARY KEY (player_id, fixture_id)
            )
        ");

        $pdo->exec("
            CREATE TABLE player_season_history (
                player_code INTEGER,
                season_id TEXT,
                total_points INTEGER,
                minutes INTEGER,
                goals_scored INTEGER,
                assists INTEGER,
                clean_sheets INTEGER,
                expected_goals REAL,
                expected_assists REAL,
                expected_goals_conceded REAL,
                starts INTEGER,
                start_cost INTEGER,
                end_cost INTEGER,
                PRIMARY KEY (player_code, season_id)
            )
        ");

        $pdo->exec("
            CREATE TABLE understat_season_history (
                understat_id INTEGER,
                season INTEGER,
                minutes INTEGER,
                npxg REAL,
                xa REAL,
                goals INTEGER,
                assists INTEGER,
                shots INTEGER,
                key_passes INTEGER,
                PRIMARY KEY (understat_id, season)
            )
        ");

        $pdo->exec("
            CREATE TABLE understat_team_season (
                team_name TEXT,
                club_id INTEGER,
                season INTEGER,
                games INTEGER,
                xgf REAL,
                xga REAL,
                npxgf REAL,
                npxga REAL,
                scored INTEGER,
                missed INTEGER,
                PRIMARY KEY (team_name, season)
            )
        ");
    }

    private function insertPlayer(int $id, string $name, int $position = 3, ?int $chanceOfPlaying = null, string $news = ''): void
    {
        $this->db->query(
            "INSERT INTO players (id, code, web_name, club_id, position, now_cost, total_points, form, minutes,
                goals_scored, assists, clean_sheets, expected_goals, expected_assists, bps, bonus, starts, appearances,
                chance_of_playing, news)
            VALUES (?, ?, ?, 1, ?, 80, 100, 5.0, 1800, 5, 3, 5, 4.0, 2.0, 400, 6, 20, 20, ?, ?)",
            [$id, $id, $name, $position, $chanceOfPlaying, $news]
        );
    }

    private function insertPrediction(int $playerId, int $gameweek, float $points, float $confidence = 0.7): void
    {
        $this->db->query(
            "INSERT INTO player_predictions (player_id, gameweek, predicted_points, confidence, model_version, computed_at)
            VALUES (?, ?, ?, ?, 'v2.0', datetime('now'))",
            [$playerId, $gameweek, $points, $confidence]
        );
    }

    private function insertSnapshot(int $playerId, int $gameweek, float $points, float $confidence = 0.7): void
    {
        $this->db->query(
            "INSERT INTO prediction_snapshots (player_id, gameweek, predicted_points, confidence, breakdown, model_version, snapped_at)
            VALUES (?, ?, ?, ?, '{}', 'v2.0', datetime('now'))",
            [$playerId, $gameweek, $points, $confidence]
        );
    }

    private function insertHistory(int $playerId, int $gameweek, int $actualPoints): void
    {
        $this->db->query(
            "INSERT INTO player_gameweek_history (player_id, gameweek, fixture_id, opponent_team, was_home, minutes,
                goals_scored, assists, clean_sheets, goals_conceded, bonus, bps, total_points,
                expected_goals, expected_assists, expected_goals_conceded, value, selected)
            VALUES (?, ?, 1, 2, 1, 90, 0, 0, 0, 0, 0, 0, ?, 0, 0, 0, 80, 10)",
            [$playerId, $gameweek, $actualPoints]
        );
    }

    // --- Snapshot tests ---

    public function testSnapshotCopiesPredictions(): void
    {
        $this->insertPlayer(1, 'Salah');
        $this->insertPlayer(2, 'Haaland');
        $this->insertPrediction(1, 25, 6.5, 0.8);
        $this->insertPrediction(2, 25, 7.2, 0.9);

        $service = new PredictionService($this->db);
        $count = $service->snapshotPredictions(25);

        $this->assertEquals(2, $count);

        // Verify data in snapshot table
        $snapshots = $this->db->fetchAll(
            "SELECT * FROM prediction_snapshots WHERE gameweek = 25 ORDER BY player_id"
        );
        $this->assertCount(2, $snapshots);
        $this->assertEquals(6.5, (float) $snapshots[0]['predicted_points']);
        $this->assertEquals(7.2, (float) $snapshots[1]['predicted_points']);
    }

    public function testSnapshotIsIdempotent(): void
    {
        $this->insertPlayer(1, 'Salah');
        $this->insertPrediction(1, 25, 6.5);

        $service = new PredictionService($this->db);
        $count1 = $service->snapshotPredictions(25);
        $count2 = $service->snapshotPredictions(25);

        // Second call should still succeed (INSERT OR IGNORE)
        $this->assertEquals(1, $count1);
        // Second call inserts 0 new rows
        $this->assertEquals(0, $count2);

        // Still only 1 row
        $snapshots = $this->db->fetchAll("SELECT * FROM prediction_snapshots WHERE gameweek = 25");
        $this->assertCount(1, $snapshots);
    }

    public function testGetSnapshotReturnsData(): void
    {
        $this->insertPlayer(1, 'Salah', 3);
        $this->insertPlayer(2, 'Haaland', 4);
        $this->insertSnapshot(1, 24, 5.5, 0.8);
        $this->insertSnapshot(2, 24, 6.0, 0.85);

        $service = new PredictionService($this->db);
        $results = $service->getSnapshotPredictions(24);

        $this->assertCount(2, $results);
        // Sorted by predicted_points DESC
        $this->assertEquals('Haaland', $results[0]['web_name']);
        $this->assertEquals('Salah', $results[1]['web_name']);
        $this->assertEquals(6.0, (float) $results[0]['predicted_points']);
    }

    public function testGetSnapshotReturnsEmptyForMissingGw(): void
    {
        $service = new PredictionService($this->db);
        $results = $service->getSnapshotPredictions(99);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testCachedPredictionsAreZeroedForBlankGameweek(): void
    {
        $this->insertPlayer(1, 'Salah', 3);
        $this->insertPrediction(1, 31, 8.5, 0.9);
        // No fixtures inserted for GW31 -> blank for all teams in this test DB.

        $service = new PredictionService($this->db);
        $results = $service->getPredictions(31);

        $this->assertCount(1, $results);
        $this->assertEquals(0.0, (float) $results[0]['predicted_points']);
    }

    public function testAccuracyComputesCorrectly(): void
    {
        $this->insertPlayer(1, 'Salah');
        $this->insertPlayer(2, 'Haaland');

        // Snapshot predictions
        $this->insertSnapshot(1, 24, 5.0);
        $this->insertSnapshot(2, 24, 7.0);

        // Actual results
        $this->insertHistory(1, 24, 3);  // delta = -2
        $this->insertHistory(2, 24, 10); // delta = +3

        $service = new PredictionService($this->db);
        $accuracy = $service->getAccuracy(24);

        $this->assertArrayHasKey('summary', $accuracy);
        $this->assertArrayHasKey('mae', $accuracy['summary']);
        $this->assertArrayHasKey('bias', $accuracy['summary']);
        $this->assertArrayHasKey('count', $accuracy['summary']);

        // MAE = (|5-3| + |7-10|) / 2 = (2 + 3) / 2 = 2.5
        $this->assertEquals(2.5, $accuracy['summary']['mae']);
        // bias = ((5-3) + (7-10)) / 2 = (2 + (-3)) / 2 = -0.5
        $this->assertEquals(-0.5, $accuracy['summary']['bias']);
        $this->assertEquals(2, $accuracy['summary']['count']);
    }

    public function testAccuracyBucketBreakdown(): void
    {
        $this->insertPlayer(1, 'LowPred');
        $this->insertPlayer(2, 'MidPred');
        $this->insertPlayer(3, 'HighPred');

        // Low bucket (0-2)
        $this->insertSnapshot(1, 24, 1.5);
        $this->insertHistory(1, 24, 2);

        // Mid bucket (2-5)
        $this->insertSnapshot(2, 24, 3.5);
        $this->insertHistory(2, 24, 5);

        // High bucket (5+)
        $this->insertSnapshot(3, 24, 6.0);
        $this->insertHistory(3, 24, 8);

        $service = new PredictionService($this->db);
        $accuracy = $service->getAccuracy(24);

        $this->assertArrayHasKey('buckets', $accuracy);
        $this->assertNotEmpty($accuracy['buckets']);

        // Each bucket should have mae, bias, count
        foreach ($accuracy['buckets'] as $bucket) {
            $this->assertArrayHasKey('range', $bucket);
            $this->assertArrayHasKey('mae', $bucket);
            $this->assertArrayHasKey('bias', $bucket);
            $this->assertArrayHasKey('count', $bucket);
        }
    }

    // --- Availability tests ---

    public function testAvailableWhenNullChanceAndNoNews(): void
    {
        $this->insertPlayer(1, 'Salah', 3, null, '');

        $service = new PredictionService($this->db);
        $availability = $this->invokeDerive($service, ['chance_of_playing' => null, 'news' => '']);

        $this->assertEquals('available', $availability);
    }

    public function testUnavailableWhenChanceZero(): void
    {
        $service = new PredictionService($this->db);
        $availability = $this->invokeDerive($service, ['chance_of_playing' => 0, 'news' => 'Knee injury']);

        $this->assertEquals('unavailable', $availability);
    }

    public function testSuspendedWhenChanceZeroWithSuspendNews(): void
    {
        $service = new PredictionService($this->db);
        $availability = $this->invokeDerive($service, ['chance_of_playing' => 0, 'news' => 'Suspended for 1 match']);

        $this->assertEquals('suspended', $availability);
    }

    public function testInjuredWhenChanceLow(): void
    {
        $service = new PredictionService($this->db);
        $availability = $this->invokeDerive($service, ['chance_of_playing' => 25, 'news' => 'Hamstring']);

        $this->assertEquals('injured', $availability);
    }

    public function testDoubtfulWhenChanceMedium(): void
    {
        $service = new PredictionService($this->db);
        $availability = $this->invokeDerive($service, ['chance_of_playing' => 50, 'news' => 'Knock']);

        $this->assertEquals('doubtful', $availability);
    }

    public function testAvailableWhenChanceHigh(): void
    {
        $service = new PredictionService($this->db);
        $availability = $this->invokeDerive($service, ['chance_of_playing' => 100, 'news' => '']);

        $this->assertEquals('available', $availability);
    }

    /**
     * Use reflection to test the private deriveAvailability method.
     */
    private function invokeDerive(PredictionService $service, array $player): string
    {
        $method = new \ReflectionMethod($service, 'deriveAvailability');
        $method->setAccessible(true);
        return $method->invoke($service, $player);
    }
}
