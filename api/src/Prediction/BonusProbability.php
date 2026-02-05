<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates expected bonus points for a player.
 * Bonus points (1-3) are awarded to the top 3 BPS scorers in each match.
 */
class BonusProbability
{
    /**
     * Calculate expected bonus points.
     *
     * @param array<string, mixed> $player Player data
     * @return float Expected bonus points (0-3 range typically 0-1.5)
     */
    public function calculate(array $player): float
    {
        $bps = (int) ($player['bps'] ?? 0);
        $bonus = (int) ($player['bonus'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);

        if ($minutes < 95) {
            // Not enough data
            return $this->estimateFromPosition($position);
        }

        // Calculate BPS per 95 (full match with injury time)
        $bpsPer95 = ($bps / $minutes) * 95;

        // Calculate historical bonus per appearance
        $appearances = max(1, floor($minutes / 60));
        $bonusPerGame = $bonus / $appearances;

        // Weight towards actual bonus history but use BPS to estimate future
        // Higher BPS indicates more likely to get bonus
        $expectedBonus = $this->bpsToExpectedBonus($bpsPer95, $position);

        // Blend with historical
        return round(($expectedBonus * 0.6) + ($bonusPerGame * 0.4), 2);
    }

    /**
     * Convert BPS per 90 to expected bonus points.
     * Based on typical BPS thresholds for bonus points.
     */
    private function bpsToExpectedBonus(float $bpsPer90, int $position): float
    {
        // Average BPS for bonus winners is typically:
        // 3 bonus: ~35-40 BPS
        // 2 bonus: ~30-35 BPS
        // 1 bonus: ~25-30 BPS

        // Probability of getting 3/2/1 bonus based on BPS
        $prob3 = $this->sigmoid($bpsPer90, 38, 5);
        $prob2 = $this->sigmoid($bpsPer90, 32, 4) - $prob3;
        $prob1 = $this->sigmoid($bpsPer90, 26, 4) - $prob3 - $prob2;

        return (3 * $prob3) + (2 * max(0, $prob2)) + (1 * max(0, $prob1));
    }

    /**
     * Sigmoid function for smooth probability transitions.
     */
    private function sigmoid(float $x, float $midpoint, float $steepness): float
    {
        return 1 / (1 + exp(-($x - $midpoint) / $steepness));
    }

    /**
     * Estimate bonus from position when no data available.
     */
    private function estimateFromPosition(int $position): float
    {
        // League average bonus by position
        return match ($position) {
            1 => 0.3,  // GK - occasional high BPS from saves
            2 => 0.4,  // DEF - clean sheet bonuses
            3 => 0.5,  // MID - most likely to get bonus
            4 => 0.6,  // FWD - goals = high BPS
            default => 0.4,
        };
    }
}
