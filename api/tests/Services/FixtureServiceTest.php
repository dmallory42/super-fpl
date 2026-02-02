<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\FixtureService;

class FixtureServiceTest extends TestCase
{
    private Database $db;
    private FixtureService $service;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->createSchema();
        $this->insertTestData();
        $this->service = new FixtureService($this->db);
    }

    private function createSchema(): void
    {
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
        $this->db->getPdo()->exec("
            INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, home_score, away_score, home_difficulty, away_difficulty, finished) VALUES
            (1, 23, 1, 2, '2024-01-01 12:30:00', 2, 1, 3, 4, 1),
            (2, 23, 3, 4, '2024-01-01 15:00:00', 0, 0, 2, 3, 1),
            (3, 24, 5, 6, '2024-01-08 15:00:00', NULL, NULL, 4, 2, 0),
            (4, 24, 7, 8, '2024-01-08 17:30:00', NULL, NULL, 3, 3, 0),
            (5, 25, 1, 3, '2024-01-15 15:00:00', NULL, NULL, 2, 4, 0)
        ");
    }

    public function testGetAllFixtures(): void
    {
        $fixtures = $this->service->getAll();

        $this->assertCount(5, $fixtures);
    }

    public function testGetAllFixturesOrderedByKickoffTime(): void
    {
        $fixtures = $this->service->getAll();

        // Verify ordering
        $this->assertEquals('2024-01-01 12:30:00', $fixtures[0]['kickoff_time']);
        $this->assertEquals('2024-01-01 15:00:00', $fixtures[1]['kickoff_time']);
    }

    public function testGetFixturesByGameweek(): void
    {
        $fixtures = $this->service->getAll(23);

        $this->assertCount(2, $fixtures);
        foreach ($fixtures as $fixture) {
            $this->assertEquals(23, $fixture['gameweek']);
        }
    }

    public function testGetFixturesByGameweekReturnsEmpty(): void
    {
        $fixtures = $this->service->getAll(99);

        $this->assertEmpty($fixtures);
    }

    public function testFixtureContainsExpectedFields(): void
    {
        $fixtures = $this->service->getAll(23);
        $fixture = $fixtures[0];

        $this->assertArrayHasKey('id', $fixture);
        $this->assertArrayHasKey('gameweek', $fixture);
        $this->assertArrayHasKey('home_club_id', $fixture);
        $this->assertArrayHasKey('away_club_id', $fixture);
        $this->assertArrayHasKey('kickoff_time', $fixture);
        $this->assertArrayHasKey('home_score', $fixture);
        $this->assertArrayHasKey('away_score', $fixture);
        $this->assertArrayHasKey('home_difficulty', $fixture);
        $this->assertArrayHasKey('away_difficulty', $fixture);
        $this->assertArrayHasKey('finished', $fixture);
    }

    public function testFinishedFixtureHasScores(): void
    {
        $fixtures = $this->service->getAll(23);

        $finishedFixture = $fixtures[0];
        $this->assertEquals(1, $finishedFixture['finished']);
        $this->assertEquals(2, $finishedFixture['home_score']);
        $this->assertEquals(1, $finishedFixture['away_score']);
    }
}
