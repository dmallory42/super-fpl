<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Tests\Support\TestDatabase;
use SuperFPL\Api\Services\ManagerSeasonAnalysisService;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;
use SuperFPL\FplClient\FplClient;

class ManagerSeasonAnalysisServiceTest extends TestCase
{
    private TestDatabase $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabase(':memory:');
        $this->createSchema();
        $this->seedData();
    }

    private function createSchema(): void
    {
        $pdo = $this->db->getPdo();

        $pdo->exec("
            CREATE TABLE manager_history (
                manager_id INTEGER,
                gameweek INTEGER,
                points INTEGER,
                total_points INTEGER,
                overall_rank INTEGER,
                bank INTEGER,
                team_value INTEGER,
                transfers_cost INTEGER,
                points_on_bench INTEGER,
                PRIMARY KEY (manager_id, gameweek)
            )
        ");

        $pdo->exec("
            CREATE TABLE manager_picks (
                manager_id INTEGER,
                gameweek INTEGER,
                player_id INTEGER,
                position INTEGER,
                multiplier INTEGER,
                is_captain BOOLEAN,
                is_vice_captain BOOLEAN,
                PRIMARY KEY (manager_id, gameweek, player_id)
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
                snapshot_source TEXT DEFAULT 'legacy',
                is_pre_deadline INTEGER DEFAULT 0,
                snapped_at TIMESTAMP,
                PRIMARY KEY (player_id, gameweek)
            )
        ");

        $pdo->exec("
            CREATE TABLE player_predictions (
                player_id INTEGER,
                gameweek INTEGER,
                predicted_points REAL,
                predicted_if_fit REAL,
                expected_mins REAL,
                expected_mins_if_fit REAL,
                breakdown_json TEXT DEFAULT '{}',
                if_fit_breakdown_json TEXT DEFAULT '{}',
                confidence REAL,
                model_version TEXT,
                computed_at TIMESTAMP,
                PRIMARY KEY (player_id, gameweek)
            )
        ");

        $pdo->exec("
            CREATE TABLE player_gameweek_history (
                player_id INTEGER,
                gameweek INTEGER,
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
            CREATE TABLE players (
                id INTEGER PRIMARY KEY,
                web_name TEXT
            )
        ");
    }

    private function seedData(): void
    {
        $pdo = $this->db->getPdo();

        $pdo->exec("
            INSERT INTO manager_history (manager_id, gameweek, points, total_points, overall_rank, bank, team_value, transfers_cost, points_on_bench) VALUES
            (100, 1, 12, 12, 100000, 0, 1000, 0, 0),
            (100, 2, 10, 22, 90000, 0, 1001, 4, 2),
            (200, 1, 70, 70, 9000, 0, 1000, 0, 0),
            (200, 2, 60, 130, 9500, 0, 1000, 0, 0),
            (300, 1, 50, 50, 12000, 0, 1000, 0, 0),
            (300, 2, 40, 90, 15000, 0, 1000, 0, 0)
        ");

        // GW1 picks for manager 100 (player 1 captain)
        $pdo->exec("
            INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain) VALUES
            (100, 1, 1, 1, 2, 1, 0),
            (100, 1, 2, 2, 1, 0, 1)
        ");

        // GW2 picks for manager 100 (player 2 captain)
        $pdo->exec("
            INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain) VALUES
            (100, 2, 1, 1, 1, 0, 1),
            (100, 2, 2, 2, 2, 1, 0)
        ");

        $pdo->exec("
            INSERT INTO prediction_snapshots (
                player_id, gameweek, predicted_points, confidence, breakdown, model_version, snapshot_source, is_pre_deadline, snapped_at
            ) VALUES
            (1, 1, 5.0, 0.8, '{}', 'v1', 'test', 1, '2026-02-01 00:00:00'),
            (2, 1, 4.0, 0.8, '{}', 'v1', 'test', 1, '2026-02-01 00:00:00'),
            (1, 2, 6.0, 0.8, '{}', 'v1', 'test', 1, '2026-02-01 00:00:00'),
            (2, 2, 3.5, 0.8, '{}', 'v1', 'test', 1, '2026-02-01 00:00:00')
        ");

        $pdo->exec("
            INSERT INTO player_gameweek_history (player_id, gameweek, total_points, expected_goals, expected_assists, expected_goals_conceded, value, selected) VALUES
            (1, 1, 7, 0, 0, 0, 100, 1000),
            (2, 1, 2, 0, 0, 0, 100, 1000),
            (1, 2, 1, 0, 0, 0, 100, 1000),
            (2, 2, 8, 0, 0, 0, 100, 1000)
        ");

        $pdo->exec("
            INSERT INTO players (id, web_name) VALUES
            (1, 'PlayerOne'),
            (2, 'PlayerTwo'),
            (3, 'PlayerThree')
        ");
    }

    public function testSeasonAnalysisReturnsConsistentLuckAndTransferRows(): void
    {
        $mockEntry = $this->createMock(EntryEndpoint::class);
        $mockEntry->method('history')->willReturn([
            'current' => [
                [
                    'event' => 1,
                    'points' => 12,
                    'total_points' => 12,
                    'overall_rank' => 100000,
                    'bank' => 0,
                    'value' => 1000,
                    'event_transfers' => 0,
                    'event_transfers_cost' => 0,
                    'points_on_bench' => 0,
                ],
                [
                    'event' => 2,
                    'points' => 10,
                    'total_points' => 22,
                    'overall_rank' => 90000,
                    'bank' => 0,
                    'value' => 1001,
                    'event_transfers' => 1,
                    'event_transfers_cost' => 4,
                    'points_on_bench' => 2,
                ],
            ],
            'chips' => [
                ['name' => '3xc', 'event' => 2, 'time' => '2026-02-01T00:00:00Z'],
            ],
        ]);
        $mockEntry->method('transfers')->willReturn([
            ['event' => 2, 'element_in' => 2, 'element_out' => 1],
        ]);

        $mockClient = $this->createMock(FplClient::class);
        $mockClient->method('entry')->with(100)->willReturn($mockEntry);

        $service = new ManagerSeasonAnalysisService($this->db, $mockClient);
        $result = $service->analyze(100);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['gameweeks']);
        $this->assertCount(1, $result['transfer_analytics']);

        $actual = (float) $result['summary']['actual_points'];
        $expected = (float) $result['summary']['expected_points'];
        $luck = (float) $result['summary']['luck_delta'];
        $this->assertEqualsWithDelta($actual - $expected, $luck, 0.01);

        $transferGw = $result['transfer_analytics'][0];
        $this->assertSame(2, $transferGw['gameweek']);
        $this->assertSame(1, $transferGw['transfer_count']);
        $this->assertArrayHasKey('transfers', $transferGw);
        $this->assertCount(1, $transferGw['transfers']);
        $this->assertSame('PlayerOne', $transferGw['transfers'][0]['out_name']);
        $this->assertSame('PlayerTwo', $transferGw['transfers'][0]['in_name']);

        $this->assertArrayHasKey('benchmarks', $result);
        $this->assertSame(44.0, $result['benchmarks']['overall'][0]['points']);
        $this->assertSame(36.67, $result['benchmarks']['overall'][1]['points']);
        $this->assertSame(70.0, $result['benchmarks']['top_10k'][0]['points']);
        $this->assertSame(60.0, $result['benchmarks']['top_10k'][1]['points']);

        $this->assertArrayHasKey('season_player_points', $result);
        $this->assertCount(2, $result['season_player_points']);
        $this->assertSame(2, $result['season_player_points'][0]['player_id']);
        $this->assertSame(10.0, $result['season_player_points'][0]['owned_points']);
        $this->assertSame(18.0, $result['season_player_points'][0]['contributed_points']);
        $this->assertSame(2, $result['season_player_points'][0]['owned_gameweeks']);
        $this->assertSame(1, $result['season_player_points'][1]['player_id']);
        $this->assertSame(8.0, $result['season_player_points'][1]['owned_points']);
        $this->assertSame(15.0, $result['season_player_points'][1]['contributed_points']);
        $this->assertSame(2, $result['season_player_points'][1]['owned_gameweeks']);
    }

    public function testBulkPreloadProducesIdenticalResults(): void
    {
        // Seed additional player (3) with prediction_snapshots for GW1 but only
        // player_predictions (with if-fit fallback) for GW2 to exercise both paths.
        $pdo = $this->db->getPdo();

        // Player 3 picks for manager 100 in both GWs
        $pdo->exec("
            INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain) VALUES
            (100, 1, 3, 3, 1, 0, 0),
            (100, 2, 3, 3, 1, 0, 0)
        ");

        // Player 3 snapshot only for GW1
        $pdo->exec("
            INSERT INTO prediction_snapshots (
                player_id, gameweek, predicted_points, confidence, breakdown, model_version, snapshot_source, is_pre_deadline, snapped_at
            ) VALUES
            (3, 1, 3.0, 0.7, '{}', 'v1', 'test', 1, '2026-02-01 00:00:00')
        ");

        // Player 3 player_predictions for GW2 with if-fit fallback triggered
        // (predicted_points near zero, predicted_if_fit has real value)
        $pdo->exec("
            INSERT INTO player_predictions (player_id, gameweek, predicted_points, predicted_if_fit, expected_mins, expected_mins_if_fit, confidence, model_version, computed_at) VALUES
            (3, 2, 0.01, 4.5, 5.0, 60.0, 0.7, 'v1', '2026-02-01 00:00:00')
        ");

        // Player 3 actuals
        $pdo->exec("
            INSERT INTO player_gameweek_history (player_id, gameweek, total_points, expected_goals, expected_assists, expected_goals_conceded, value, selected) VALUES
            (3, 1, 5, 0, 0, 0, 100, 500),
            (3, 2, 3, 0, 0, 0, 100, 500)
        ");

        $mockEntry = $this->createMock(EntryEndpoint::class);
        $mockEntry->method('history')->willReturn([
            'current' => [
                [
                    'event' => 1, 'points' => 12, 'total_points' => 12,
                    'overall_rank' => 100000, 'bank' => 0, 'value' => 1000,
                    'event_transfers' => 0, 'event_transfers_cost' => 0, 'points_on_bench' => 0,
                ],
                [
                    'event' => 2, 'points' => 10, 'total_points' => 22,
                    'overall_rank' => 90000, 'bank' => 0, 'value' => 1001,
                    'event_transfers' => 1, 'event_transfers_cost' => 4, 'points_on_bench' => 2,
                ],
            ],
            'chips' => [],
        ]);
        $mockEntry->method('transfers')->willReturn([
            ['event' => 2, 'element_in' => 2, 'element_out' => 1],
        ]);

        $mockClient = $this->createMock(FplClient::class);
        $mockClient->method('entry')->with(100)->willReturn($mockEntry);

        $service = new ManagerSeasonAnalysisService($this->db, $mockClient);
        $result = $service->analyze(100);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['gameweeks']);

        // GW1: players 1(cap x2), 2(x1), 3(x1)
        // Expected = 5.0*2 + 4.0*1 + 3.0*1 = 17.0
        $gw1 = $result['gameweeks'][0];
        $this->assertSame(1, $gw1['gameweek']);
        $this->assertEqualsWithDelta(17.0, $gw1['expected_points'], 0.01);

        // GW2: players 1(x1), 2(cap x2), 3(x1)
        // Player 3 GW2: if-fit fallback triggers (0.01 <= 0.05 and predicted_if_fit=4.5) → 4.5
        // Expected = 6.0*1 + 3.5*2 + 4.5*1 = 17.5
        $gw2 = $result['gameweeks'][1];
        $this->assertSame(2, $gw2['gameweek']);
        $this->assertEqualsWithDelta(17.5, $gw2['expected_points'], 0.01);

        // Verify luck_delta = actual - expected for each GW
        $this->assertEqualsWithDelta(
            $gw1['actual_points'] - $gw1['expected_points'],
            $gw1['luck_delta'],
            0.01
        );
        $this->assertEqualsWithDelta(
            $gw2['actual_points'] - $gw2['expected_points'],
            $gw2['luck_delta'],
            0.01
        );
    }

    public function testCacheResetsAcrossAnalyzeCalls(): void
    {
        // Run analyze for manager 100, then a second call should produce fresh results
        $mockEntry = $this->createMock(EntryEndpoint::class);
        $mockEntry->method('history')->willReturn([
            'current' => [
                [
                    'event' => 1, 'points' => 12, 'total_points' => 12,
                    'overall_rank' => 100000, 'bank' => 0, 'value' => 1000,
                    'event_transfers' => 0, 'event_transfers_cost' => 0, 'points_on_bench' => 0,
                ],
            ],
            'chips' => [],
        ]);
        $mockEntry->method('transfers')->willReturn([]);

        $mockClient = $this->createMock(FplClient::class);
        $mockClient->method('entry')->with(100)->willReturn($mockEntry);

        $service = new ManagerSeasonAnalysisService($this->db, $mockClient);

        $result1 = $service->analyze(100);
        $this->assertNotNull($result1);

        // Update prediction data between calls
        $this->db->getPdo()->exec("UPDATE prediction_snapshots SET predicted_points = 10.0 WHERE player_id = 1 AND gameweek = 1");

        $result2 = $service->analyze(100);
        $this->assertNotNull($result2);

        // GW1 expected should change: was 5.0*2+4.0=14.0, now 10.0*2+4.0=24.0
        $this->assertEqualsWithDelta(24.0, $result2['gameweeks'][0]['expected_points'], 0.01);
        // Confirm first result was different
        $this->assertEqualsWithDelta(14.0, $result1['gameweeks'][0]['expected_points'], 0.01);
    }

    public function testSeasonAnalysisHandlesNoChipsAndNoHits(): void
    {
        $mockEntry = $this->createMock(EntryEndpoint::class);
        $mockEntry->method('history')->willReturn([
            'current' => [
                [
                    'event' => 1,
                    'points' => 9,
                    'total_points' => 9,
                    'overall_rank' => 100000,
                    'bank' => 0,
                    'value' => 1000,
                    'event_transfers' => 0,
                    'event_transfers_cost' => 0,
                    'points_on_bench' => 1,
                ],
            ],
            'chips' => [],
        ]);
        $mockEntry->method('transfers')->willReturn([]);

        $mockClient = $this->createMock(FplClient::class);
        $mockClient->method('entry')->with(100)->willReturn($mockEntry);

        $service = new ManagerSeasonAnalysisService($this->db, $mockClient);
        $result = $service->analyze(100);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['gameweeks']);
        $this->assertSame([], $result['transfer_analytics']);
    }

    public function testTransferForesightIsNullWhenSnapshotCoverageIsIncomplete(): void
    {
        $this->db->query(
            'DELETE FROM prediction_snapshots WHERE player_id = ? AND gameweek = ?',
            [2, 2]
        );

        $mockEntry = $this->createMock(EntryEndpoint::class);
        $mockEntry->method('history')->willReturn([
            'current' => [
                [
                    'event' => 1,
                    'points' => 12,
                    'total_points' => 12,
                    'overall_rank' => 100000,
                    'bank' => 0,
                    'value' => 1000,
                    'event_transfers' => 0,
                    'event_transfers_cost' => 0,
                    'points_on_bench' => 0,
                ],
                [
                    'event' => 2,
                    'points' => 10,
                    'total_points' => 22,
                    'overall_rank' => 90000,
                    'bank' => 0,
                    'value' => 1001,
                    'event_transfers' => 1,
                    'event_transfers_cost' => 4,
                    'points_on_bench' => 2,
                ],
            ],
            'chips' => [],
        ]);
        $mockEntry->method('transfers')->willReturn([
            ['event' => 2, 'element_in' => 2, 'element_out' => 1],
        ]);

        $mockClient = $this->createMock(FplClient::class);
        $mockClient->method('entry')->with(100)->willReturn($mockEntry);

        $service = new ManagerSeasonAnalysisService($this->db, $mockClient);
        $result = $service->analyze(100);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['transfer_analytics']);
        $this->assertNull($result['transfer_analytics'][0]['foresight_gain']);
        $this->assertFalse($result['transfer_analytics'][0]['foresight_complete']);
        $this->assertNull($result['summary']['transfer_foresight_gain']);
    }
}
