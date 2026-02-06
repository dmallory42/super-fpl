<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates expected negative points from cards, own goals, and penalty misses.
 *
 * Simple per-90 rates from FPL actuals:
 * - Yellow card: -1 point
 * - Red card: -3 points
 * - Own goal: -2 points
 * - Penalty missed: -2 points
 */
class CardProbability
{
    /**
     * Calculate expected deduction points per match.
     *
     * @param array<string, mixed> $player Player data
     * @return float Expected negative points (always <= 0)
     */
    public function calculate(array $player): float
    {
        $minutes = (int) ($player['minutes'] ?? 0);

        if ($minutes <= 0) {
            return 0.0;
        }

        $yellowCards = (int) ($player['yellow_cards'] ?? 0);
        $redCards = (int) ($player['red_cards'] ?? 0);
        $ownGoals = (int) ($player['own_goals'] ?? 0);
        $penaltiesMissed = (int) ($player['penalties_missed'] ?? 0);

        $yellowPer90 = ($yellowCards / $minutes) * 90;
        $redPer90 = ($redCards / $minutes) * 90;
        $ownGoalsPer90 = ($ownGoals / $minutes) * 90;
        $penMissedPer90 = ($penaltiesMissed / $minutes) * 90;

        $expectedDeductions = ($yellowPer90 * -1)
            + ($redPer90 * -3)
            + ($ownGoalsPer90 * -2)
            + ($penMissedPer90 * -2);

        return round($expectedDeductions, 4);
    }
}
