<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

/**
 * Service for transfer planning and suggestions.
 */
class TransferService
{
    private const MAX_PLAYERS_PER_TEAM = 3;
    private const SQUAD_SIZE = 15;
    private const POSITION_LIMITS = [
        1 => 2,  // GK
        2 => 5,  // DEF
        3 => 5,  // MID
        4 => 3,  // FWD
    ];

    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {
    }

    /**
     * Get transfer suggestions for a manager.
     *
     * @return array<string, mixed>
     */
    public function getSuggestions(int $managerId, int $gameweek, ?int $transfers = 1): array
    {
        // Get manager's current squad
        $squad = $this->getManagerSquad($managerId, $gameweek);
        if (empty($squad)) {
            return ['error' => 'Could not load manager squad'];
        }

        // Get manager's bank and team value
        $managerInfo = $this->getManagerInfo($managerId);
        $bank = $managerInfo['bank'] ?? 0;

        // Get predictions for the gameweek
        $predictions = $this->getPredictions($gameweek);
        $predictionMap = [];
        foreach ($predictions as $p) {
            $predictionMap[$p['player_id']] = $p;
        }

        // Score current squad players
        $squadScores = [];
        foreach ($squad as $pick) {
            $playerId = $pick['player_id'];
            $player = $this->getPlayerInfo($playerId);
            $prediction = $predictionMap[$playerId] ?? null;

            $squadScores[] = [
                'player_id' => $playerId,
                'web_name' => $player['web_name'] ?? "Player $playerId",
                'team' => $player['club_id'] ?? 0,
                'position' => $player['position'] ?? 0,
                'now_cost' => $player['now_cost'] ?? 0,
                'selling_price' => $pick['selling_price'] ?? $player['now_cost'] ?? 0,
                'predicted_points' => $prediction['predicted_points'] ?? 0,
                'form' => (float) ($player['form'] ?? 0),
                'chance_of_playing' => $player['chance_of_playing'] ?? 100,
                'news' => $player['news'] ?? '',
                'score' => $this->calculatePlayerScore($player, $prediction),
            ];
        }

        // Sort by score ascending (worst players first for potential transfers out)
        usort($squadScores, fn($a, $b) => $a['score'] <=> $b['score']);

        // Identify transfer out candidates (bottom performers with issues)
        $transferOutCandidates = $this->identifyTransferOutCandidates($squadScores);

        // For each candidate, find best replacements
        $suggestions = [];
        foreach (array_slice($transferOutCandidates, 0, $transfers) as $outPlayer) {
            $budget = $outPlayer['selling_price'] + $bank;
            $position = $outPlayer['position'];
            $currentTeams = $this->getCurrentTeamCounts($squad, $outPlayer['player_id']);

            $replacements = $this->findReplacements(
                $position,
                $budget,
                $currentTeams,
                $squad,
                $predictionMap,
                $gameweek
            );

            $suggestions[] = [
                'out' => [
                    'player_id' => $outPlayer['player_id'],
                    'web_name' => $outPlayer['web_name'],
                    'team' => $outPlayer['team'],
                    'position' => $outPlayer['position'],
                    'selling_price' => $outPlayer['selling_price'],
                    'predicted_points' => $outPlayer['predicted_points'],
                    'reason' => $this->getTransferOutReason($outPlayer),
                ],
                'in' => array_slice($replacements, 0, 5), // Top 5 replacements
            ];

            // Update bank for next suggestion
            $bank = 0; // Subsequent transfers start fresh
        }

        return [
            'manager_id' => $managerId,
            'gameweek' => $gameweek,
            'bank' => $managerInfo['bank'] ?? 0,
            'squad_value' => $managerInfo['team_value'] ?? 0,
            'free_transfers' => $managerInfo['free_transfers'] ?? 1,
            'suggestions' => $suggestions,
            'squad_analysis' => [
                'total_predicted_points' => array_sum(array_column($squadScores, 'predicted_points')),
                'weakest_players' => array_slice($squadScores, 0, 3),
            ],
        ];
    }

