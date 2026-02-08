<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates expected penalty points for a player.
 *
 * Model:
 *   expectedPenPoints = teamPenRate * takePct * expectedPointsPerPen
 *
 * Where:
 *   - teamPenRate: average penalties awarded to a team per match (~0.15 in EPL)
 *   - takePct: probability this player takes the pen if one is awarded (from penalty_order)
 *   - expectedPointsPerPen: conversionRate * goalPoints + missRate * (-2)
 *
 * penalty_order is user-set: 1 = primary taker, 2 = backup, etc.
 * Default take probabilities: 1 → 85%, 2 → 10%, 3 → 5%
 */
class PenaltyProbability
{
    /**
     * Average penalties awarded per team per EPL match.
     * ~120 penalties per season / 380 matches * 2 teams = ~0.158 per team per match.
     */
    private const TEAM_PEN_RATE = 0.15;

    /** Average penalty conversion rate in the EPL (~78%). */
    private const CONVERSION_RATE = 0.78;

    /** FPL deduction for a missed penalty. */
    private const MISS_DEDUCTION = -2;

    /**
     * Default take probability by penalty_order position.
     * penalty_order 1 = primary taker (85%), 2 = backup (10%), 3 = third choice (5%).
     */
    private const DEFAULT_TAKE_PCT = [
        1 => 0.85,
        2 => 0.10,
        3 => 0.05,
    ];

    /** FPL goal points by position. */
    private const GOAL_POINTS = [1 => 6, 2 => 6, 3 => 5, 4 => 4];

    /**
     * Calculate expected penalty points per match.
     *
     * @param array<string, mixed> $player Player data (needs penalty_order and position)
     * @return float Expected points from penalties per match
     */
    public function calculate(array $player): float
    {
        $penaltyOrder = $player['penalty_order'] ?? null;

        if ($penaltyOrder === null || (int) $penaltyOrder <= 0) {
            return 0.0;
        }

        $order = (int) $penaltyOrder;
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);

        // Get take probability from order
        $takePct = self::DEFAULT_TAKE_PCT[$order] ?? 0.0;

        if ($takePct <= 0) {
            return 0.0;
        }

        $goalPoints = self::GOAL_POINTS[$position] ?? 4;

        // Expected points per penalty attempt:
        // P(score) * goalPoints + P(miss) * (-2)
        $expectedPerPen = (self::CONVERSION_RATE * $goalPoints)
            + ((1 - self::CONVERSION_RATE) * self::MISS_DEDUCTION);

        // Expected penalty points per match:
        // teamPenRate * P(this player takes it) * expectedPerPen
        return round(self::TEAM_PEN_RATE * $takePct * $expectedPerPen, 4);
    }
}
