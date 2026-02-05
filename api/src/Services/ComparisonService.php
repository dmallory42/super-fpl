<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

/**
 * Service for comparing multiple managers' teams.
 * Calculates effective ownership, differentials, and risk scores.
 */
class ComparisonService
{
    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {
    }

    /**
     * Compare multiple managers for a specific gameweek.
     *
     * @param array<int> $managerIds
     * @return array<string, mixed>
     */
    public function compare(array $managerIds, int $gameweek): array
    {
        // Fetch picks for all managers
        $managerPicks = [];
        foreach ($managerIds as $managerId) {
            $picks = $this->getManagerPicks($managerId, $gameweek);
            if ($picks !== null) {
                $managerPicks[$managerId] = $picks;
            }
        }

        if (empty($managerPicks)) {
            return ['error' => 'No picks found for any manager'];
        }

        // Calculate effective ownership
        $effectiveOwnership = $this->calculateEffectiveOwnership($managerPicks);

        // Find differentials for each manager
        $differentials = [];
        foreach ($managerPicks as $managerId => $picks) {
            $differentials[$managerId] = $this->findDifferentials($picks, $effectiveOwnership);
        }

        // Calculate risk scores
        $riskScores = [];
        foreach ($managerPicks as $managerId => $picks) {
            $riskScores[$managerId] = $this->calculateRiskScore($picks, $effectiveOwnership);
        }

        // Build ownership matrix
        $ownershipMatrix = $this->buildOwnershipMatrix($managerPicks);

        // Get player details
        $playerIds = array_keys($effectiveOwnership);
        $players = $this->getPlayerDetails($playerIds);

        return [
            'gameweek' => $gameweek,
            'manager_count' => count($managerPicks),
            'effective_ownership' => $effectiveOwnership,
            'differentials' => $differentials,
            'risk_scores' => $riskScores,
            'ownership_matrix' => $ownershipMatrix,
            'players' => $players,
        ];
    }

    /**
     * Get manager's picks for a gameweek (from cache or API).
     *
     * @return array<int, array{element: int, multiplier: int, is_captain: bool}>|null
     */
    private function getManagerPicks(int $managerId, int $gameweek): ?array
    {
        // Try cache first
        $cached = $this->db->fetchAll(
            'SELECT player_id as element, multiplier, is_captain
            FROM manager_picks
            WHERE manager_id = ? AND gameweek = ?',
            [$managerId, $gameweek]
        );

        if (!empty($cached)) {
            return $cached;
        }

        // Fetch from API
        try {
            $picksData = $this->fplClient->entry($managerId)->picks($gameweek);
            return $picksData['picks'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Calculate Effective Ownership (EO) for all players.
     * EO = (sum of multipliers) / number of managers * 100
     *
     * @param array<int, array<int, array>> $managerPicks
     * @return array<int, float> Player ID => EO percentage
     */
    private function calculateEffectiveOwnership(array $managerPicks): array
    {
        $eo = [];
        $managerCount = count($managerPicks);

        foreach ($managerPicks as $picks) {
            foreach ($picks as $pick) {
                $playerId = $pick['element'];
                $multiplier = $pick['multiplier'] ?? 1;

                if (!isset($eo[$playerId])) {
                    $eo[$playerId] = 0;
                }
                $eo[$playerId] += $multiplier;
            }
        }

        // Convert to percentage
        foreach ($eo as $playerId => $total) {
            $eo[$playerId] = round(($total / $managerCount) * 100, 1);
        }

        // Sort by EO descending
        arsort($eo);

        return $eo;
    }

    /**
     * Find differentials for a manager (players with low EO that they own).
     *
     * @param array<int, array> $picks
     * @param array<int, float> $effectiveOwnership
     * @return array<int, array{player_id: int, eo: float, is_captain: bool}>
     */
    private function findDifferentials(array $picks, array $effectiveOwnership): array
    {
        $differentials = [];

        foreach ($picks as $pick) {
            $playerId = $pick['element'];
            $eo = $effectiveOwnership[$playerId] ?? 0;

            // Differential = player owned by <30% of managers (in starting XI)
            if ($eo < 30 && ($pick['multiplier'] ?? 0) > 0) {
                $differentials[] = [
                    'player_id' => $playerId,
                    'eo' => $eo,
                    'is_captain' => (bool) ($pick['is_captain'] ?? false),
                    'multiplier' => $pick['multiplier'] ?? 1,
                ];
            }
        }

        // Sort by EO ascending (most differential first)
        usort($differentials, fn($a, $b) => $a['eo'] <=> $b['eo']);

        return $differentials;
    }

    /**
     * Calculate risk score for a manager.
     * Higher score = more differentiated from the group.
     *
     * @param array<int, array> $picks
     * @param array<int, float> $effectiveOwnership
     * @return array{score: float, level: string, breakdown: array}
     */
    private function calculateRiskScore(array $picks, array $effectiveOwnership): array
    {
        $totalRisk = 0;
        $captainRisk = 0;
        $playingCount = 0;

        foreach ($picks as $pick) {
            $multiplier = $pick['multiplier'] ?? 0;
            if ($multiplier === 0) {
                continue; // Skip bench
            }

            $playerId = $pick['element'];
            $eo = $effectiveOwnership[$playerId] ?? 0;

            // Risk = 100 - EO (higher for less-owned players)
            $playerRisk = 100 - $eo;

            if ($pick['is_captain'] ?? false) {
                // Captain risk counts double
                $captainRisk = $playerRisk * 2;
                $totalRisk += $captainRisk;
            } else {
                $totalRisk += $playerRisk;
            }

            $playingCount++;
        }

        // Normalize by playing XI count
        $normalizedRisk = $playingCount > 0 ? $totalRisk / $playingCount : 0;

        // Determine risk level
        $level = match (true) {
            $normalizedRisk >= 70 => 'high',
            $normalizedRisk >= 40 => 'medium',
            default => 'low',
        };

        return [
            'score' => round($normalizedRisk, 1),
            'level' => $level,
            'breakdown' => [
                'captain_risk' => round($captainRisk, 1),
                'playing_count' => $playingCount,
            ],
        ];
    }

    /**
     * Build ownership matrix showing which managers own which players.
     *
     * @param array<int, array<int, array>> $managerPicks
     * @return array<int, array<int, int>> Player ID => [Manager ID => multiplier]
     */
    private function buildOwnershipMatrix(array $managerPicks): array
    {
        $matrix = [];

        foreach ($managerPicks as $managerId => $picks) {
            foreach ($picks as $pick) {
                $playerId = $pick['element'];
                $multiplier = $pick['multiplier'] ?? 0;

                if (!isset($matrix[$playerId])) {
                    $matrix[$playerId] = [];
                }
                $matrix[$playerId][$managerId] = $multiplier;
            }
        }

        return $matrix;
    }

    /**
     * Get player details for display.
     *
     * @param array<int> $playerIds
     * @return array<int, array<string, mixed>>
     */
    private function getPlayerDetails(array $playerIds): array
    {
        if (empty($playerIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));

        $players = $this->db->fetchAll(
            "SELECT id, web_name, club_id as team, position, now_cost, total_points
            FROM players
            WHERE id IN ({$placeholders})",
            $playerIds
        );

        $result = [];
        foreach ($players as $player) {
            $result[$player['id']] = $player;
        }

        return $result;
    }
}
