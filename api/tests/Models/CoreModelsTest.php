<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Models;

use Maia\Core\Testing\TestCase;
use SuperFPL\Api\Models\Club;
use SuperFPL\Api\Models\Fixture;
use SuperFPL\Api\Models\League;
use SuperFPL\Api\Models\LeagueMember;
use SuperFPL\Api\Models\Manager;
use SuperFPL\Api\Models\ManagerHistory;
use SuperFPL\Api\Models\ManagerPick;
use SuperFPL\Api\Models\Player;

class CoreModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db()->execute('CREATE TABLE clubs (
            id INTEGER PRIMARY KEY,
            name TEXT,
            short_name TEXT,
            strength_attack_home INTEGER,
            strength_attack_away INTEGER,
            strength_defence_home INTEGER,
            strength_defence_away INTEGER
        )');

        $this->db()->execute('CREATE TABLE players (
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
            expected_goals_conceded REAL,
            ict_index REAL,
            bps INTEGER,
            bonus INTEGER,
            starts INTEGER,
            chance_of_playing INTEGER,
            news TEXT,
            penalties_order INTEGER,
            penalties_taken INTEGER,
            defensive_contribution INTEGER,
            defensive_contribution_per_90 REAL,
            saves INTEGER,
            appearances INTEGER,
            yellow_cards INTEGER,
            red_cards INTEGER,
            own_goals INTEGER,
            penalties_missed INTEGER,
            penalties_saved INTEGER,
            goals_conceded INTEGER,
            updated_at TEXT,
            understat_id INTEGER,
            npxg REAL,
            npg INTEGER,
            understat_shots INTEGER,
            understat_key_passes INTEGER,
            xg_chain REAL,
            xg_buildup REAL,
            understat_xa REAL,
            xmins_override INTEGER,
            penalty_order INTEGER
        )');

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

        $this->db()->execute('CREATE TABLE managers (
            id INTEGER PRIMARY KEY,
            name TEXT,
            team_name TEXT,
            overall_rank INTEGER,
            overall_points INTEGER,
            last_synced TEXT
        )');

        $this->db()->execute('CREATE TABLE manager_picks (
            manager_id INTEGER,
            gameweek INTEGER,
            player_id INTEGER,
            position INTEGER,
            multiplier INTEGER,
            is_captain INTEGER,
            is_vice_captain INTEGER,
            PRIMARY KEY (manager_id, gameweek, player_id)
        )');

        $this->db()->execute('CREATE TABLE manager_history (
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

        $this->db()->execute('CREATE TABLE leagues (
            id INTEGER PRIMARY KEY,
            name TEXT,
            type TEXT,
            last_synced TEXT
        )');

        $this->db()->execute('CREATE TABLE league_members (
            league_id INTEGER,
            manager_id INTEGER,
            rank INTEGER,
            PRIMARY KEY (league_id, manager_id)
        )');

        $this->seedCoreData();
    }

    public function testClubFindAndPlayerQueryWork(): void
    {
        $club = Club::find(1);

        $this->assertInstanceOf(Club::class, $club);
        $this->assertSame('Arsenal', $club->name);
        $this->assertSame('ARS', $club->short_name);

        $players = Player::query()->where('club_id', 1)->orderBy('id')->get();

        $this->assertCount(2, $players);
        $this->assertSame('Saka', $players[0]->web_name);
    }

    public function testPlayerBelongsToClub(): void
    {
        $player = Player::find(10);

        $this->assertInstanceOf(Player::class, $player);
        $this->assertSame('Saka', $player->web_name);
        $this->assertSame('Arsenal', $player->club->name);
        $this->assertSame(95, $player->now_cost);
    }

    public function testFixtureBelongsToHomeAndAwayClubs(): void
    {
        $fixture = Fixture::find(100);

        $this->assertInstanceOf(Fixture::class, $fixture);
        $this->assertTrue($fixture->finished);
        $this->assertSame('Arsenal', $fixture->homeClub->name);
        $this->assertSame('Chelsea', $fixture->awayClub->name);
    }

    public function testManagerRelationsLoadFromCompositeKeyTables(): void
    {
        $manager = Manager::find(200);

        $this->assertInstanceOf(Manager::class, $manager);
        $this->assertCount(2, $manager->picks);
        $this->assertCount(2, $manager->history);
        $this->assertSame('Saka', $manager->picks[0]->player->web_name);
        $this->assertSame(52, $manager->history[0]->points);
    }

    public function testManagerPickAndHistoryCanBeQueriedWithoutFind(): void
    {
        $pick = ManagerPick::query()
            ->where('manager_id', 200)
            ->where('gameweek', 1)
            ->where('player_id', 10)
            ->first();

        $history = ManagerHistory::query()
            ->where('manager_id', 200)
            ->where('gameweek', 2)
            ->first();

        $this->assertInstanceOf(ManagerPick::class, $pick);
        $this->assertTrue($pick->is_captain);
        $this->assertSame('Mal', $pick->manager->name);
        $this->assertSame('Saka', $pick->player->web_name);

        $this->assertInstanceOf(ManagerHistory::class, $history);
        $this->assertSame(48, $history->points);
        $this->assertSame('Mal', $history->manager->name);
    }

    public function testLeagueMembershipRelationsLoad(): void
    {
        $league = League::find(300);
        $member = LeagueMember::query()->where('league_id', 300)->where('manager_id', 200)->first();

        $this->assertInstanceOf(League::class, $league);
        $this->assertCount(1, $league->members);
        $this->assertSame(1, $league->members[0]->rank);

        $this->assertInstanceOf(LeagueMember::class, $member);
        $this->assertSame('Mini League', $member->league->name);
        $this->assertSame('Mal', $member->manager->name);
    }

    private function seedCoreData(): void
    {
        $this->db()->execute(
            "INSERT INTO clubs (id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away)
             VALUES (1, 'Arsenal', 'ARS', 1300, 1250, 1350, 1300)"
        );
        $this->db()->execute(
            "INSERT INTO clubs (id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away)
             VALUES (2, 'Chelsea', 'CHE', 1200, 1180, 1220, 1210)"
        );

        $this->db()->execute(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists,
                expected_goals_conceded, ict_index, bps, bonus, starts, chance_of_playing, news, penalties_order,
                penalties_taken, defensive_contribution, defensive_contribution_per_90, saves, appearances,
                yellow_cards, red_cards, own_goals, penalties_missed, penalties_saved, goals_conceded, updated_at,
                understat_id, npxg, npg, understat_shots, understat_key_passes, xg_chain, xg_buildup, understat_xa,
                xmins_override, penalty_order
            ) VALUES (
                10, 10010, 'Saka', 'Bukayo', 'Saka', 1, 3, 95, 180, 6.5,
                25.1, 2100, 12, 10, 8, 9.3, 8.1,
                20.0, 145.6, 410, 18, 24, 100, '', 1,
                4, 33, 1.41, 0, 25,
                3, 0, 0, 0, 0, 21, '2026-02-28T10:00:00Z',
                501, 8.2, 11, 60, 55, 12.2, 9.1, 7.4,
                88, 1
            )"
        );
        $this->db()->execute(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists,
                expected_goals_conceded, ict_index, bps, bonus, starts, chance_of_playing, news, penalties_order,
                penalties_taken, defensive_contribution, defensive_contribution_per_90, saves, appearances,
                yellow_cards, red_cards, own_goals, penalties_missed, penalties_saved, goals_conceded, updated_at,
                understat_id, npxg, npg, understat_shots, understat_key_passes, xg_chain, xg_buildup, understat_xa,
                xmins_override, penalty_order
            ) VALUES (
                11, 10011, 'Gabriel', 'Gabriel', 'Magalhaes', 1, 2, 62, 130, 4.9,
                18.4, 2250, 4, 1, 11, 3.1, 0.9,
                18.7, 101.2, 390, 9, 25, 100, '', 0,
                0, 61, 2.44, 0, 25,
                5, 0, 0, 0, 0, 18, '2026-02-28T10:00:00Z',
                502, 2.9, 4, 32, 10, 6.7, 5.1, 1.1,
                NULL, NULL
            )"
        );
        $this->db()->execute(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists,
                expected_goals_conceded, ict_index, bps, bonus, starts, chance_of_playing, news, penalties_order,
                penalties_taken, defensive_contribution, defensive_contribution_per_90, saves, appearances,
                yellow_cards, red_cards, own_goals, penalties_missed, penalties_saved, goals_conceded, updated_at,
                understat_id, npxg, npg, understat_shots, understat_key_passes, xg_chain, xg_buildup, understat_xa,
                xmins_override, penalty_order
            ) VALUES (
                20, 10020, 'Palmer', 'Cole', 'Palmer', 2, 3, 108, 192, 7.2,
                38.5, 2050, 15, 9, 5, 11.5, 7.8,
                25.0, 160.5, 430, 20, 23, 100, '', 1,
                7, 24, 1.05, 0, 24,
                4, 0, 0, 1, 0, 28, '2026-02-28T10:00:00Z',
                601, 10.8, 14, 71, 49, 13.4, 10.7, 6.8,
                90, 1
            )"
        );

        $this->db()->execute(
            "INSERT INTO fixtures (id, gameweek, home_club_id, away_club_id, kickoff_time, home_score, away_score, home_difficulty, away_difficulty, finished)
             VALUES (100, 27, 1, 2, '2026-03-01T15:00:00Z', 2, 1, 2, 4, 1)"
        );

        $this->db()->execute(
            "INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced)
             VALUES (200, 'Mal', 'Expected FC', 12345, 1800, '2026-02-28T10:00:00Z')"
        );

        $this->db()->execute(
            'INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain)
             VALUES (200, 1, 10, 1, 2, 1, 0)'
        );
        $this->db()->execute(
            'INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain)
             VALUES (200, 1, 11, 2, 1, 0, 1)'
        );

        $this->db()->execute(
            'INSERT INTO manager_history (manager_id, gameweek, points, total_points, overall_rank, bank, team_value, transfers_cost, points_on_bench)
             VALUES (200, 1, 52, 1752, 14567, 15, 1025, 4, 8)'
        );
        $this->db()->execute(
            'INSERT INTO manager_history (manager_id, gameweek, points, total_points, overall_rank, bank, team_value, transfers_cost, points_on_bench)
             VALUES (200, 2, 48, 1800, 12345, 10, 1028, 0, 6)'
        );

        $this->db()->execute(
            "INSERT INTO leagues (id, name, type, last_synced)
             VALUES (300, 'Mini League', 'classic', '2026-02-28T10:00:00Z')"
        );
        $this->db()->execute(
            'INSERT INTO league_members (league_id, manager_id, rank) VALUES (300, 200, 1)'
        );
    }
}
