<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use Maia\Orm\Connection;
use SuperFPL\Api\Support\ConnectionSql;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\ParallelHttpClient;

/**
 * Service for comparing multiple managers' teams.
 * Calculates effective ownership, differentials, and risk scores.
 */
class ComparisonService
{
    use ConnectionSql;

    public function __construct(
        private readonly Connection $connection,
        private readonly FplClient $fplClient,
        private readonly ?ParallelHttpClient $parallelClient = null
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
        // Fetch picks for all managers (parallel when possible)
        $managerPicks = $this->getAllManagerPicks($managerIds, $gameweek);

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
     * Fetch picks for all managers, using DB cache first and parallel HTTP for the rest.
     *
     * @param array<int> $managerIds
     * @return array<int, array>
     */
    private function getAllManagerPicks(array $managerIds, int $gameweek): array
    {
        $managerPicks = [];
        $uncachedIds = [];

        // Check DB cache for each manager
        foreach ($managerIds as $managerId) {
            $cached = $this->fetchAll(
                'SELECT player_id as element, multiplier, is_captain
                FROM manager_picks
                WHERE manager_id = ? AND gameweek = ?',
                [$managerId, $gameweek]
            );

            if (!empty($cached)) {
                $managerPicks[$managerId] = $cached;
            } else {
                $uncachedIds[] = $managerId;
            }
        }

        if (empty($uncachedIds)) {
            return $managerPicks;
        }

        // Fetch uncached picks in parallel
        if ($this->parallelClient !== null && count($uncachedIds) > 1) {
            $endpoints = [];
            foreach ($uncachedIds as $id) {
                $endpoints["entry/{$id}/event/{$gameweek}/picks/"] = $id;
            }

            $results = $this->parallelClient->getBatch(array_keys($endpoints));

            foreach ($results as $endpoint => $data) {
                $id = $endpoints[$endpoint];
                $picks = $data['picks'] ?? null;
                if ($picks !== null) {
                    $managerPicks[$id] = $picks;
                }
            }
        } else {
            // Fallback to sequential
            foreach ($uncachedIds as $managerId) {
                try {
                    $picksData = $this->fplClient->entry($managerId)->picks($gameweek);
                    $picks = $picksData['picks'] ?? null;
                    if ($picks !== null) {
                        $managerPicks[$managerId] = $picks;
                    }
                } catch (\Throwable) {
                    // Skip failed managers
                }
            }
        }

        return $managerPicks;
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

        $players = $this->fetchAll(
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

    protected function connection(): Connection
    {
        return $this->connection;
    }
}
