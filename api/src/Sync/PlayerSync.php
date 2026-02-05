<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class PlayerSync
{
    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {
    }

    /**
     * Sync players and teams from FPL API.
     *
     * @return array{players: int, teams: int}
     */
    public function sync(): array
    {
        $bootstrap = $this->fplClient->bootstrap()->get();

        // Sync teams first (players reference them)
        $teamCount = $this->syncTeams($bootstrap['teams']);

        // Sync players
        $playerCount = $this->syncPlayers($bootstrap['elements']);

        return [
            'players' => $playerCount,
            'teams' => $teamCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $teams
     */
    private function syncTeams(array $teams): int
    {
        $count = 0;

        foreach ($teams as $team) {
            $this->db->upsert('clubs', [
                'id' => $team['id'],
                'name' => $team['name'],
                'short_name' => $team['short_name'],
                'strength_attack_home' => $team['strength_attack_home'] ?? null,
                'strength_attack_away' => $team['strength_attack_away'] ?? null,
                'strength_defence_home' => $team['strength_defence_home'] ?? null,
                'strength_defence_away' => $team['strength_defence_away'] ?? null,
            ], ['id']);

            $count++;
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    private function syncPlayers(array $elements): int
    {
        $count = 0;

        foreach ($elements as $player) {
            $this->db->upsert('players', [
                'id' => $player['id'],
                'code' => $player['code'],
                'web_name' => $player['web_name'],
                'first_name' => $player['first_name'],
                'second_name' => $player['second_name'],
                'club_id' => $player['team'],
                'position' => $player['element_type'],
                'now_cost' => $player['now_cost'],
                'total_points' => $player['total_points'],
                'form' => $player['form'] ?? '0.0',
                'selected_by_percent' => $player['selected_by_percent'] ?? '0.0',
                'minutes' => $player['minutes'],
                'goals_scored' => $player['goals_scored'],
                'assists' => $player['assists'],
                'clean_sheets' => $player['clean_sheets'],
                'expected_goals' => $player['expected_goals'] ?? '0.00',
                'expected_assists' => $player['expected_assists'] ?? '0.00',
                'expected_goals_conceded' => $player['expected_goals_conceded'] ?? '0.00',
                'ict_index' => $player['ict_index'] ?? '0.0',
                'bps' => $player['bps'],
                'bonus' => $player['bonus'],
                'starts' => $player['starts'] ?? 0,
                'chance_of_playing' => $player['chance_of_playing_next_round'],
                'news' => $player['news'] ?? '',
                'defensive_contribution' => $player['defensive_contribution'] ?? 0,
                'defensive_contribution_per_90' => $this->calculatePer90($player['defensive_contribution'] ?? 0, $player['minutes'] ?? 0),
                'saves' => $player['saves'] ?? 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id']);

            $count++;
        }

        return $count;
    }

    /**
     * Calculate a stat per 90 minutes.
     */
    private function calculatePer90(int|float $stat, int $minutes): float
    {
        if ($minutes <= 0) {
            return 0.0;
        }
        return round(($stat / $minutes) * 90, 2);
    }
}
