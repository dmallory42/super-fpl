<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates expected penalty points for a player using a chain model.
 *
 * Model:
 *   expectedPenPoints = teamPenRate * takePct * expectedPointsPerPen
 *
 * Where:
 *   - teamPenRate: average penalties awarded to a team per match (~0.15 in EPL)
 *   - takePct: conditional probability this player takes the pen, derived from
 *     the on-pitch fractions of all takers ranked above them
 *   - expectedPointsPerPen: conversionRate * goalPoints + missRate * (-2)
 *
 * Chain model (using f = expected_mins / 90 as on-pitch fraction):
 *   P(taker 1 takes) = f₁
 *   P(taker 2 takes) = (1 - f₁) × f₂
 *   P(taker 3 takes) = (1 - f₁) × (1 - f₂) × f₃
 *
 * This captures the real-world dynamic where a backup taker's value depends
 * on whether the primary taker is on the pitch at the moment a pen is awarded.
 */
class PenaltyProbability
{
    /**
     * Average penalties awarded per team per EPL match.
     * ~120 penalties per season / 380 matches * 2 teams = ~0.158 per team per match.
     */
    private const TEAM_PEN_RATE = 0.15;

    /**
     * Prior conversion rate for designated penalty takers.
     * Slightly above league average because this model only runs for players
     * with penalty_order (i.e. nominated takers).
     */
    private const PRIOR_CONVERSION_RATE = 0.80;

    /**
     * Prior sample size for Bayesian shrinkage.
     * Prevents short-term miss streaks from overreacting.
     */
    private const PRIOR_ATTEMPTS = 20.0;

    /** FPL deduction for a missed penalty. */
    private const MISS_DEDUCTION = -2;

    /** FPL goal points by position. */
    private const GOAL_POINTS = [1 => 6, 2 => 6, 3 => 5, 4 => 4];

    /**
     * Calculate expected penalty points per match.
     *
     * @param array<string, mixed> $player Player data (needs id, penalty_order, position)
     * @param array<int, array{player_id: int, expected_mins: float}> $teamTakers
     *        Team's penalty takers ordered by penalty_order, with pre-computed expected_mins
     * @return float Expected points from penalties per match
     */
    public function calculate(array $player, array $teamTakers): float
    {
        $penaltyOrder = $player['penalty_order'] ?? null;

        if ($penaltyOrder === null || (int) $penaltyOrder <= 0) {
            return 0.0;
        }

        if (empty($teamTakers)) {
            return 0.0;
        }

        $playerId = (int) ($player['id'] ?? 0);
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);

        // Calculate this player's take probability using the chain model
        $takePct = $this->calculateTakePct($playerId, $teamTakers);

        if ($takePct <= 0) {
            return 0.0;
        }

        $goalPoints = self::GOAL_POINTS[$position] ?? 4;
        $conversionRate = $this->estimateConversionRate($player);

        // Expected points per penalty attempt:
        // P(score) * goalPoints + P(miss) * (-2)
        $expectedPerPen = ($conversionRate * $goalPoints)
            + ((1 - $conversionRate) * self::MISS_DEDUCTION);

        // Expected penalty points per match:
        // teamPenRate * P(this player takes it) * expectedPerPen
        return round(self::TEAM_PEN_RATE * $takePct * $expectedPerPen, 4);
    }

    /**
     * Estimate player penalty conversion with Bayesian shrinkage.
     *
     * Uses explicit penalties_taken when available, otherwise infers converted
     * penalties as goals_scored - npg when Understat NPG is present.
     */
    private function estimateConversionRate(array $player): float
    {
        $penMissed = max(0, (int) ($player['penalties_missed'] ?? 0));
        $penTaken = max(0, (int) ($player['penalties_taken'] ?? 0));

        $attempts = 0;
        $scored = 0;

        if ($penTaken > 0) {
            $attempts = $penTaken;
            $scored = max(0, $penTaken - $penMissed);
        } else {
            $goals = max(0, (int) ($player['goals_scored'] ?? 0));
            $npgRaw = $player['npg'] ?? null;
            if ($npgRaw !== null && is_numeric($npgRaw)) {
                $npg = max(0, (int) round((float) $npgRaw));
                $scored = max(0, $goals - $npg);
                $attempts = $scored + $penMissed;
            }
        }

        $priorSuccess = self::PRIOR_ATTEMPTS * self::PRIOR_CONVERSION_RATE;
        $posterior = ($priorSuccess + $scored) / (self::PRIOR_ATTEMPTS + $attempts);

        return min(0.95, max(0.60, $posterior));
    }

    /**
     * Calculate the probability this player takes a penalty using the chain model.
     *
     * For each taker in order, their take probability is the product of all
     * higher-ranked takers NOT being on the pitch, times this taker being on the pitch.
     *
     * @param int $playerId The player to calculate for
     * @param array<int, array{player_id: int, expected_mins: float}> $teamTakers
     *        Ordered by penalty_order (index 0 = primary, 1 = backup, etc.)
     * @return float Probability this player takes the pen (0.0 to 1.0)
     */
    private function calculateTakePct(int $playerId, array $teamTakers): float
    {
        // Walk through the chain — accumulate P(all above are off pitch)
        $probAllAboveOff = 1.0;

        foreach ($teamTakers as $taker) {
            $f = min(1.0, max(0.0, $taker['expected_mins'] / 90.0));

            if ((int) $taker['player_id'] === $playerId) {
                // This is our player — their takePct = P(all above off) * P(this player on)
                return $probAllAboveOff * $f;
            }

            // This taker is ranked above our player — multiply by P(this taker NOT on pitch)
            $probAllAboveOff *= (1.0 - $f);
        }

        // Player not found in team takers list
        return 0.0;
    }
}
