<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\TransferService;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;
use SuperFPL\FplClient\FplClient;

class TransferServiceTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Database(':memory:');
        $this->db->init();
        $this->seedClubs(20);
    }

    public function testGetTopTargetsReturnsSortedResults(): void
    {
        $this->insertPlayer(1, 'Saka', 1, 3, 70);
        $this->insertPlayer(2, 'Palmer', 2, 3, 65);
        $this->insertPlayer(3, 'Foden', 3, 3, 60);

        $this->insertPrediction(1, 30, 8.0);
        $this->insertPrediction(2, 30, 6.0);
        $this->insertPrediction(3, 30, 7.0);

        $service = new TransferService($this->db, $this->createMock(FplClient::class));
        $targets = $service->getTopTargets(30, 3, 70);

        $this->assertSame([1, 3, 2], array_column($targets, 'player_id'));
    }

    public function testGetSuggestionsEnforcesBudgetAndTeamLimit(): void
    {
        $this->insertManager(1);

        // 15-player squad: 2 GK, 5 DEF, 5 MID, 3 FWD.
        $squad = [
            [1, 'GK1', 1, 1], [2, 'GK2', 1, 2],
            [3, 'DEF1', 2, 2], [4, 'DEF2', 2, 3], [5, 'DEF3', 2, 4], [6, 'DEF4', 2, 5], [7, 'DEF5', 2, 6],
            [8, 'MID1', 3, 2], [9, 'MID2', 3, 7], [10, 'MID3', 3, 8], [11, 'MID4', 3, 9], [12, 'MID5', 3, 10],
            [13, 'FWD1', 4, 2], [14, 'FWD2', 4, 11], [15, 'FWD3', 4, 12],
        ];
        foreach ($squad as [$id, $name, $pos, $team]) {
            $cost = $id === 12 ? 50 : 55;
            $this->insertPlayer($id, $name, $team, $pos, $cost);
            $this->insertManagerPick(1, 30, $id, $id);
            $this->insertPrediction($id, 30, $id === 12 ? 1.0 : 4.0);
        }

        // Transfer-in candidates (all mids):
        // 201: great points but team 2 already has 3 players in squad => excluded by max-per-team rule.
        $this->insertPlayer(201, 'BlockedByTeamLimit', 2, 3, 50);
        $this->insertPrediction(201, 30, 10.0);
        // 202: valid replacement (within budget, valid team count).
        $this->insertPlayer(202, 'ValidTarget', 3, 3, 50);
        $this->insertPrediction(202, 30, 9.0);
        // 203: best points but over budget => excluded.
        $this->insertPlayer(203, 'OverBudget', 4, 3, 60);
        $this->insertPrediction(203, 30, 11.0);

        $service = new TransferService($this->db, $this->mockFplClientWithUnavailableEntry());
        $result = $service->getSuggestions(1, 30, 1);

        $this->assertNotEmpty($result['suggestions']);
        $this->assertSame(12, (int) $result['suggestions'][0]['out']['player_id']);

        $inIds = array_column($result['suggestions'][0]['in'], 'player_id');
        $this->assertContains(202, $inIds);
        $this->assertNotContains(201, $inIds, 'Team-limit candidate should be excluded');
        $this->assertNotContains(203, $inIds, 'Over-budget candidate should be excluded');
    }

    public function testMissingPredictionDataReturnsSafeFallbackWithoutException(): void
    {
        $this->insertManager(2);

        for ($id = 1; $id <= 15; $id++) {
            $position = $id <= 2 ? 1 : ($id <= 7 ? 2 : ($id <= 12 ? 3 : 4));
            $team = $id;
            $this->insertPlayer($id, "P{$id}", $team, $position, 55);
            $this->insertManagerPick(2, 31, $id, $id);
            if ($id !== 12) {
                $this->insertPrediction($id, 31, 4.0);
            }
        }

        $service = new TransferService($this->db, $this->mockFplClientWithUnavailableEntry());
        $result = $service->getSuggestions(2, 31, 1);

        $this->assertArrayHasKey('squad_analysis', $result);
        $this->assertArrayHasKey('weakest_players', $result['squad_analysis']);

        $missingPredictionPlayer = array_filter(
            $result['squad_analysis']['weakest_players'],
            static fn(array $row): bool => (int) ($row['player_id'] ?? 0) === 12
        );
        $this->assertNotEmpty($missingPredictionPlayer, 'Missing-prediction player should be scored safely');
    }

    private function mockFplClientWithUnavailableEntry(): FplClient
    {
        $fplClient = $this->createMock(FplClient::class);
        $entry = $this->createMock(EntryEndpoint::class);
        $entry->method('get')->willThrowException(new \RuntimeException('upstream unavailable'));
        $fplClient->method('entry')->willReturn($entry);
        return $fplClient;
    }

    private function seedClubs(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->db->query(
                'INSERT INTO clubs (id, name, short_name) VALUES (?, ?, ?)',
                [$i, "Club {$i}", "C{$i}"]
            );
        }
    }

    private function insertManager(int $id): void
    {
        $this->db->query(
            'INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced) VALUES (?, ?, ?, 1, 1000, datetime("now"))',
            [$id, "Manager {$id}", "Team {$id}"]
        );
    }

    private function insertPlayer(int $id, string $name, int $clubId, int $position, int $nowCost): void
    {
        $this->db->query(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists,
                expected_goals_conceded, ict_index, bps, bonus, starts, chance_of_playing, news, updated_at
            ) VALUES (
                ?, ?, ?, '', '', ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 100, '', datetime('now')
            )",
            [$id, $id, $name, $clubId, $position, $nowCost]
        );
    }

    private function insertManagerPick(int $managerId, int $gameweek, int $playerId, int $position): void
    {
        $this->db->query(
            'INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain)
             VALUES (?, ?, ?, ?, 1, 0, 0)',
            [$managerId, $gameweek, $playerId, $position]
        );
    }

    private function insertPrediction(int $playerId, int $gameweek, float $points): void
    {
        $this->db->query(
            "INSERT INTO player_predictions (
                player_id, gameweek, predicted_points, predicted_if_fit, expected_mins, expected_mins_if_fit,
                breakdown_json, if_fit_breakdown_json, confidence, model_version, computed_at
            ) VALUES (?, ?, ?, ?, 90, 90, '{}', '{}', 1.0, 'v2.0', datetime('now'))",
            [$playerId, $gameweek, $points, $points]
        );
    }
}
