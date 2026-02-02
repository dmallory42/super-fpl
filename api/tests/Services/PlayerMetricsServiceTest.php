<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\PlayerMetricsService;

class PlayerMetricsServiceTest extends TestCase
{
    private Database $db;
    private PlayerMetricsService $service;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->createSchema();
        $this->service = new PlayerMetricsService($this->db);
    }

    private function createSchema(): void
    {
        $this->db->getPdo()->exec("
            CREATE TABLE clubs (
                id INTEGER PRIMARY KEY,
                name TEXT,
                short_name TEXT
            )
        ");

        $this->db->getPdo()->exec("
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
                selected_by_percent REAL,
                minutes INTEGER,
                goals_scored INTEGER,
                assists INTEGER,
                clean_sheets INTEGER,
                expected_goals REAL,
                expected_assists REAL,
                ict_index REAL,
                bps INTEGER,
                bonus INTEGER,
                starts INTEGER,
                chance_of_playing INTEGER,
                news TEXT
            )
        ");
    }

    public function testGetEnhancedMetricsWithZeroMinutes(): void
    {
        $player = [
            'minutes' => 0,
            'goals_scored' => 0,
            'assists' => 0,
            'expected_goals' => 0,
            'expected_assists' => 0,
        ];

        $metrics = $this->service->getEnhancedMetrics($player);

        $this->assertEquals(0, $metrics['minutes']);
        $this->assertEquals(0, $metrics['nineties']);
        $this->assertEquals(0, $metrics['goals_per_90']);
        $this->assertEquals(0, $metrics['assists_per_90']);
    }

    public function testGetEnhancedMetricsPerNinetyStats(): void
    {
        $player = [
            'minutes' => 900, // 10 x 90
            'goals_scored' => 5,
            'assists' => 3,
            'expected_goals' => 4.5,
            'expected_assists' => 2.5,
        ];

        $metrics = $this->service->getEnhancedMetrics($player);

        $this->assertEquals(10, $metrics['nineties']);
        $this->assertEquals(0.5, $metrics['goals_per_90']);
        $this->assertEquals(0.3, $metrics['assists_per_90']);
        $this->assertEquals(0.45, $metrics['xg_per_90']);
        $this->assertEquals(0.25, $metrics['xa_per_90']);
    }

    public function testGetEnhancedMetricsNonPenaltyStats(): void
    {
        $player = [
            'minutes' => 900,
            'goals_scored' => 8,
            'assists' => 2,
            'expected_goals' => 4.0, // 4 goals more than xG suggests penalties
            'expected_assists' => 2.0,
        ];

        $metrics = $this->service->getEnhancedMetrics($player);

        // Non-penalty goals should be less than or equal to total goals
        $this->assertLessThanOrEqual($metrics['goals'], $metrics['np_goals']);
        // Estimated penalties should be non-negative and not exceed total goals
        $this->assertGreaterThanOrEqual(0, $metrics['estimated_penalties_scored']);
        $this->assertLessThanOrEqual($metrics['goals'], $metrics['estimated_penalties_scored']);
        // np_xg should be non-negative
        $this->assertGreaterThanOrEqual(0, $metrics['np_xg']);
    }

    public function testGetEnhancedMetricsPerformanceDifferentials(): void
    {
        $player = [
            'minutes' => 900,
            'goals_scored' => 6,
            'assists' => 4,
            'expected_goals' => 5.0,
            'expected_assists' => 3.0,
        ];

        $metrics = $this->service->getEnhancedMetrics($player);

        // Goals - xG = 6 - 5 = 1 (overperforming)
        $this->assertEquals(1, $metrics['goals_minus_xg']);
        // Assists - xA = 4 - 3 = 1 (overperforming)
        $this->assertEquals(1, $metrics['assists_minus_xa']);
    }

    public function testGetEnhancedMetricsUnderperformingPlayer(): void
    {
        $player = [
            'minutes' => 1800,
            'goals_scored' => 2,
            'assists' => 1,
            'expected_goals' => 5.0,
            'expected_assists' => 3.0,
        ];

        $metrics = $this->service->getEnhancedMetrics($player);

        // Underperforming: goals < xG
        $this->assertLessThan(0, $metrics['goals_minus_xg']);
        $this->assertLessThan(0, $metrics['assists_minus_xa']);
    }

    public function testGetEnhancedMetricsWithMissingFields(): void
    {
        // Player with missing/null fields
        $player = [
            'minutes' => 450,
        ];

        $metrics = $this->service->getEnhancedMetrics($player);

        $this->assertEquals(450, $metrics['minutes']);
        $this->assertEquals(5, $metrics['nineties']);
        $this->assertEquals(0, $metrics['goals']);
        $this->assertEquals(0, $metrics['assists']);
        $this->assertEquals(0, $metrics['xg']);
    }

    public function testGetAllWithMetrics(): void
    {
        // Insert test data
        $this->db->getPdo()->exec("INSERT INTO clubs (id, name, short_name) VALUES (1, 'Arsenal', 'ARS')");
        $this->db->getPdo()->exec("
            INSERT INTO players (id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form, selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists, ict_index, bps, bonus, starts, chance_of_playing, news)
            VALUES (1, 101, 'Saka', 'Bukayo', 'Saka', 1, 3, 90, 150, 8.5, 45.0, 2000, 10, 8, 0, 9.0, 7.0, 250.0, 500, 15, 22, 100, NULL)
        ");

        $players = $this->service->getAllWithMetrics();

        $this->assertCount(1, $players);
        $this->assertEquals('Saka', $players[0]['web_name']);
        $this->assertArrayHasKey('metrics', $players[0]);
        $this->assertEquals(10, $players[0]['metrics']['goals']);
    }
}
