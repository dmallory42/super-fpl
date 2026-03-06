<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Testing\TestCase;
use SuperFPL\Api\Controllers\ManagerController;
use SuperFPL\Api\Database;
use SuperFPL\Api\Tests\Support\FakeFplClient;
use SuperFPL\FplClient\FplClient;

require_once __DIR__ . '/../Support/FakeFplClient.php';

class ManagerControllerTest extends TestCase
{
    private Database $database;

    protected function controllers(): array
    {
        return [ManagerController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Database(':memory:');
        $this->app->container()->instance(Database::class, $this->database);
        $this->app->container()->instance(FplClient::class, new FakeFplClient());

        $this->database->query('CREATE TABLE managers (
            id INTEGER PRIMARY KEY,
            name TEXT,
            team_name TEXT,
            overall_rank INTEGER,
            overall_points INTEGER,
            last_synced TEXT
        )');
        $this->database->query('CREATE TABLE manager_picks (
            manager_id INTEGER,
            gameweek INTEGER,
            player_id INTEGER,
            position INTEGER,
            multiplier INTEGER,
            is_captain INTEGER,
            is_vice_captain INTEGER,
            PRIMARY KEY (manager_id, gameweek, player_id)
        )');
        $this->database->query('CREATE TABLE manager_history (
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
        )');

        $this->database->query(
            "INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced)
             VALUES (100, 'Mal Test', 'Expected FC', 1000, 1800, '2026-03-01T00:00:00Z')"
        );
        $this->database->query(
            'INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain)
             VALUES (100, 27, 10, 1, 2, 1, 0)'
        );
        $this->database->query(
            'INSERT INTO manager_history (manager_id, gameweek, points, total_points, overall_rank, bank, team_value, transfers_cost, points_on_bench)
             VALUES (100, 27, 62, 1800, 1000, 12, 1020, 4, 7)'
        );
    }

    public function testShowReturnsCachedManagerWhenApiUnavailable(): void
    {
        $response = $this->get('/managers/100');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(100, $json['id'] ?? null);
        $this->assertSame('Expected FC', $json['team_name'] ?? null);
    }

    public function testPicksEndpointReturnsCachedPicks(): void
    {
        $response = $this->get('/managers/100/picks/27');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayHasKey('picks', $json);
        $this->assertCount(1, $json['picks']);
    }

    public function testHistoryEndpointReturnsCachedHistory(): void
    {
        $response = $this->get('/managers/100/history');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayHasKey('current', $json);
        $this->assertCount(1, $json['current']);
    }

    public function testSeasonAnalysisReturnsNotFoundWithoutSufficientData(): void
    {
        $response = $this->get('/managers/999/season-analysis');

        $response->assertStatus(404);
        $this->assertSame('Season analysis not found', $response->json()['error'] ?? null);
    }
}
