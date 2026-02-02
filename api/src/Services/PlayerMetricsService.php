<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;

class PlayerMetricsService
{
    private const PENALTY_XG = 0.76;

    public function __construct(private Database $db)
    {
    }

    /**
     * Get enhanced metrics for a player
     * Returns per-90 stats, non-penalty stats, and performance differentials
     */
    public function getEnhancedMetrics(array $player): array
    {
        $minutes = (int) ($player['minutes'] ?? 0);
        $nineties = $minutes > 0 ? $minutes / 90 : 0;

        $goals = (int) ($player['goals_scored'] ?? 0);
        $assists = (int) ($player['assists'] ?? 0);
        $xg = (float) ($player['expected_goals'] ?? 0);
        $xa = (float) ($player['expected_assists'] ?? 0);

        // Estimate penalties scored: if goals significantly exceed xG, likely includes pens
        // A penalty adds ~0.24 goals above xG expectation (1.0 - 0.76)
        $goalOverperformance = $goals - $xg;
        $estimatedPenaltiesScored = $goalOverperformance > 0.5
            ? (int) round($goalOverperformance / 0.24)
            : 0;
        $estimatedPenaltiesScored = min($estimatedPenaltiesScored, $goals); // Can't exceed total goals

        $npGoals = $goals - $estimatedPenaltiesScored;
        $npxG = $xg - ($estimatedPenaltiesScored * self::PENALTY_XG);
        $npxG = max(0, $npxG); // Floor at 0

        return [
            // Per 90 metrics
            'minutes' => $minutes,
            'nineties' => round($nineties, 2),
            'goals_per_90' => $nineties > 0 ? round($goals / $nineties, 2) : 0,
            'assists_per_90' => $nineties > 0 ? round($assists / $nineties, 2) : 0,
            'xg_per_90' => $nineties > 0 ? round($xg / $nineties, 2) : 0,
            'xa_per_90' => $nineties > 0 ? round($xa / $nineties, 2) : 0,

            // Non-penalty metrics
            'goals' => $goals,
            'np_goals' => $npGoals,
            'xg' => round($xg, 2),
            'np_xg' => round($npxG, 2),
            'estimated_penalties_scored' => $estimatedPenaltiesScored,

            // Assist metrics
            'assists' => $assists,
            'xa' => round($xa, 2),

            // Performance differentials (positive = overperforming)
            'goals_minus_xg' => round($goals - $xg, 2),
            'np_goals_minus_np_xg' => round($npGoals - $npxG, 2),
            'assists_minus_xa' => round($assists - $xa, 2),

            // Per 90 differentials
            'np_goals_per_90' => $nineties > 0 ? round($npGoals / $nineties, 2) : 0,
            'np_xg_per_90' => $nineties > 0 ? round($npxG / $nineties, 2) : 0,
        ];
    }

    /**
     * Get all players with enhanced metrics
     */
    public function getAllWithMetrics(array $filters = []): array
    {
        $playerService = new PlayerService($this->db);
        $players = $playerService->getAll($filters);

        return array_map(function ($player) {
            return array_merge($player, [
                'metrics' => $this->getEnhancedMetrics($player)
            ]);
        }, $players);
    }
}
