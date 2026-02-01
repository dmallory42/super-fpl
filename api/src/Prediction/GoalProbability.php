<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates goal-scoring probability for a player.
 */
class GoalProbability
{
    /**
     * Position-based goal weights.
     * Higher for forwards, lower for defenders.
     */
    private const POSITION_WEIGHTS = [
        1 => 0.01,  // GK - very rare
        2 => 0.15,  // DEF - occasional headers
        3 => 0.60,  // MID - regular contributors
        4 => 1.00,  // FWD - main scorers
    ];

    /**
     * Calculate goal probability for a player in a fixture.
     *
     * @param array<string, mixed> $player Player data
     * @param array<string, mixed>|null $fixture Fixture data with odds
     * @param array<string, mixed>|null $goalscorerOdds Anytime scorer odds if available
     * @return array{prob: float, expected_goals: float}
     */
    public function calculate(
        array $player,
        ?array $fixture = null,
        ?array $goalscorerOdds = null
    ): array {
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);
        $xg = (float) ($player['expected_goals'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $goalsScored = (int) ($player['goals_scored'] ?? 0);

        // Method 1: Use bookmaker odds if available (most accurate)
        if ($goalscorerOdds !== null && isset($goalscorerOdds['anytime_scorer_prob'])) {
            $oddsProb = (float) $goalscorerOdds['anytime_scorer_prob'];
            // Blend with xG for stability
            $xgProb = $this->xgToProb($xg, $minutes);
            $prob = ($oddsProb * 0.6) + ($xgProb * 0.4);

            return [
                'prob' => round($prob, 4),
                'expected_goals' => round($prob * 1.2, 4), // Slight uplift for multi-goal potential
            ];
        }

        // Method 2: Use xG per 90 (primary statistical approach)
        if ($minutes > 0 && $xg > 0) {
            $xgPer90 = ($xg / $minutes) * 90;
            $prob = $this->xgToProb($xgPer90, 90);

            // Adjust for fixture difficulty if available
            if ($fixture !== null) {
                $prob = $this->adjustForFixture($prob, $player, $fixture);
            }

            return [
                'prob' => round($prob, 4),
                'expected_goals' => round($xgPer90, 4),
            ];
        }

        // Method 3: Fallback to historical goals per game
        $gamesPlayed = max(1, (int) ($player['starts'] ?? 1));
        $goalsPerGame = $goalsScored / $gamesPlayed;
        $positionWeight = self::POSITION_WEIGHTS[$position] ?? 0.5;

        $prob = min(0.8, $goalsPerGame * $positionWeight);

        return [
            'prob' => round($prob, 4),
            'expected_goals' => round($goalsPerGame, 4),
        ];
    }

    /**
     * Convert xG to probability using Poisson distribution.
     * P(goals >= 1) = 1 - P(goals = 0) = 1 - e^(-xG)
     */
    private function xgToProb(float $xg, int $minutes): float
    {
        if ($xg <= 0 || $minutes <= 0) {
            return 0;
        }

        // Scale xG to per-match (assuming ~70 mins average playing time)
        $scaledXg = ($xg / max(1, $minutes)) * 70;

        // Poisson: P(X >= 1) = 1 - e^(-lambda)
        return 1 - exp(-$scaledXg);
    }

    /**
     * Adjust goal probability based on fixture difficulty.
     */
    private function adjustForFixture(float $baseProb, array $player, array $fixture): float
    {
        $clubId = $player['club_id'] ?? $player['team'] ?? 0;
        $isHome = ($fixture['home_club_id'] ?? 0) === $clubId;

        // Home advantage
        $homeBoost = $isHome ? 1.1 : 0.95;

        // Fixture difficulty (1-5 scale, 2 = easy, 5 = hard)
        $difficulty = $isHome
            ? ($fixture['home_difficulty'] ?? 3)
            : ($fixture['away_difficulty'] ?? 3);

        $difficultyMultiplier = match ((int) $difficulty) {
            1, 2 => 1.2,  // Easy fixture
            3 => 1.0,     // Medium
            4 => 0.85,    // Hard
            5 => 0.7,     // Very hard
            default => 1.0,
        };

        return min(0.8, $baseProb * $homeBoost * $difficultyMultiplier);
    }
}
