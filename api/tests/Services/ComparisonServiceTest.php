<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\ComparisonService;
use SuperFPL\FplClient\FplClient;

class ComparisonServiceTest extends TestCase
{
    private Database $db;
    private ComparisonService $service;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->createSchema();
        $this->insertTestData();

        // Create mock FplClient
        $mockFplClient = $this->createMock(FplClient::class);

        $this->service = new ComparisonService($this->db, $mockFplClient);
    }

    private function createSchema(): void
    {
        $this->db->getPdo()->exec("
            CREATE TABLE players (
                id INTEGER PRIMARY KEY,
                web_name TEXT,
                club_id INTEGER,
                position INTEGER,
                now_cost INTEGER,
                total_points INTEGER
            )
        ");

        $this->db->getPdo()->exec("
            CREATE TABLE managers (
                id INTEGER PRIMARY KEY,
                name TEXT
            )
        ");

        $this->db->getPdo()->exec("
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
    }

    private function insertTestData(): void
    {
        // Insert players
        $this->db->getPdo()->exec("
            INSERT INTO players (id, web_name, club_id, position, now_cost, total_points) VALUES
            (1, 'Salah', 10, 3, 130, 200),
            (2, 'Haaland', 11, 4, 150, 180),
            (3, 'Saka', 1, 3, 90, 150),
            (4, 'Palmer', 2, 3, 105, 160),
            (5, 'Watkins', 3, 4, 80, 140),
            (6, 'Gordon', 12, 3, 70, 110),
            (7, 'Raya', 1, 1, 55, 120),
            (8, 'Ederson', 11, 1, 55, 115),
            (9, 'Gabriel', 1, 2, 60, 130),
            (10, 'Saliba', 1, 2, 58, 125),
            (11, 'Trippier', 12, 2, 65, 100)
        ");

        // Insert managers
        $this->db->getPdo()->exec("
            INSERT INTO managers (id, name) VALUES
            (1001, 'Manager A'),
            (1002, 'Manager B'),
            (1003, 'Manager C')
        ");

        // Insert picks for GW25
        // Manager A: Template team with Salah, Haaland (C)
        $this->db->getPdo()->exec("
            INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain) VALUES
            (1001, 25, 1, 1, 1, 0, 0),
            (1001, 25, 2, 2, 2, 1, 0),
            (1001, 25, 3, 3, 1, 0, 0),
            (1001, 25, 4, 4, 1, 0, 0),
            (1001, 25, 7, 5, 1, 0, 0),
            (1001, 25, 9, 6, 1, 0, 0),
            (1001, 25, 10, 7, 1, 0, 0),
            (1001, 25, 11, 8, 1, 0, 0),
            (1001, 25, 5, 9, 1, 0, 0),
            (1001, 25, 6, 10, 1, 0, 0),
            (1001, 25, 8, 11, 1, 0, 1)
        ");

        // Manager B: Similar but Salah (C), no Saka
        $this->db->getPdo()->exec("
            INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain) VALUES
            (1002, 25, 1, 1, 2, 1, 0),
            (1002, 25, 2, 2, 1, 0, 0),
            (1002, 25, 4, 3, 1, 0, 0),
            (1002, 25, 5, 4, 1, 0, 0),
            (1002, 25, 6, 5, 1, 0, 0),
            (1002, 25, 7, 6, 1, 0, 0),
            (1002, 25, 9, 7, 1, 0, 0),
            (1002, 25, 10, 8, 1, 0, 0),
            (1002, 25, 11, 9, 1, 0, 0),
            (1002, 25, 8, 10, 1, 0, 1),
            (1002, 25, 3, 11, 0, 0, 0)
        ");

        // Manager C: Differential team, Watkins (C)
        $this->db->getPdo()->exec("
            INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain) VALUES
            (1003, 25, 5, 1, 2, 1, 0),
            (1003, 25, 6, 2, 1, 0, 0),
            (1003, 25, 1, 3, 1, 0, 0),
            (1003, 25, 7, 4, 1, 0, 0),
            (1003, 25, 9, 5, 1, 0, 0),
            (1003, 25, 10, 6, 1, 0, 0),
            (1003, 25, 11, 7, 1, 0, 0),
            (1003, 25, 8, 8, 1, 0, 0),
            (1003, 25, 3, 9, 1, 0, 1),
            (1003, 25, 2, 10, 0, 0, 0),
            (1003, 25, 4, 11, 0, 0, 0)
        ");
    }

    public function testCompareManagers(): void
    {
        $result = $this->service->compare([1001, 1002, 1003], 25);

        $this->assertArrayHasKey('gameweek', $result);
        $this->assertEquals(25, $result['gameweek']);
        $this->assertEquals(3, $result['manager_count']);
    }

    public function testCompareReturnsEffectiveOwnership(): void
    {
        $result = $this->service->compare([1001, 1002, 1003], 25);

        $this->assertArrayHasKey('effective_ownership', $result);
        $eo = $result['effective_ownership'];

        // Salah: Manager A (1x) + Manager B (2x captain) + Manager C (1x) = 4
        // EO = 4/3 * 100 = 133.3%
        $this->assertGreaterThan(100, $eo[1] ?? 0, 'Salah should have >100% EO due to captaincy');
    }

    public function testCompareReturnsDifferentials(): void
    {
        $result = $this->service->compare([1001, 1002, 1003], 25);

        $this->assertArrayHasKey('differentials', $result);
        $this->assertArrayHasKey(1001, $result['differentials']);
    }

    public function testCompareReturnsRiskScores(): void
    {
        $result = $this->service->compare([1001, 1002, 1003], 25);

        $this->assertArrayHasKey('risk_scores', $result);

        foreach ([1001, 1002, 1003] as $managerId) {
            $this->assertArrayHasKey($managerId, $result['risk_scores']);
            $risk = $result['risk_scores'][$managerId];
            $this->assertArrayHasKey('score', $risk);
            $this->assertArrayHasKey('level', $risk);
            $this->assertContains($risk['level'], ['low', 'medium', 'high']);
        }
    }

    public function testCompareReturnsOwnershipMatrix(): void
    {
        $result = $this->service->compare([1001, 1002, 1003], 25);

        $this->assertArrayHasKey('ownership_matrix', $result);
        $matrix = $result['ownership_matrix'];

        // Salah (player 1) should be in matrix
        $this->assertArrayHasKey(1, $matrix);
        // All 3 managers own Salah
        $this->assertCount(3, $matrix[1]);
    }

    public function testCompareReturnsPlayerDetails(): void
    {
        $result = $this->service->compare([1001, 1002, 1003], 25);

        $this->assertArrayHasKey('players', $result);
        $players = $result['players'];

        // Check Salah's details
        $this->assertArrayHasKey(1, $players);
        $this->assertEquals('Salah', $players[1]['web_name']);
    }

    public function testCompareWithNoPicksReturnsError(): void
    {
        $result = $this->service->compare([9999], 25);

        $this->assertArrayHasKey('error', $result);
    }

    public function testCompareHandlesMixedCachedAndMissingManagers(): void
    {
        // Compare with one valid and one invalid manager
        $result = $this->service->compare([1001, 9999], 25);

        // Should still work with the valid manager
        $this->assertEquals(1, $result['manager_count']);
    }
}
