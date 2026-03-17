<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Middleware\ResponseCacheMiddleware;
use Maia\Core\Testing\TestCase;
use Maia\Orm\Connection;
use SuperFPL\Api\Cache\NullResponseCacheStore;
use SuperFPL\Api\Controllers\LeagueController;
use SuperFPL\Api\Tests\Support\TestDatabase;
use SuperFPL\Api\Tests\Support\FakeFplClient;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\ParallelHttpClient;

require_once __DIR__ . '/../Support/FakeFplClient.php';

class LeagueControllerTest extends TestCase
{
    private TestDatabase $database;

    protected function controllers(): array
    {
        return [LeagueController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new TestDatabase(':memory:');
        $this->app->container()->instance(Connection::class, $this->database);
        $this->app->container()->instance(FplClient::class, new FakeFplClient());
        $this->app->container()->instance(
            ResponseCacheMiddleware::class,
            new ResponseCacheMiddleware(new NullResponseCacheStore())
        );
        $this->app->container()->instance(
            ParallelHttpClient::class,
            new ParallelHttpClient('https://fantasy.premierleague.com/api/')
        );

        $this->database->query('CREATE TABLE leagues (
            id INTEGER PRIMARY KEY,
            name TEXT,
            type TEXT,
            last_synced TEXT
        )');
        $this->database->query('CREATE TABLE league_members (
            league_id INTEGER,
            manager_id INTEGER,
            rank INTEGER,
            PRIMARY KEY (league_id, manager_id)
        )');
        $this->database->query('CREATE TABLE managers (
            id INTEGER PRIMARY KEY,
            name TEXT,
            team_name TEXT,
            overall_rank INTEGER,
            overall_points INTEGER,
            last_synced TEXT
        )');
        $this->database->query('CREATE TABLE fixtures (
            id INTEGER PRIMARY KEY,
            gameweek INTEGER,
            kickoff_time TEXT,
            finished INTEGER,
            home_club_id INTEGER,
            away_club_id INTEGER
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

        $this->database->query(
            "INSERT INTO leagues (id, name, type, last_synced) VALUES (10, 'Mini League', 'classic', '2026-03-01T00:00:00Z')"
        );
        $this->database->query(
            "INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced)
             VALUES (100, 'Mal Test', 'Expected FC', 1, 2000, '2026-03-01T00:00:00Z')"
        );
        $this->database->query(
            "INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced)
             VALUES (101, 'Alex Test', 'Variance FC', 2, 1990, '2026-03-01T00:00:00Z')"
        );
        $this->database->query('INSERT INTO league_members (league_id, manager_id, rank) VALUES (10, 100, 1)');
        $this->database->query('INSERT INTO league_members (league_id, manager_id, rank) VALUES (10, 101, 2)');
        $this->database->query(
            "INSERT INTO fixtures (id, gameweek, kickoff_time, finished, home_club_id, away_club_id)
             VALUES (1, 27, '2026-03-01T15:00:00Z', 0, 1, 2)"
        );
    }

    public function testShowReturnsCachedLeaguePayload(): void
    {
        $response = $this->get('/leagues/10');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(10, $json['league']['id'] ?? null);
    }

    public function testStandingsReturnsRows(): void
    {
        $response = $this->get('/leagues/10/standings');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(10, $json['league_id'] ?? null);
        $this->assertCount(2, $json['standings'] ?? []);
    }

    public function testAnalysisRequiresAtLeastTwoManagersFromServiceResults(): void
    {
        $this->database->query('DELETE FROM league_members WHERE manager_id = 101');
        $response = $this->get('/leagues/10/analysis');

        $response->assertStatus(400);
        $this->assertSame('League needs at least 2 managers', $response->json()['error'] ?? null);
    }
}
