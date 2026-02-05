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
     * @param array<string, mixed>|null $fixture Fixture data
     * @param array<string, mixed>|null $goalscorerOdds Anytime scorer odds if available
     * @param array<string, mixed>|null $fixtureOdds Fixture odds with win probabilities
     * @return array{prob: float, expected_goals: float}
     */
    public function calculate(
        array $player,
        ?array $fixture = null,
        ?array $goalscorerOdds = null,
        ?array $fixtureOdds = null
    ): array {
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);
        $xg = (float) ($player['expected_goals'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $goalsScored = (int) ($player['goals_scored'] ?? 0);

        // Method 1: Use bookmaker odds if available (most accurate)
        if ($goalscorerOdds !== null && isset($goalscorerOdds['anytime_scorer_prob'])) {
            $oddsProb = (float) $goalscorerOdds['anytime_scorer_prob'];

            // Calculate xG per 95 for blending (95 mins = full match with injury time)
            $xgPer95 = ($minutes > 0 && $xg > 0) ? ($xg / $minutes) * 95 : 0;
            $xgProb = $this->xgToProb($xgPer95);

            // Blend: 60% bookmaker odds, 40% xG-based
            $prob = ($oddsProb * 0.6) + ($xgProb * 0.4);

            return [
                'prob' => round($prob, 4),
                'expected_goals' => round($xgPer95, 4),
            ];
        }

        // Method 2: Use xG per 95 (primary statistical approach, 95 mins = full match)
        if ($minutes > 0 && $xg > 0) {
            $xgPer95 = ($xg / $minutes) * 95;
            $prob = $this->xgToProb($xgPer95);

            // Adjust for fixture using odds if available
            if ($fixture !== null) {
                $prob = $this->adjustForFixture($prob, $player, $fixture, $fixtureOdds);
            }

            return [
                'prob' => round($prob, 4),
                'expected_goals' => round($xgPer95, 4),
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
     * Convert xG per match to probability using Poisson distribution.
     * P(goals >= 1) = 1 - P(goals = 0) = 1 - e^(-xG)
     *
     * Note: We use full per-match rate (95 mins) since minutes probability
     * already accounts for whether the player will play. A nailed starter
     * with 0.8 xG/95 should have ~55% goal probability per game.
     */
    private function xgToProb(float $xgPerMatch): float
    {
        if ($xgPerMatch <= 0) {
            return 0;
        }

        // Poisson: P(X >= 1) = 1 - e^(-lambda)
        return 1 - exp(-$xgPerMatch);
    }

    /**
     * Adjust goal probability based on fixture odds.
     *
     * Uses bookmaker win probability to derive attack multiplier.
     * Falls back to neutral multiplier when no odds available.
     *
     * @param array<string, mixed>|null $fixtureOdds Fixture odds data
     */
    private function adjustForFixture(
        float $baseProb,
        array $player,
        array $fixture,
        ?array $fixtureOdds = null
    ): float {
        $clubId = $player['club_id'] ?? $player['team'] ?? 0;
        $isHome = ($fixture['home_club_id'] ?? 0) === $clubId;

        $attackMultiplier = 1.0;

        // Use odds-based multiplier if available
        if ($fixtureOdds !== null) {
            $winProb = $isHome
                ? (float) ($fixtureOdds['home_win_prob'] ?? 0.33)
                : (float) ($fixtureOdds['away_win_prob'] ?? 0.33);

            // Win prob ranges ~0.15 (weak vs strong) to ~0.65 (strong vs weak)
            // Map to multiplier range 0.75 - 1.25
            $attackMultiplier = 0.75 + ($winProb * 0.77);
        }

        // Home advantage (+10%)
        if ($isHome) {
            $attackMultiplier *= 1.1;
        }

        return min(0.8, $baseProb * $attackMultiplier);
    }
}
