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
    }

    public function testCareerXgPer90AcrossSeasons(): void
    {
        // Player 100: 2 seasons
        $this->db->getPdo()->exec("
            INSERT INTO player_season_history (player_code, season_id, minutes, expected_goals, expected_assists, total_points)
            VALUES
            (100, '2022/23', 2700, 9.0, 4.5, 150),
            (100, '2023/24', 1800, 7.2, 3.6, 120)
        ");

        $baselines = new HistoricalBaselines($this->db);

        // Total: 4500 mins, 16.2 xG → 16.2/4500*90 = 0.324
        $xgPer90 = $baselines->getXgPer90(100);
        $this->assertEqualsWithDelta(0.324, $xgPer90, 0.001);

        // Total: 4500 mins, 8.1 xA → 8.1/4500*90 = 0.162
        $xaPer90 = $baselines->getXaPer90(100);
        $this->assertEqualsWithDelta(0.162, $xaPer90, 0.001);
    }

    public function testRegressionWeightFormula(): void
    {
        $this->db->getPdo()->exec("
            INSERT INTO player_season_history (player_code, season_id, minutes, expected_goals, expected_assists, total_points)
            VALUES (100, '2022/23', 900, 3.0, 1.5, 60)
        ");

        $baselines = new HistoricalBaselines($this->db);

        // 900 minutes → weight = 900/1800 = 0.5
        $this->assertEqualsWithDelta(0.5, $baselines->getRegressionWeight(900), 0.001);

        // 1800 minutes → weight = 1.0
        $this->assertEqualsWithDelta(1.0, $baselines->getRegressionWeight(1800), 0.001);

        // 3600 minutes → weight = 1.0 (capped)
        $this->assertEqualsWithDelta(1.0, $baselines->getRegressionWeight(3600), 0.001);

        // 0 minutes → weight = 0.0
        $this->assertEqualsWithDelta(0.0, $baselines->getRegressionWeight(0), 0.001);
    }

    public function testEffectiveRateBlending(): void
    {
        $this->db->getPdo()->exec("
            INSERT INTO player_season_history (player_code, season_id, minutes, expected_goals, expected_assists, total_points)
            VALUES (100, '2022/23', 2700, 9.0, 4.5, 150)
        ");

        $baselines = new HistoricalBaselines($this->db);

        // At 900 minutes: weight = 0.5
        // effective = (current * 0.5) + (historical * 0.5)
        $currentRate = 0.5;
        $historicalRate = 0.3;
        $effective = $baselines->getEffectiveRate($currentRate, $historicalRate, 900);
        $this->assertEqualsWithDelta(0.4, $effective, 0.001); // (0.5*0.5) + (0.3*0.5)

        // At 1800 minutes: weight = 1.0 → full current rate
        $effective1800 = $baselines->getEffectiveRate($currentRate, $historicalRate, 1800);
        $this->assertEqualsWithDelta(0.5, $effective1800, 0.001);
    }

    public function testMissingPlayerReturnsZero(): void
    {
        $baselines = new HistoricalBaselines($this->db);

        $this->assertEquals(0.0, $baselines->getXgPer90(999));
        $this->assertEquals(0.0, $baselines->getXaPer90(999));
    }
}
