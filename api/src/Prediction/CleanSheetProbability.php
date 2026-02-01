<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates clean sheet probability for defenders and goalkeepers.
 */
class CleanSheetProbability
{
    /**
     * Calculate clean sheet probability for a player's team.
     *
     * @param array<string, mixed> $player Player data
     * @param array<string, mixed>|null $fixture Fixture data
     * @param array<string, mixed>|null $fixtureOdds Odds data with CS probabilities
     * @return float Probability of clean sheet (0-1)
     */
    public function calculate(
        array $player,
        ?array $fixture = null,
        ?array $fixtureOdds = null
    ): float {
        // Use bookmaker odds if available (most accurate)
        if ($fixtureOdds !== null) {
            $clubId = $player['club_id'] ?? $player['team'] ?? 0;
            $isHome = $fixture && ($fixture['home_club_id'] ?? 0) === $clubId;

            $csProb = $isHome
                ? ($fixtureOdds['home_cs_prob'] ?? null)
                : ($fixtureOdds['away_cs_prob'] ?? null);

            if ($csProb !== null) {
                return round((float) $csProb, 4);
            }
        }

        // Fallback to statistical approach
        return $this->calculateFromStats($player, $fixture);
    }

    /**
     * Calculate CS probability from player/team stats.
     */
    private function calculateFromStats(array $player, ?array $fixture): float
    {
        $cleanSheets = (int) ($player['clean_sheets'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $xgc = (float) ($player['expected_goals_conceded'] ?? 0);

        // Calculate games played (approximate)
        $gamesPlayed = max(1, floor($minutes / 60));

        // Base CS rate
        $csRate = $cleanSheets / $gamesPlayed;

        // If we have xGC, use it for better accuracy
        if ($xgc > 0 && $minutes > 0) {
            $xgcPer90 = ($xgc / $minutes) * 90;
            // Lower xGC = higher CS probability
            // xGC of 1.0 per game ≈ 35% CS, xGC of 0.5 ≈ 55% CS
            $xgcProb = exp(-$xgcPer90 * 0.9);
            // Blend historical CS rate with xGC-based estimate
            $baseProb = ($csRate * 0.4) + ($xgcProb * 0.6);
        } else {
            $baseProb = $csRate;
        }

        // Adjust for fixture difficulty
        if ($fixture !== null) {
            $clubId = $player['club_id'] ?? $player['team'] ?? 0;
            $isHome = ($fixture['home_club_id'] ?? 0) === $clubId;

            $difficulty = $isHome
                ? ($fixture['home_difficulty'] ?? 3)
                : ($fixture['away_difficulty'] ?? 3);

            $difficultyMultiplier = match ((int) $difficulty) {
                1, 2 => 1.3,  // Easy fixture - high CS chance
                3 => 1.0,     // Medium
                4 => 0.75,    // Hard
                5 => 0.5,     // Very hard
                default => 1.0,
            };

            // Home advantage for clean sheets
            $homeBoost = $isHome ? 1.15 : 0.9;

            $baseProb *= $difficultyMultiplier * $homeBoost;
        }

        // Cap probability at reasonable bounds
        return round(min(0.6, max(0.05, $baseProb)), 4);
    }
}
