<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\GameweekService;

class GameweekServiceTest extends TestCase
{
    private Database $db;
    private GameweekService $service;

    protected function setUp(): void
    {
        // Use in-memory SQLite for tests
        $this->db = new Database(':memory:');
        $this->createSchema();
        $this->insertTestData();

        $this->service = new GameweekService($this->db);
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
    }

    private function insertTestData(): void
    {
        // Insert test clubs
        $this->db->getPdo()->exec("
            INSERT INTO clubs (id, name, short_name) VALUES
            (1, 'Arsenal', 'ARS'),
            (2, 'Chelsea', 'CHE'),
            (3, 'Liverpool', 'LIV'),
            (4, 'Man City', 'MCI'),
            (5, 'Man Utd', 'MUN'),
            (6, 'Tottenham', 'TOT')
        ");

        // Insert test fixtures
        // GW23: All finished
        // GW24: Not started
        // GW25: Team 1 has DGW (2 fixtures)
        $this->db->getPdo()->exec("
            INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, finished) VALUES
            (1, 23, 1, 2, '2024-01-01 15:00:00', 1),
            (2, 23, 3, 4, '2024-01-01 15:00:00', 1),
            (3, 24, 1, 3, '2099-06-01 15:00:00', 0),
            (4, 24, 2, 4, '2099-06-01 15:00:00', 0),
            (5, 25, 1, 4, '2099-06-08 15:00:00', 0),
            (6, 25, 2, 3, '2099-06-08 15:00:00', 0),
            (7, 25, 1, 5, '2099-06-08 17:00:00', 0)
        ");
    }

    public function testGetCurrentGameweek(): void
    {
        $currentGw = $this->service->getCurrentGameweek();

        $this->assertEquals(24, $currentGw, 'Should return first unfinished gameweek');
    }

    public function testGetCurrentGameweekReturns38WhenAllFinished(): void
    {
        // Mark all fixtures as finished
        $this->db->getPdo()->exec("UPDATE fixtures SET finished = 1");

        $currentGw = $this->service->getCurrentGameweek();

        $this->assertEquals(38, $currentGw, 'Should return 38 when all gameweeks finished');
    }

    public function testGetUpcomingGameweeks(): void
    {
        $upcoming = $this->service->getUpcomingGameweeks(3);

        $this->assertCount(3, $upcoming);
        $this->assertEquals([24, 25, 26], $upcoming);
    }

    public function testGetUpcomingGameweeksLimitedTo38(): void
    {
        // Set all fixtures to finished except GW37+
        $this->db->getPdo()->exec("UPDATE fixtures SET finished = 1");
        $this->db->getPdo()->exec("
            INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, finished) VALUES
            (100, 37, 1, 2, '2024-05-01 15:00:00', 0)
        ");

        $upcoming = $this->service->getUpcomingGameweeks(5);

        $this->assertEquals([37, 38], $upcoming, 'Should not exceed gameweek 38');
    }

    public function testGetFixtureCounts(): void
    {
        $counts = $this->service->getFixtureCounts([24, 25]);

        // GW24: team 1 has 1 fixture, team 2 has 1, team 3 has 1, team 4 has 1
        $this->assertEquals(1, $counts[24][1] ?? 0);
        $this->assertEquals(1, $counts[24][2] ?? 0);

        // GW25: team 1 has 2 fixtures (DGW)
        $this->assertEquals(2, $counts[25][1] ?? 0);
    }

    public function testGetFixtureCountsEmptyArray(): void
    {
        $counts = $this->service->getFixtureCounts([]);

        $this->assertEmpty($counts);
    }

    public function testGetDoubleGameweekTeams(): void
    {
        $dgwTeams = $this->service->getDoubleGameweekTeams(25);

        $this->assertContains(1, $dgwTeams, 'Team 1 should have a DGW in GW25');
        $this->assertNotContains(2, $dgwTeams, 'Team 2 should not have a DGW in GW25');
    }

    public function testGetBlankGameweekTeams(): void
    {
        $bgwTeams = $this->service->getBlankGameweekTeams(25);

        // Team 6 has no fixtures in GW25
        $this->assertContains(6, $bgwTeams, 'Team 6 should have a BGW in GW25');
        $this->assertNotContains(1, $bgwTeams, 'Team 1 should not have a BGW in GW25');
    }

    public function testHasGameweekStarted(): void
    {
        $this->assertTrue($this->service->hasGameweekStarted(23), 'GW23 should have started (kickoff in the past)');
        $this->assertFalse($this->service->hasGameweekStarted(24), 'GW24 should not have started (kickoff in the future)');
    }

    public function testIsGameweekFinished(): void
    {
        $this->assertTrue($this->service->isGameweekFinished(23), 'GW23 should be finished');
        $this->assertFalse($this->service->isGameweekFinished(24), 'GW24 should not be finished');
    }

    public function testGetNextActionableGameweek(): void
    {
        // Current GW is 24 (first unfinished), kickoff in future → actionable is 24
        $this->assertEquals(24, $this->service->getNextActionableGameweek());
    }

    public function testGetNextActionableGameweekSkipsStartedGw(): void
    {
        // Move GW24 kickoffs to the past so it counts as started
        $this->db->getPdo()->exec("UPDATE fixtures SET kickoff_time = '2024-01-01 15:00:00' WHERE gameweek = 24");

        $this->assertEquals(25, $this->service->getNextActionableGameweek());
    }

    public function testGetMultipleDoubleGameweekTeams(): void
    {
        $dgw = $this->service->getMultipleDoubleGameweekTeams([24, 25]);

        // GW24 has no DGW teams
        $this->assertArrayNotHasKey(24, $dgw);

        // GW25 has team 1 as DGW (2 home fixtures)
        $this->assertArrayHasKey(25, $dgw);
        $this->assertContains(1, $dgw[25]);
        $this->assertNotContains(2, $dgw[25]);
    }

    public function testGetMultipleDoubleGameweekTeamsNoDgw(): void
    {
        // GW24 has no DGW teams
        $dgw = $this->service->getMultipleDoubleGameweekTeams([24]);

        $this->assertEmpty($dgw);
    }

    public function testGetMultipleBlankGameweekTeams(): void
    {
        $bgw = $this->service->getMultipleBlankGameweekTeams([24, 25]);

        // GW24: teams 5, 6 have no fixtures
        $this->assertArrayHasKey(24, $bgw);
        $this->assertContains(5, $bgw[24]);
        $this->assertContains(6, $bgw[24]);
        $this->assertNotContains(1, $bgw[24]);

        // GW25: team 6 has no fixtures
        $this->assertArrayHasKey(25, $bgw);
        $this->assertContains(6, $bgw[25]);
        $this->assertNotContains(1, $bgw[25]);
    }

    public function testGetMultipleBlankGameweekTeamsNoBgw(): void
    {
        // Add fixtures so all 6 teams play in GW24
        $this->db->getPdo()->exec("
            INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, finished) VALUES
            (50, 24, 5, 6, '2099-06-01 15:00:00', 0)
        ");

        $bgw = $this->service->getMultipleBlankGameweekTeams([24]);

        $this->assertArrayNotHasKey(24, $bgw);
    }

    public function testGetMultipleBlankGameweekTeamsEmptyInput(): void
    {
        $bgw = $this->service->getMultipleBlankGameweekTeams([]);

        $this->assertEmpty($bgw);
    }
}
