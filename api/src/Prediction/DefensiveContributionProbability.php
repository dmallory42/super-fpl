<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates probability of earning defensive contribution bonus points.
 *
 * FPL awards 2 bonus points for reaching defensive contribution thresholds:
 * - DEF: 10+ contributions = 2 points
 * - MID/FWD: 12+ contributions = 2 points
 *
 * Defensive contributions include tackles, interceptions, blocks, clearances.
 */
class DefensiveContributionProbability
{
    /**
     * Thresholds for earning DC bonus by position.
     * Position: 2=DEF, 3=MID, 4=FWD
     */
    private const DC_THRESHOLDS = [
        2 => 10,  // DEF
        3 => 12,  // MID
        4 => 12,  // FWD
    ];

    /**
     * Calculate expected defensive contribution bonus points.
     *
     * @param array<string, mixed> $player Player data
     * @param int $position Player position (2=DEF, 3=MID, 4=FWD)
     * @param float $expectedMins Expected minutes to be played
     * @return float Expected DC bonus points (0-2)
     */
    public function calculate(array $player, int $position, float $expectedMins): float
    {
        // DC bonus only applies to outfield players (not GK)
        if ($position < 2 || $position > 4) {
            return 0.0;
        }

        $dcPer90 = (float) ($player['defensive_contribution_per_90'] ?? 0);

        if ($dcPer90 <= 0 || $expectedMins <= 0) {
            return 0.0;
        }

        // Calculate expected DC for the match
        $expectedDC = $dcPer90 * ($expectedMins / 90);

        // Get threshold for this position
        $threshold = self::DC_THRESHOLDS[$position] ?? 12;

        // Probability of reaching threshold using Poisson CDF
        // P(DC >= threshold) = 1 - P(DC < threshold) = 1 - P(DC <= threshold - 1)
        $probReachingThreshold = 1 - $this->poissonCDF($threshold - 1, $expectedDC);

        // 2 points if threshold reached
        return $probReachingThreshold * 2;
    }

    /**
     * Calculate Poisson CDF: P(X <= k) for Poisson distribution with mean lambda.
     *
     * @param int $k Upper bound (inclusive)
     * @param float $lambda Mean of the Poisson distribution
     * @return float Cumulative probability
     */
    private function poissonCDF(int $k, float $lambda): float
    {
        if ($lambda <= 0) {
            return $k >= 0 ? 1.0 : 0.0;
        }

        $sum = 0.0;
        $term = exp(-$lambda);  // P(X = 0)

        for ($i = 0; $i <= $k; $i++) {
            $sum += $term;
            if ($i < $k) {
                $term *= $lambda / ($i + 1);
            }
        }

        return min(1.0, $sum);
    }
}
