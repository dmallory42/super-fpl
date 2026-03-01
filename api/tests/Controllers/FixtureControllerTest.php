<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Http\Request;
use Maia\Core\Testing\TestCase;
use Maia\Core\Testing\TestResponse;
use Maia\Orm\Connection;
use SuperFPL\Api\Controllers\FixtureController;

class FixtureControllerTest extends TestCase
{
    protected function controllers(): array
    {
        return [FixtureController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->container()->instance(Connection::class, $this->db());

        $this->db()->execute('CREATE TABLE fixtures (
            id INTEGER PRIMARY KEY,
            gameweek INTEGER,
            home_club_id INTEGER,
            away_club_id INTEGER,
            kickoff_time TEXT,
            home_score INTEGER,
            away_score INTEGER,
            home_difficulty INTEGER,
            away_difficulty INTEGER,
            finished INTEGER
        )');

        $this->db()->execute(
            "INSERT INTO fixtures (
                id, gameweek, home_club_id, away_club_id, kickoff_time, home_score, away_score, home_difficulty, away_difficulty, finished
            ) VALUES (
                100, 27, 1, 2, '2026-02-28T15:00:00Z', 2, 1, 2, 4, 1
            )"
        );
        $this->db()->execute(
            "INSERT INTO fixtures (
                id, gameweek, home_club_id, away_club_id, kickoff_time, home_score, away_score, home_difficulty, away_difficulty, finished
            ) VALUES (
                101, 28, 2, 1, '2026-03-02T20:00:00Z', NULL, NULL, 3, 3, 0
            )"
        );
    }

    public function testFixturesEndpointReturnsFixtureList(): void
    {
        $response = $this->get('/fixtures');

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(2, $json['fixtures']);
        $this->assertSame(100, $json['fixtures'][0]['id']);
    }

    public function testFixturesEndpointSupportsGameweekFilter(): void
    {
        $response = $this->send(new Request('GET', '/fixtures', ['gameweek' => '28']));

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(1, $json['fixtures']);
        $this->assertSame(101, $json['fixtures'][0]['id']);
    }

    public function testFixtureStatusEndpointReturnsCurrentGameweekSummary(): void
    {
        $response = $this->get('/fixtures/status');

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertArrayHasKey('current_gameweek', $json);
        $this->assertArrayHasKey('is_live', $json);
        $this->assertArrayHasKey('gameweeks', $json);
        $this->assertNotEmpty($json['gameweeks']);
    }

    private function send(Request $request): TestResponse
    {
        return new TestResponse($this->app->handle($request), $this);
    }
}
