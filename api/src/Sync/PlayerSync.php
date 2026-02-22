<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\ParallelHttpClient;

class PlayerSync
{
    private const FPL_API_BASE = 'https://fantasy.premierleague.com/api/';

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
     * Sync player appearances from element-summary endpoints.
     * This fetches detailed history for each player to count actual appearances.
     *
     * @return int Number of players updated
     */
    public function syncAppearances(): int
    {
        // Get all player IDs with minutes > 0
        $players = $this->db->fetchAll('SELECT id FROM players WHERE minutes > 0');
        $playerIds = array_column($players, 'id');

        if (empty($playerIds)) {
            return 0;
        }

        // Build endpoints for parallel fetch
        $endpoints = [];
        foreach ($playerIds as $playerId) {
            $endpoints[] = "element-summary/{$playerId}/";
        }

        // Fetch all player summaries in parallel
        $parallelClient = new ParallelHttpClient(self::FPL_API_BASE);
        $responses = $parallelClient->getBatch($endpoints, 200);

        // Calculate appearances and update database
        $count = 0;
        foreach ($playerIds as $index => $playerId) {
            $endpoint = $endpoints[$index];
            $data = $responses[$endpoint] ?? null;

            if ($data === null || !isset($data['history'])) {
                continue;
            }

            /** @var array<int, array<string, int|float|null>> $byGw */
            $byGw = [];

            // Count appearances (gameweeks where minutes > 0)
            $appearances = 0;
            foreach ($data['history'] as $match) {
                $minutes = (int) ($match['minutes'] ?? 0);
                if ($minutes > 0) {
                    $appearances++;
                }

                $gameweek = (int) ($match['round'] ?? 0);
                if ($gameweek <= 0) {
                    continue;
                }

                if (!isset($byGw[$gameweek])) {
                    $byGw[$gameweek] = [
                        'fixture_id' => null,
                        'opponent_team' => null,
                        'was_home' => null,
                        'minutes' => 0,
                        'goals_scored' => 0,
                        'assists' => 0,
                        'clean_sheets' => 0,
                        'goals_conceded' => 0,
                        'bonus' => 0,
                        'bps' => 0,
                        'total_points' => 0,
                        'expected_goals' => 0.0,
                        'expected_assists' => 0.0,
                        'expected_goals_conceded' => 0.0,
                        'value' => null,
                        'selected' => null,
                    ];
                }

                $row = &$byGw[$gameweek];
                $row['fixture_id'] = isset($match['fixture']) ? (int) $match['fixture'] : $row['fixture_id'];
                $row['opponent_team'] = isset($match['opponent_team']) ? (int) $match['opponent_team'] : $row['opponent_team'];
                $row['was_home'] = isset($match['was_home']) ? ((bool) $match['was_home'] ? 1 : 0) : $row['was_home'];
                $row['minutes'] += $minutes;
                $row['goals_scored'] += (int) ($match['goals_scored'] ?? 0);
                $row['assists'] += (int) ($match['assists'] ?? 0);
                $row['clean_sheets'] += (int) ($match['clean_sheets'] ?? 0);
                $row['goals_conceded'] += (int) ($match['goals_conceded'] ?? 0);
                $row['bonus'] += (int) ($match['bonus'] ?? 0);
                $row['bps'] += (int) ($match['bps'] ?? 0);
                $row['total_points'] += (int) ($match['total_points'] ?? 0);
                $row['expected_goals'] += (float) ($match['expected_goals'] ?? 0.0);
                $row['expected_assists'] += (float) ($match['expected_assists'] ?? 0.0);
                $row['expected_goals_conceded'] += (float) ($match['expected_goals_conceded'] ?? 0.0);
                $row['value'] = isset($match['value']) ? (int) $match['value'] : $row['value'];
                $row['selected'] = isset($match['selected']) ? (int) $match['selected'] : $row['selected'];
                unset($row);
            }

            // Update player record
            $this->db->query(
                'UPDATE players SET appearances = ? WHERE id = ?',
                [$appearances, $playerId]
            );

            foreach ($byGw as $gameweek => $row) {
                $this->db->upsert('player_gameweek_history', [
                    'player_id' => $playerId,
                    'gameweek' => $gameweek,
                    'fixture_id' => $row['fixture_id'],
                    'opponent_team' => $row['opponent_team'],
                    'was_home' => $row['was_home'],
                    'minutes' => $row['minutes'],
                    'goals_scored' => $row['goals_scored'],
                    'assists' => $row['assists'],
                    'clean_sheets' => $row['clean_sheets'],
                    'goals_conceded' => $row['goals_conceded'],
                    'bonus' => $row['bonus'],
                    'bps' => $row['bps'],
                    'total_points' => $row['total_points'],
                    'expected_goals' => round((float) $row['expected_goals'], 3),
                    'expected_assists' => round((float) $row['expected_assists'], 3),
                    'expected_goals_conceded' => round((float) $row['expected_goals_conceded'], 3),
                    'value' => $row['value'],
                    'selected' => $row['selected'],
                ], ['player_id', 'gameweek']);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Sync past season history for all players.
     * Heavy operation (~700 requests), should run via cron only.
     *
     * @return int Number of season history records upserted
     */
    public function syncSeasonHistory(): int
    {
        // Get all players with their code (needed for player_season_history PK)
        $players = $this->db->fetchAll('SELECT id, code FROM players WHERE minutes > 0');

        if (empty($players)) {
            return 0;
        }

        // Build endpoints for parallel fetch
        $endpoints = [];
        foreach ($players as $player) {
            $endpoints[] = "element-summary/{$player['id']}/";
        }

        // Fetch all player summaries in parallel
        $parallelClient = new ParallelHttpClient(self::FPL_API_BASE);
        $responses = $parallelClient->getBatch($endpoints, 200);

        $count = 0;
        $seenSeasons = [];
        foreach ($players as $index => $player) {
            $endpoint = $endpoints[$index];
            $data = $responses[$endpoint] ?? null;

            if ($data === null || !isset($data['history_past'])) {
                continue;
            }

            foreach ($data['history_past'] as $season) {
                $seasonId = (string) ($season['season_name'] ?? '');
                if ($seasonId === '') {
                    continue;
                }
                if (!isset($seenSeasons[$seasonId])) {
                    $this->db->upsert('seasons', [
                        'id' => $seasonId,
                        'start_date' => null,
                        'end_date' => null,
                    ], ['id']);
                    $seenSeasons[$seasonId] = true;
                }

                $this->db->upsert('player_season_history', [
                    'player_code' => $player['code'],
                    'season_id' => $seasonId,
                    'total_points' => $season['total_points'] ?? 0,
                    'minutes' => $season['minutes'] ?? 0,
                    'goals_scored' => $season['goals_scored'] ?? 0,
                    'assists' => $season['assists'] ?? 0,
                    'clean_sheets' => $season['clean_sheets'] ?? 0,
                    'expected_goals' => $season['expected_goals'] ?? null,
                    'expected_assists' => $season['expected_assists'] ?? null,
                    'expected_goals_conceded' => $season['expected_goals_conceded'] ?? null,
                    'starts' => $season['starts'] ?? null,
                    'start_cost' => $season['start_cost'] ?? 0,
                    'end_cost' => $season['end_cost'] ?? 0,
                ], ['player_code', 'season_id']);

                $count++;
            }
        }

        return $count;
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
                'yellow_cards' => $player['yellow_cards'] ?? 0,
                'red_cards' => $player['red_cards'] ?? 0,
                'own_goals' => $player['own_goals'] ?? 0,
                'penalties_missed' => $player['penalties_missed'] ?? 0,
                'penalties_saved' => $player['penalties_saved'] ?? 0,
                'goals_conceded' => $player['goals_conceded'] ?? 0,
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
