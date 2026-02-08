<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\PlayerService;

class PlayerServiceTest extends TestCase
{
    private Database $db;
    private PlayerService $service;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->createSchema();
        $this->insertTestData();
        $this->service = new PlayerService($this->db);
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
                appearances INTEGER DEFAULT 0,
                chance_of_playing INTEGER,
                news TEXT,
                xmins_override INTEGER DEFAULT NULL,
                penalty_order INTEGER DEFAULT NULL
            )
        ");
    }

    private function insertTestData(): void
    {
        // Insert clubs
        $this->db->getPdo()->exec("
            INSERT INTO clubs (id, name, short_name) VALUES
            (1, 'Arsenal', 'ARS'),
            (2, 'Chelsea', 'CHE')
        ");

        // Insert players
        // Position: 1=GK, 2=DEF, 3=MID, 4=FWD
        $this->db->getPdo()->exec("
            INSERT INTO players (id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form, selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists, ict_index, bps, bonus, starts, chance_of_playing, news)
            VALUES
            (1, 101, 'Saka', 'Bukayo', 'Saka', 1, 3, 90, 150, 8.5, 45.2, 2000, 10, 8, 0, 9.0, 7.0, 250.0, 500, 15, 22, 100, NULL),
            (2, 102, 'Raya', 'David', 'Raya', 1, 1, 55, 120, 6.0, 30.0, 1800, 0, 0, 12, 0.0, 0.0, 50.0, 400, 5, 20, 100, NULL),
            (3, 103, 'Palmer', 'Cole', 'Palmer', 2, 3, 105, 180, 9.5, 60.0, 2200, 15, 10, 0, 14.0, 9.0, 300.0, 600, 20, 24, 75, 'Minor knock'),
            (4, 104, 'Jackson', 'Nicolas', 'Jackson', 2, 4, 75, 100, 5.0, 20.0, 1500, 8, 3, 0, 10.0, 2.0, 150.0, 300, 8, 18, 100, NULL)
        ");
    }

    public function testGetAllPlayers(): void
    {
        $players = $this->service->getAll();

        $this->assertCount(4, $players);
    }

    public function testGetAllPlayersOrderedByTotalPoints(): void
    {
        $players = $this->service->getAll();

        // Highest points first
        $this->assertEquals('Palmer', $players[0]['web_name']);
        $this->assertEquals('Saka', $players[1]['web_name']);
    }

    public function testGetAllPlayersFilterByPosition(): void
    {
        $midfielders = $this->service->getAll(['position' => 3]);

        $this->assertCount(2, $midfielders);
        foreach ($midfielders as $player) {
            $this->assertEquals(3, $player['element_type']);
        }
    }

    public function testGetAllPlayersFilterByTeam(): void
    {
        $arsenalPlayers = $this->service->getAll(['team' => 1]);

        $this->assertCount(2, $arsenalPlayers);
        foreach ($arsenalPlayers as $player) {
            $this->assertEquals(1, $player['team']);
        }
    }

    public function testGetAllPlayersFilterByPositionAndTeam(): void
    {
        $arsenalMidfielders = $this->service->getAll(['position' => 3, 'team' => 1]);

        $this->assertCount(1, $arsenalMidfielders);
        $this->assertEquals('Saka', $arsenalMidfielders[0]['web_name']);
    }

    public function testGetPlayerById(): void
    {
        $player = $this->service->getById(1);

        $this->assertNotNull($player);
        $this->assertEquals('Saka', $player['web_name']);
        $this->assertEquals('Bukayo', $player['first_name']);
        $this->assertEquals(1, $player['team']);
        $this->assertEquals(3, $player['element_type']);
    }

    public function testGetPlayerByIdNotFound(): void
    {
        $player = $this->service->getById(999);

        $this->assertNull($player);
    }

    public function testPlayerContainsExpectedFields(): void
    {
        $player = $this->service->getById(1);

        $expectedFields = [
            'id', 'code', 'web_name', 'first_name', 'second_name',
            'team', 'element_type', 'now_cost', 'total_points', 'form',
            'selected_by_percent', 'minutes', 'goals_scored', 'assists',
            'clean_sheets', 'expected_goals', 'expected_assists', 'ict_index',
            'bps', 'bonus', 'starts', 'chance_of_playing_next_round', 'news'
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $player, "Missing field: $field");
        }
    }

    public function testGetAllTeams(): void
    {
        $teams = $this->service->getAllTeams();

        $this->assertCount(2, $teams);
        $this->assertEquals('Arsenal', $teams[0]['name']);
        $this->assertEquals('ARS', $teams[0]['short_name']);
    }

    public function testDeprecatedMethods(): void
    {
        // Test deprecated methods still work
        $allPlayers = $this->service->getAllPlayers();
        $this->assertCount(4, $allPlayers);

        $player = $this->service->getPlayer(1);
        $this->assertEquals('Saka', $player['web_name']);
    }
}
