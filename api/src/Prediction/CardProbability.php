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
     * Minimum minutes for reliable per-90 rates.
     * Below this, regress toward league-average baseline.
     */
    private const RELIABLE_MINUTES_THRESHOLD = 270;

    /**
     * League-average deduction rate per 90 (~0.17 yellows, rare reds/OGs/pen misses).
     */
    private const BASELINE_DEDUCTIONS_PER_90 = -0.20;

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
            return self::BASELINE_DEDUCTIONS_PER_90;
        }

        $yellowCards = (int) ($player['yellow_cards'] ?? 0);
        $redCards = (int) ($player['red_cards'] ?? 0);
        $ownGoals = (int) ($player['own_goals'] ?? 0);
        $penaltiesMissed = (int) ($player['penalties_missed'] ?? 0);

        $yellowPer90 = ($yellowCards / $minutes) * 90;
        $redPer90 = ($redCards / $minutes) * 90;
        $ownGoalsPer90 = ($ownGoals / $minutes) * 90;
        $penMissedPer90 = ($penaltiesMissed / $minutes) * 90;

        $rawDeductions = ($yellowPer90 * -1)
            + ($redPer90 * -3)
            + ($ownGoalsPer90 * -2)
            + ($penMissedPer90 * -2);

        // Regress toward league-average baseline for small sample sizes
        if ($minutes < self::RELIABLE_MINUTES_THRESHOLD) {
            $reliability = $minutes / self::RELIABLE_MINUTES_THRESHOLD;
            return round(
                ($rawDeductions * $reliability) + (self::BASELINE_DEDUCTIONS_PER_90 * (1 - $reliability)),
                4
            );
        }

        return round($rawDeductions, 4);
    }
}