    /**
     * Simulate a transfer and show impact.
     *
     * @return array<string, mixed>
     */
    public function simulateTransfer(int $managerId, int $gameweek, int $outPlayerId, int $inPlayerId): array
    {
        $squad = $this->getManagerSquad($managerId, $gameweek);
        $predictions = $this->getPredictions($gameweek);
        $predictionMap = [];
        foreach ($predictions as $p) {
            $predictionMap[$p['player_id']] = $p;
        }

        // Calculate current total
        $currentTotal = 0;
        foreach ($squad as $pick) {
            $pred = $predictionMap[$pick['player_id']] ?? null;
            $currentTotal += $pred['predicted_points'] ?? 0;
        }

        // Calculate new total
        $newTotal = 0;
        foreach ($squad as $pick) {
            if ($pick['player_id'] === $outPlayerId) {
                $pred = $predictionMap[$inPlayerId] ?? null;
            } else {
                $pred = $predictionMap[$pick['player_id']] ?? null;
            }
            $newTotal += $pred['predicted_points'] ?? 0;
        }

        $outPlayer = $this->getPlayerInfo($outPlayerId);
        $inPlayer = $this->getPlayerInfo($inPlayerId);
        $outPred = $predictionMap[$outPlayerId] ?? null;
        $inPred = $predictionMap[$inPlayerId] ?? null;

        return [
            'transfer_out' => [
                'player_id' => $outPlayerId,
                'web_name' => $outPlayer['web_name'] ?? '',
                'predicted_points' => $outPred['predicted_points'] ?? 0,
                'now_cost' => $outPlayer['now_cost'] ?? 0,
            ],
            'transfer_in' => [
                'player_id' => $inPlayerId,
                'web_name' => $inPlayer['web_name'] ?? '',
                'predicted_points' => $inPred['predicted_points'] ?? 0,
                'now_cost' => $inPlayer['now_cost'] ?? 0,
            ],
            'points_difference' => round($newTotal - $currentTotal, 1),
            'current_squad_total' => round($currentTotal, 1),
            'new_squad_total' => round($newTotal, 1),
            'cost_difference' => ($inPlayer['now_cost'] ?? 0) - ($outPlayer['now_cost'] ?? 0),
        ];
    }

    /**
     * Get top transfer targets regardless of current squad.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopTargets(int $gameweek, ?int $position = null, ?int $maxPrice = null): array
    {
        $predictions = $this->getPredictions($gameweek);
        $targets = [];

        foreach ($predictions as $pred) {
            $player = $this->getPlayerInfo($pred['player_id']);
            if (!$player) {
                continue;
            }

            // Apply position filter
            if ($position !== null && $player['position'] !== $position) {
                continue;
            }

            // Apply price filter
            if ($maxPrice !== null && $player['now_cost'] > $maxPrice) {
                continue;
            }

            // Skip injured players
            $chanceOfPlaying = $player['chance_of_playing'] ?? 100;
            if ($chanceOfPlaying !== null && $chanceOfPlaying < 75) {
                continue;
            }

            $valueScore = $pred['predicted_points'] / max(1, $player['now_cost'] / 10);

            $targets[] = [
                'player_id' => $pred['player_id'],
                'web_name' => $player['web_name'],
                'team' => $player['club_id'],
                'position' => $player['position'],
                'now_cost' => $player['now_cost'],
                'predicted_points' => $pred['predicted_points'],
                'form' => (float) $player['form'],
                'value_score' => round($valueScore, 2),
            ];
        }

        // Sort by predicted points
        usort($targets, fn($a, $b) => $b['predicted_points'] <=> $a['predicted_points']);

        return array_slice($targets, 0, 20);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getManagerSquad(int $managerId, int $gameweek): array
    {
        // Try cached picks first
        $cached = $this->db->fetchAll(
            'SELECT player_id, position, multiplier FROM manager_picks
            WHERE manager_id = ? AND gameweek = ?
            ORDER BY position',
            [$managerId, $gameweek]
        );

        if (!empty($cached)) {
            return $cached;
        }

        // Fetch from API
        try {
            $picksData = $this->fplClient->entry($managerId)->picks($gameweek);
            $picks = $picksData['picks'] ?? [];

            return array_map(fn($p) => [
                'player_id' => $p['element'],
                'position' => $p['position'],
                'multiplier' => $p['multiplier'],
                'selling_price' => $p['selling_price'] ?? null,
            ], $picks);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getManagerInfo(int $managerId): array
    {
        try {
            $entry = $this->fplClient->entry($managerId)->get();
            return [
                'bank' => ($entry['last_deadline_bank'] ?? 0) / 10,
                'team_value' => ($entry['last_deadline_value'] ?? 0) / 10,
                'free_transfers' => $entry['last_deadline_total_transfers'] ?? 1,
            ];
        } catch (\Throwable $e) {
            return ['bank' => 0, 'team_value' => 1000, 'free_transfers' => 1];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPredictions(int $gameweek): array
    {
        return $this->db->fetchAll(
            'SELECT p.player_id, p.predicted_points, p.confidence,
                    pl.web_name, pl.club_id as team, pl.position, pl.now_cost
            FROM player_predictions p
            JOIN players pl ON pl.id = p.player_id
            WHERE p.gameweek = ?
            ORDER BY p.predicted_points DESC',
            [$gameweek]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPlayerInfo(int $playerId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, web_name, club_id, position, now_cost, form,
                    chance_of_playing, news, total_points
            FROM players WHERE id = ?',
            [$playerId]
        );
    }

    /**
     * @param array<string, mixed> $player
     * @param array<string, mixed>|null $prediction
     */
    private function calculatePlayerScore(array $player, ?array $prediction): float
    {
        $score = 0;

        // Predicted points (most important)
        $score += ($prediction['predicted_points'] ?? 0) * 10;

        // Form
        $score += (float) ($player['form'] ?? 0) * 5;

        // Availability
        $chanceOfPlaying = $player['chance_of_playing'] ?? 100;
        if ($chanceOfPlaying !== null && $chanceOfPlaying < 100) {
            $score *= ($chanceOfPlaying / 100);
        }

        // Penalize players with negative news
        if (!empty($player['news'])) {
            $score *= 0.7;
        }

        return $score;
    }

