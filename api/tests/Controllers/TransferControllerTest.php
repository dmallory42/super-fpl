<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Http\Request;
use Maia\Core\Testing\TestCase;
use Maia\Core\Testing\TestResponse;
use Maia\Orm\Connection;
use SuperFPL\Api\Controllers\TransferController;
use SuperFPL\Api\Tests\Support\TestDatabase;
use SuperFPL\Api\Tests\Support\FakeFplClient;
use SuperFPL\FplClient\FplClient;

require_once __DIR__ . '/../Support/FakeFplClient.php';

class TransferControllerTest extends TestCase
{
    private TestDatabase $database;

    protected function controllers(): array
    {
        return [TransferController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new TestDatabase(':memory:');
        $this->app->container()->instance(Connection::class, $this->database);
        $this->app->container()->instance(FplClient::class, new FakeFplClient());

        $this->database->query('CREATE TABLE players (
            id INTEGER PRIMARY KEY,
            web_name TEXT,
            club_id INTEGER,
            position INTEGER,
            now_cost INTEGER,
            form REAL,
            chance_of_playing INTEGER,
            news TEXT,
            total_points INTEGER
        )');
        $this->database->query('CREATE TABLE player_predictions (
            player_id INTEGER,
            gameweek INTEGER,
            predicted_points REAL,
            confidence REAL,
            PRIMARY KEY (player_id, gameweek)
        )');

        $this->database->query(
            "INSERT INTO players (id, web_name, club_id, position, now_cost, form, chance_of_playing, news, total_points)
             VALUES (10, 'Saka', 1, 3, 95, 6.5, 100, '', 180)"
        );
        $this->database->query(
            "INSERT INTO players (id, web_name, club_id, position, now_cost, form, chance_of_playing, news, total_points)
             VALUES (20, 'Haaland', 2, 4, 140, 7.8, 100, '', 210)"
        );
        $this->database->query(
            'INSERT INTO player_predictions (player_id, gameweek, predicted_points, confidence)
             VALUES (10, 27, 7.5, 0.8)'
        );
        $this->database->query(
            'INSERT INTO player_predictions (player_id, gameweek, predicted_points, confidence)
             VALUES (20, 27, 8.8, 0.9)'
        );
    }

    public function testSuggestReturnsValidationErrorWhenManagerMissing(): void
    {
        $response = $this->get('/transfers/suggest');

        $response->assertStatus(400);
        $this->assertSame('Missing manager parameter', $response->json()['error'] ?? null);
    }

    public function testSimulateReturnsValidationErrorWhenParamsMissing(): void
    {
        $response = $this->get('/transfers/simulate');

        $response->assertStatus(400);
        $this->assertSame('Missing parameters: manager, out, in required', $response->json()['error'] ?? null);
    }

    public function testTargetsReturnsEnvelope(): void
    {
        $response = $this->send(new Request('GET', '/transfers/targets', ['gw' => '27']));

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(27, $json['gameweek'] ?? null);
        $this->assertArrayHasKey('targets', $json);
        $this->assertNotEmpty($json['targets']);
    }

    public function testPlannerOptimizeReturnsValidationErrorWhenManagerMissing(): void
    {
        $response = $this->get('/planner/optimize');

        $response->assertStatus(400);
        $this->assertSame('Missing manager parameter', $response->json()['error'] ?? null);
    }

    public function testPlannerChipSuggestReturnsValidationErrorWhenManagerMissing(): void
    {
        $response = $this->get('/planner/chips/suggest');

        $response->assertStatus(400);
        $this->assertSame('Missing manager parameter', $response->json()['error'] ?? null);
    }

    private function send(Request $request): TestResponse
    {
        return new TestResponse($this->app->handle($request), $this);
    }
}
