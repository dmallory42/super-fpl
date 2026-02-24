<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates expected negative points from disciplinary cards only.
 *
 * Simple per-90 rates from FPL actuals:
 * - Yellow card: -1 point
 * - Red card: -3 points
 *
 * Own goals and penalty misses are intentionally excluded here:
 * - Own goals are rare and highly volatile match events
 * - Penalty miss risk is already embedded in PenaltyProbability
 */
class CardProbability
{
    /**
     * Minimum minutes for reliable per-90 rates.
     * Below this, regress toward league-average baseline.
     */
    private const RELIABLE_MINUTES_THRESHOLD = 270;

    /**
     * League-average disciplinary deduction rate per 90.
     */
    private const BASELINE_DEDUCTIONS_PER_90 = -0.16;

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
        $yellowPer90 = ($yellowCards / $minutes) * 90;
        $redPer90 = ($redCards / $minutes) * 90;

        $rawDeductions = ($yellowPer90 * -1)
            + ($redPer90 * -3);

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