    /**
     * @param array<int, array<string, mixed>> $squadScores
     * @return array<int, array<string, mixed>>
     */
    private function identifyTransferOutCandidates(array $squadScores): array
    {
        $candidates = [];

        foreach ($squadScores as $player) {
            $isCandidate = false;
            $reason = '';

            // Low chance of playing
            if (isset($player['chance_of_playing']) && $player['chance_of_playing'] < 75) {
                $isCandidate = true;
            }

            // Injury news
            if (!empty($player['news'])) {
                $isCandidate = true;
            }

            // Very low form
            if ($player['form'] < 2.0) {
                $isCandidate = true;
            }

            // Very low predicted points
            if ($player['predicted_points'] < 2.0) {
                $isCandidate = true;
            }

            if ($isCandidate) {
                $candidates[] = $player;
            }
        }

        // If no obvious candidates, return worst 3 by score
        if (empty($candidates)) {
            return array_slice($squadScores, 0, 3);
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $player
     */
    private function getTransferOutReason(array $player): string
    {
        $reasons = [];

        if (isset($player['chance_of_playing']) && $player['chance_of_playing'] < 75) {
            $reasons[] = "Availability concern ({$player['chance_of_playing']}%)";
        }

        if (!empty($player['news'])) {
            $reasons[] = 'Flagged: ' . substr($player['news'], 0, 50);
        }

        if ($player['form'] < 2.0) {
            $reasons[] = "Poor form ({$player['form']})";
        }

        if ($player['predicted_points'] < 2.0) {
            $reasons[] = "Low predicted points ({$player['predicted_points']})";
        }

        return implode('; ', $reasons) ?: 'Low overall score';
    }

    /**
     * @param array<int, array<string, mixed>> $squad
     * @return array<int, int>
     */
    private function getCurrentTeamCounts(array $squad, int $excludePlayerId): array
    {
        $counts = [];

        foreach ($squad as $pick) {
            if ($pick['player_id'] === $excludePlayerId) {
                continue;
            }

            $player = $this->getPlayerInfo($pick['player_id']);
            if ($player) {
                $teamId = $player['club_id'];
                $counts[$teamId] = ($counts[$teamId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<int, int> $currentTeams
     * @param array<int, array<string, mixed>> $squad
     * @param array<int, array<string, mixed>> $predictionMap
     * @return array<int, array<string, mixed>>
     */
    private function findReplacements(
        int $position,
        float $budget,
        array $currentTeams,
        array $squad,
        array $predictionMap,
        int $gameweek
    ): array {
        // Get current squad player IDs
        $squadIds = array_column($squad, 'player_id');

        // Get all players of same position within budget
        $players = $this->db->fetchAll(
            'SELECT id, web_name, club_id, position, now_cost, form,
                    chance_of_playing, total_points
            FROM players
            WHERE position = ? AND now_cost <= ?
            ORDER BY total_points DESC',
            [$position, $budget * 10]
        );

        $replacements = [];

        foreach ($players as $player) {
            // Skip players already in squad
            if (in_array($player['id'], $squadIds)) {
                continue;
            }

            // Check team limit
            $teamId = $player['club_id'];
            if (($currentTeams[$teamId] ?? 0) >= self::MAX_PLAYERS_PER_TEAM) {
                continue;
            }

            // Skip injured players
            $chanceOfPlaying = $player['chance_of_playing'] ?? 100;
            if ($chanceOfPlaying !== null && $chanceOfPlaying < 75) {
                continue;
            }

            $prediction = $predictionMap[$player['id']] ?? null;
            $predictedPoints = $prediction['predicted_points'] ?? 0;

            $replacements[] = [
                'player_id' => $player['id'],
                'web_name' => $player['web_name'],
                'team' => $player['club_id'],
                'position' => $player['position'],
                'now_cost' => $player['now_cost'] / 10,
                'predicted_points' => $predictedPoints,
                'form' => (float) $player['form'],
                'total_points' => $player['total_points'],
            ];
        }

        // Sort by predicted points
        usort($replacements, fn($a, $b) => $b['predicted_points'] <=> $a['predicted_points']);

        return $replacements;
    }
}
