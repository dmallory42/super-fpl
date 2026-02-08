<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Prediction\HistoricalBaselines;

class HistoricalBaselinesTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->db->getPdo()->exec("
            CREATE TABLE players (
                id INTEGER PRIMARY KEY,
                code INTEGER,
                web_name TEXT,
                club_id INTEGER,
                position INTEGER,
                understat_id INTEGER
            )
        ");

        $this->db->getPdo()->exec("
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

        $this->db->getPdo()->exec("
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
    }

    public function testPrefersUnderstatNpxgOverFplXg(): void
    {
        // Player with code 100, understat_id 555
        $this->db->getPdo()->exec("
            INSERT INTO players (id, code, web_name, understat_id)
            VALUES (1, 100, 'Salah', 555)
        ");

        // Understat history: npxG (non-penalty)
        $this->db->getPdo()->exec("
            INSERT INTO understat_season_history (understat_id, season, minutes, npxg, xa)
            VALUES
            (555, 2023, 2700, 12.0, 6.0),
            (555, 2022, 1800, 7.2, 4.5)
        ");

        // FPL history: penalty-inclusive xG (should be ignored for this player)
        $this->db->getPdo()->exec("
            INSERT INTO player_season_history (player_code, season_id, minutes, expected_goals, expected_assists, total_points)
            VALUES
            (100, '2022/23', 2700, 15.0, 6.0, 200),
            (100, '2023/24', 1800, 10.0, 4.5, 150)
        ");

        $baselines = new HistoricalBaselines($this->db);

        // Understat: 4500 mins, 19.2 npxG → 19.2/4500*90 = 0.384
        $xgPer90 = $baselines->getXgPer90(100);
        $this->assertEqualsWithDelta(0.384, $xgPer90, 0.001);

        // Understat: 4500 mins, 10.5 xA → 10.5/4500*90 = 0.21
        $xaPer90 = $baselines->getXaPer90(100);
        $this->assertEqualsWithDelta(0.21, $xaPer90, 0.001);
    }

    public function testFallsBackToFplWhenNoUnderstatData(): void
    {
        // Player without understat_id
        $this->db->getPdo()->exec("
            INSERT INTO players (id, code, web_name, understat_id)
            VALUES (1, 200, 'NewPlayer', NULL)
        ");

        // Only FPL history
        $this->db->getPdo()->exec("
            INSERT INTO player_season_history (player_code, season_id, minutes, expected_goals, expected_assists, total_points)
            VALUES
            (200, '2022/23', 2700, 9.0, 4.5, 150),
            (200, '2023/24', 1800, 7.2, 3.6, 120)
        ");

        $baselines = new HistoricalBaselines($this->db);

        // FPL: 4500 mins, 16.2 xG → 0.324
        $xgPer90 = $baselines->getXgPer90(200);
        $this->assertEqualsWithDelta(0.324, $xgPer90, 0.001);

        // FPL: 4500 mins, 8.1 xA → 0.162
        $xaPer90 = $baselines->getXaPer90(200);
        $this->assertEqualsWithDelta(0.162, $xaPer90, 0.001);
    }

    public function testUnderstatTakesPriorityOverFpl(): void
    {
        // Player has both Understat and FPL data — Understat should win
        $this->db->getPdo()->exec("
            INSERT INTO players (id, code, web_name, understat_id)
            VALUES (1, 300, 'Haaland', 777)
        ");

        $this->db->getPdo()->exec("
            INSERT INTO understat_season_history (understat_id, season, minutes, npxg, xa)
            VALUES (777, 2023, 2700, 18.0, 3.0)
        ");

        $this->db->getPdo()->exec("
            INSERT INTO player_season_history (player_code, season_id, minutes, expected_goals, expected_assists, total_points)
            VALUES (300, '2023/24', 2700, 25.0, 3.0, 250)
        ");

        $baselines = new HistoricalBaselines($this->db);

        // Should use Understat npxG (18.0), not FPL xG (25.0)
        $xgPer90 = $baselines->getXgPer90(300);
        $this->assertEqualsWithDelta(0.6, $xgPer90, 0.001); // 18.0/2700*90 = 0.6
    }

    public function testRegressionWeightFormula(): void
    {
        $baselines = new HistoricalBaselines($this->db);

        $this->assertEqualsWithDelta(0.5, $baselines->getRegressionWeight(900), 0.001);
        $this->assertEqualsWithDelta(1.0, $baselines->getRegressionWeight(1800), 0.001);
        $this->assertEqualsWithDelta(1.0, $baselines->getRegressionWeight(3600), 0.001);
        $this->assertEqualsWithDelta(0.0, $baselines->getRegressionWeight(0), 0.001);
    }

    public function testEffectiveRateBlending(): void
    {
        $baselines = new HistoricalBaselines($this->db);

        // At 900 minutes: weight = 0.5
        $effective = $baselines->getEffectiveRate(0.5, 0.3, 900);
        $this->assertEqualsWithDelta(0.4, $effective, 0.001);

        // At 1800 minutes: weight = 1.0 → full current rate
        $effective1800 = $baselines->getEffectiveRate(0.5, 0.3, 1800);
        $this->assertEqualsWithDelta(0.5, $effective1800, 0.001);
    }

    public function testMissingPlayerReturnsZero(): void
    {
        $baselines = new HistoricalBaselines($this->db);

        $this->assertEquals(0.0, $baselines->getXgPer90(999));
        $this->assertEquals(0.0, $baselines->getXaPer90(999));
    }
}
