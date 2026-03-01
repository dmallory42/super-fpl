<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Models;

use Maia\Core\Testing\TestCase;
use SuperFPL\Api\Models\AssistOdds;
use SuperFPL\Api\Models\FixtureOdds;
use SuperFPL\Api\Models\GameweekHistory;
use SuperFPL\Api\Models\GoalscorerOdds;
use SuperFPL\Api\Models\PlayerPrediction;
use SuperFPL\Api\Models\PlayerSeasonHistory;
use SuperFPL\Api\Models\PredictionSnapshot;
use SuperFPL\Api\Models\SamplePick;

class DataModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db()->execute('CREATE TABLE players (
            id INTEGER PRIMARY KEY,
            code INTEGER,
            web_name TEXT,
            club_id INTEGER
        )');

        $this->db()->execute('CREATE TABLE fixtures (
            id INTEGER PRIMARY KEY,
            gameweek INTEGER,
            home_club_id INTEGER,
            away_club_id INTEGER,
            kickoff_time TEXT,
            finished INTEGER
        )');

        $this->db()->execute('CREATE TABLE managers (
            id INTEGER PRIMARY KEY,
            name TEXT
        )');

        $this->db()->execute('CREATE TABLE player_predictions (
            player_id INTEGER,
            gameweek INTEGER,
            predicted_points REAL,
            predicted_if_fit REAL,
            expected_mins REAL,
            expected_mins_if_fit REAL,
            breakdown_json TEXT,
            if_fit_breakdown_json TEXT,
            confidence REAL,
            model_version TEXT,
            computed_at TEXT,
            PRIMARY KEY (player_id, gameweek)
        )');

        $this->db()->execute('CREATE TABLE prediction_snapshots (
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

        $this->db()->execute('CREATE TABLE player_gameweek_history (
            player_id INTEGER,
            gameweek INTEGER,
            fixture_id INTEGER,
            opponent_team INTEGER,
            was_home INTEGER,
            minutes INTEGER,
            goals_scored INTEGER,
            assists INTEGER,
            clean_sheets INTEGER,
            goals_conceded INTEGER,
            bonus INTEGER,
            bps INTEGER,
            total_points INTEGER,
            expected_goals REAL,
            expected_assists REAL,
            expected_goals_conceded REAL,
            value INTEGER,
            selected INTEGER,
            PRIMARY KEY (player_id, gameweek)
        )');

        $this->db()->execute('CREATE TABLE fixture_odds (
            fixture_id INTEGER PRIMARY KEY,
            home_win_prob REAL,
            draw_prob REAL,
            away_win_prob REAL,
            home_cs_prob REAL,
            away_cs_prob REAL,
            expected_total_goals REAL,
            line_count INTEGER,
            updated_at TEXT
        )');

        $this->db()->execute('CREATE TABLE player_goalscorer_odds (
            player_id INTEGER,
            fixture_id INTEGER,
            anytime_scorer_prob REAL,
            line_count INTEGER,
            updated_at TEXT,
            PRIMARY KEY (player_id, fixture_id)
        )');

        $this->db()->execute('CREATE TABLE player_assist_odds (
            player_id INTEGER,
            fixture_id INTEGER,
            anytime_assist_prob REAL,
            line_count INTEGER,
            updated_at TEXT,
            PRIMARY KEY (player_id, fixture_id)
        )');

        $this->db()->execute('CREATE TABLE sample_picks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gameweek INTEGER,
            tier TEXT,
            manager_id INTEGER,
            player_id INTEGER,
            multiplier INTEGER,
            created_at TEXT
        )');

        $this->db()->execute('CREATE TABLE player_season_history (
            player_code INTEGER,
            season_id TEXT,
            total_points INTEGER,
            minutes INTEGER,
            goals_scored INTEGER,
            assists INTEGER,
            clean_sheets INTEGER,
            expected_goals REAL,
            expected_assists REAL,
            expected_goals_conceded REAL,
            starts INTEGER,
            start_cost INTEGER,
            end_cost INTEGER,
            PRIMARY KEY (player_code, season_id)
        )');

        $this->seedData();
    }

    public function testPlayerPredictionLoadsPlayerRelation(): void
    {
        $prediction = PlayerPrediction::query()
            ->where('player_id', 10)
            ->where('gameweek', 27)
            ->first();

        $this->assertInstanceOf(PlayerPrediction::class, $prediction);
        $this->assertSame(7.4, $prediction->predicted_points);
        $this->assertSame('Saka', $prediction->player->web_name);
    }

    public function testPredictionSnapshotLoadsPlayerRelation(): void
    {
        $snapshot = PredictionSnapshot::query()
            ->where('player_id', 10)
            ->where('gameweek', 27)
            ->first();

        $this->assertInstanceOf(PredictionSnapshot::class, $snapshot);
        $this->assertSame('manual', $snapshot->snapshot_source);
        $this->assertTrue($snapshot->is_pre_deadline);
        $this->assertSame('Saka', $snapshot->player->web_name);
    }

    public function testGameweekHistoryLoadsPlayerAndFixture(): void
    {
        $history = GameweekHistory::query()
            ->where('player_id', 10)
            ->where('gameweek', 27)
            ->first();

        $this->assertInstanceOf(GameweekHistory::class, $history);
        $this->assertSame(12, $history->total_points);
        $this->assertSame('Saka', $history->player->web_name);
        $this->assertSame(27, $history->fixture->gameweek);
    }

    public function testFixtureOddsUsesFixtureIdPrimaryKey(): void
    {
        $odds = FixtureOdds::find(100);

        $this->assertInstanceOf(FixtureOdds::class, $odds);
        $this->assertSame(0.58, $odds->home_win_prob);
        $this->assertSame(27, $odds->fixture->gameweek);
    }

    public function testGoalscorerAndAssistOddsLoadRelations(): void
    {
        $goalscorerOdds = GoalscorerOdds::query()
            ->where('player_id', 10)
            ->where('fixture_id', 100)
            ->first();
        $assistOdds = AssistOdds::query()
            ->where('player_id', 10)
            ->where('fixture_id', 100)
            ->first();

        $this->assertInstanceOf(GoalscorerOdds::class, $goalscorerOdds);
        $this->assertSame(0.42, $goalscorerOdds->anytime_scorer_prob);
        $this->assertSame('Saka', $goalscorerOdds->player->web_name);

        $this->assertInstanceOf(AssistOdds::class, $assistOdds);
        $this->assertSame(0.31, $assistOdds->anytime_assist_prob);
        $this->assertSame(100, $assistOdds->fixture->id);
    }

    public function testSamplePickAndSeasonHistoryQueriesWork(): void
    {
        $samplePick = SamplePick::find(1);
        $seasonHistory = PlayerSeasonHistory::query()
            ->where('player_code', 10010)
            ->where('season_id', '2025-26')
            ->first();

        $this->assertInstanceOf(SamplePick::class, $samplePick);
        $this->assertSame('top_10k', $samplePick->tier);
        $this->assertSame('Saka', $samplePick->player->web_name);
        $this->assertSame('Mal', $samplePick->manager->name);

        $this->assertInstanceOf(PlayerSeasonHistory::class, $seasonHistory);
        $this->assertSame(220, $seasonHistory->total_points);
        $this->assertSame(10010, $seasonHistory->player_code);
    }

    private function seedData(): void
    {
        $this->db()->execute("INSERT INTO players (id, code, web_name, club_id) VALUES (10, 10010, 'Saka', 1)");
        $this->db()->execute("INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, finished) VALUES (100, 27, 1, 2, '2026-03-01T15:00:00Z', 1)");
        $this->db()->execute("INSERT INTO managers (id, name) VALUES (200, 'Mal')");

        $this->db()->execute(
            "INSERT INTO player_predictions (
                player_id, gameweek, predicted_points, predicted_if_fit, expected_mins, expected_mins_if_fit,
                breakdown_json, if_fit_breakdown_json, confidence, model_version, computed_at
            ) VALUES (
                10, 27, 7.4, 7.8, 84.0, 88.0,
                '{\"goals\": 3.2}', '{\"goals\": 3.4}', 0.82, 'v2.0', '2026-02-28T18:00:00Z'
            )"
        );

        $this->db()->execute(
            "INSERT INTO prediction_snapshots (
                player_id, gameweek, predicted_points, confidence, breakdown, model_version, snapshot_source, is_pre_deadline, snapped_at
            ) VALUES (
                10, 27, 7.4, 0.82, '{\"goals\": 3.2}', 'v2.0', 'manual', 1, '2026-02-28T18:05:00Z'
            )"
        );

        $this->db()->execute(
            "INSERT INTO player_gameweek_history (
                player_id, gameweek, fixture_id, opponent_team, was_home, minutes, goals_scored, assists, clean_sheets,
                goals_conceded, bonus, bps, total_points, expected_goals, expected_assists, expected_goals_conceded, value, selected
            ) VALUES (
                10, 27, 100, 2, 1, 90, 1, 1, 0,
                1, 3, 42, 12, 0.8, 0.4, 1.1, 95, 500000
            )"
        );

        $this->db()->execute(
            "INSERT INTO fixture_odds (
                fixture_id, home_win_prob, draw_prob, away_win_prob, home_cs_prob, away_cs_prob, expected_total_goals, line_count, updated_at
            ) VALUES (
                100, 0.58, 0.24, 0.18, 0.41, 0.22, 2.7, 12, '2026-02-28T18:00:00Z'
            )"
        );

        $this->db()->execute(
            "INSERT INTO player_goalscorer_odds (player_id, fixture_id, anytime_scorer_prob, line_count, updated_at)
             VALUES (10, 100, 0.42, 8, '2026-02-28T18:00:00Z')"
        );

        $this->db()->execute(
            "INSERT INTO player_assist_odds (player_id, fixture_id, anytime_assist_prob, line_count, updated_at)
             VALUES (10, 100, 0.31, 7, '2026-02-28T18:00:00Z')"
        );

        $this->db()->execute(
            "INSERT INTO sample_picks (id, gameweek, tier, manager_id, player_id, multiplier, created_at)
             VALUES (1, 27, 'top_10k', 200, 10, 2, '2026-02-28T18:00:00Z')"
        );

        $this->db()->execute(
            "INSERT INTO player_season_history (
                player_code, season_id, total_points, minutes, goals_scored, assists, clean_sheets,
                expected_goals, expected_assists, expected_goals_conceded, starts, start_cost, end_cost
            ) VALUES (
                10010, '2025-26', 220, 2800, 18, 14, 9,
                16.1, 12.3, 28.4, 31, 85, 105
            )"
        );
    }
}
