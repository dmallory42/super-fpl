<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates expected bonus points for a player.
 * Bonus points (1-3) are awarded to the top 3 BPS scorers in each match.
 *
 * Uses sigmoid on BPS (absolute data, reliable).
 * Boosts BPS estimate with expected goals (~24 BPS/goal) and assists (~15 BPS/assist).
 * Keeps 60/40 blend with historical bonus rate.
 * Removes arbitrary fixture multiplier.
 */
class BonusProbability
{
    /**
     * Calculate expected bonus points.
     *
     * @param array<string, mixed> $player Player data
     * @param float $expectedGoals Expected goals for this fixture
     * @param float $expectedAssists Expected assists for this fixture
     * @return float Expected bonus points (0-3 range typically 0-1.5)
     */
    public function calculate(
        array $player,
        float $expectedGoals = 0.0,
        float $expectedAssists = 0.0
    ): float {
        $bps = (int) ($player['bps'] ?? 0);
        $bonus = (int) ($player['bonus'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);

        if ($minutes < 90) {
            return $this->estimateFromPosition($position);
        }

        // Calculate BPS per 90 minutes
        $bpsPer90 = ($bps / $minutes) * 90;

        // Boost BPS estimate with goal/assist expectations
        // ~24 BPS per goal, ~15 BPS per assist
        $bpsBoost = ($expectedGoals * 24) + ($expectedAssists * 15);
        $adjustedBps = $bpsPer90 + $bpsBoost;

        // Calculate historical bonus per appearance
        $appearances = max(1, floor($minutes / 60));
        $bonusPerGame = $bonus / $appearances;

        // BPS-based estimate from sigmoid
        $expectedBonus = $this->bpsToExpectedBonus($adjustedBps);

        // Blend: 60% BPS-based, 40% historical
        $blended = ($expectedBonus * 0.6) + ($bonusPerGame * 0.4);

        return round(min(2.5, $blended), 2);
    }

    /**
     * Convert BPS per 90 to expected bonus points.
     */
    private function bpsToExpectedBonus(float $bpsPer90): float
    {
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
        return match ($position) {
            1 => 0.3,
            2 => 0.4,
            3 => 0.5,
            4 => 0.6,
            default => 0.4,
        };
    }
}
