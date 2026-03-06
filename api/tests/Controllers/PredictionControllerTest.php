<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Http\Request;
use Maia\Core\Testing\TestCase;
use Maia\Core\Testing\TestResponse;
use Maia\Orm\Connection;
use SuperFPL\Api\Controllers\PredictionController;
use SuperFPL\Api\Tests\Support\TestDatabase;

class PredictionControllerTest extends TestCase
{
    private TestDatabase $database;

    protected function controllers(): array
    {
        return [PredictionController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new TestDatabase(':memory:');
        $this->app->container()->instance(Connection::class, $this->database);

        $futureKickoff = gmdate('Y-m-d\\TH:i:s\\Z', time() + 3600);

        $this->database->query('CREATE TABLE fixtures (
            id INTEGER PRIMARY KEY,
            gameweek INTEGER,
            home_club_id INTEGER,
            away_club_id INTEGER,
            kickoff_time TEXT,
            finished INTEGER,
            home_score INTEGER,
            away_score INTEGER
        )');
        $this->database->query('CREATE TABLE clubs (
            id INTEGER PRIMARY KEY,
            name TEXT,
            short_name TEXT
        )');
        $this->database->query('CREATE TABLE players (
            id INTEGER PRIMARY KEY,
            code INTEGER,
            web_name TEXT,
            club_id INTEGER,
            position INTEGER,
            now_cost INTEGER,
            form REAL,
            total_points INTEGER,
            chance_of_playing INTEGER,
            news TEXT,
            expected_goals REAL,
            expected_assists REAL,
            expected_goals_conceded REAL,
            understat_id INTEGER
        )');
        $this->database->query('CREATE TABLE player_predictions (
            player_id INTEGER,
            gameweek INTEGER,
            predicted_points REAL,
            predicted_if_fit REAL,
            expected_mins REAL,
            expected_mins_if_fit REAL,
            if_fit_breakdown_json TEXT,
            confidence REAL,
            model_version TEXT,
            computed_at TEXT,
            breakdown_json TEXT,
            PRIMARY KEY (player_id, gameweek)
        )');
        $this->database->query('CREATE TABLE prediction_snapshots (
            player_id INTEGER,
            gameweek INTEGER,
            predicted_points REAL,
            confidence REAL,
            breakdown TEXT,
            model_version TEXT,
            snapshot_source TEXT,
            is_pre_deadline INTEGER,
            snapped_at TEXT,
            PRIMARY KEY (player_id, gameweek)
        )');
        $this->database->query('CREATE TABLE player_gameweek_history (
            player_id INTEGER,
            gameweek INTEGER,
            fixture_id INTEGER,
            total_points INTEGER,
            expected_goals REAL,
            was_home INTEGER,
            PRIMARY KEY (player_id, gameweek)
        )');
        $this->database->query('CREATE TABLE understat_season_history (
            understat_id INTEGER,
            season INTEGER,
            minutes INTEGER,
            npxg REAL,
            xa REAL
        )');
        $this->database->query('CREATE TABLE understat_team_season (
            team_name TEXT,
            club_id INTEGER,
            season INTEGER,
            games INTEGER,
            xgf REAL,
            xga REAL
        )');
        $this->database->query('CREATE TABLE player_season_history (
            player_code INTEGER,
            season_id TEXT,
            minutes INTEGER,
            expected_goals REAL,
            expected_assists REAL
        )');

        $this->database->query("INSERT INTO clubs (id, name, short_name) VALUES (1, 'Arsenal', 'ARS')");
        $this->database->query("INSERT INTO clubs (id, name, short_name) VALUES (2, 'Chelsea', 'CHE')");
        $this->database->query(
            "INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, finished, home_score, away_score)
             VALUES (100, 27, 1, 2, ?, 0, NULL, NULL)",
            [$futureKickoff]
        );
        $this->database->query(
            "INSERT INTO players (
                id, code, web_name, club_id, position, now_cost, form, total_points, chance_of_playing, news,
                expected_goals, expected_assists, expected_goals_conceded, understat_id
            ) VALUES (
                10, 10010, 'Saka', 1, 3, 95, 6.5, 180, 100, '',
                9.3, 8.1, 24.0, 501
            )"
        );
        $this->database->query(
            "INSERT INTO player_predictions (
                player_id, gameweek, predicted_points, predicted_if_fit, expected_mins, expected_mins_if_fit,
                if_fit_breakdown_json, confidence, model_version, computed_at, breakdown_json
            ) VALUES (
                10, 27, 7.4, 7.8, 84.0, 88.0, '{\"goals\": 3.2}', 0.8, 'v2.0', datetime('now'), '{}'
            )"
        );
    }

    public function testIndexReturnsPredictionsEnvelope(): void
    {
        $response = $this->get('/predictions/27');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(27, $json['gameweek'] ?? null);
        $this->assertArrayHasKey('predictions', $json);
    }

    public function testAccuracyReturns404WhenNoActualData(): void
    {
        $response = $this->get('/predictions/27/accuracy');

        $response->assertStatus(404);
        $this->assertSame('No accuracy data available for this gameweek', $response->json()['error'] ?? null);
    }

    public function testRangeReturnsPlayersAndFixtures(): void
    {
        $response = $this->send(new Request('GET', '/predictions/range', ['start' => '27', 'end' => '27']));

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertNotEmpty($json['gameweeks'] ?? []);
        $this->assertArrayHasKey('players', $json);
        $this->assertArrayHasKey('fixtures', $json);
    }

    public function testMethodologyReturnsDocumentationPayload(): void
    {
        $response = $this->get('/predictions/methodology');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayHasKey('version', $json);
    }

    private function send(Request $request): TestResponse
    {
        return new TestResponse($this->app->handle($request), $this);
    }
}
