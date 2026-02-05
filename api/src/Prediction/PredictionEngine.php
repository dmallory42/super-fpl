<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Main prediction engine that combines all probability models
 * to calculate expected FPL points for a player.
 */
class PredictionEngine
{
    /**
     * FPL scoring rules by position.
     * Position: 1=GK, 2=DEF, 3=MID, 4=FWD
     */
    private const GOAL_POINTS = [1 => 6, 2 => 6, 3 => 5, 4 => 4];
    private const CS_POINTS = [1 => 4, 2 => 4, 3 => 1, 4 => 0];
    private const ASSIST_POINTS = 3;
    private const APPEARANCE_POINTS_60 = 2;
    private const APPEARANCE_POINTS_SUB = 1;

    private MinutesProbability $minutesProb;
    private GoalProbability $goalProb;
    private CleanSheetProbability $csProb;
    private BonusProbability $bonusProb;
    private DefensiveContributionProbability $dcProb;

    public function __construct()
    {
        $this->minutesProb = new MinutesProbability();
        $this->goalProb = new GoalProbability();
        $this->csProb = new CleanSheetProbability();
        $this->bonusProb = new BonusProbability();
        $this->dcProb = new DefensiveContributionProbability();
    }

    /**
     * Calculate predicted points for a player in a given fixture.
     *
     * @param array<string, mixed> $player Player data
     * @param array<string, mixed>|null $fixture Fixture data
     * @param array<string, mixed>|null $fixtureOdds Odds data for the fixture
     * @param array<string, mixed>|null $goalscorerOdds Player-specific goalscorer odds
     * @return array{predicted_points: float, breakdown: array<string, float>, confidence: float}
     */
    public function predict(
        array $player,
        ?array $fixture = null,
        ?array $fixtureOdds = null,
        ?array $goalscorerOdds = null
    ): array {
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);

        // Calculate individual probabilities
        $minutes = $this->minutesProb->calculate($player);
        $goal = $this->goalProb->calculate($player, $fixture, $goalscorerOdds, $fixtureOdds);
        $cs = $this->csProb->calculate($player, $fixture, $fixtureOdds);
        $bonus = $this->bonusProb->calculate($player);

        // Expected points breakdown
        $breakdown = [];

        // Appearance points
        $appearancePoints = ($minutes['prob_60'] * self::APPEARANCE_POINTS_60)
            + (($minutes['prob_any'] - $minutes['prob_60']) * self::APPEARANCE_POINTS_SUB);
        $breakdown['appearance'] = round($appearancePoints, 2);

        // Goal points (conditional on playing)
        $goalPoints = $goal['prob'] * $minutes['prob_any'] * (self::GOAL_POINTS[$position] ?? 4);
        $breakdown['goals'] = round($goalPoints, 2);

        // Assist points
        $assistProb = $this->calculateAssistProb($player, $fixture, $fixtureOdds);
        $assistPoints = $assistProb * $minutes['prob_any'] * self::ASSIST_POINTS;
        $breakdown['assists'] = round($assistPoints, 2);

        // Clean sheet points (conditional on playing 60+ mins)
        $csPoints = $cs * $minutes['prob_60'] * (self::CS_POINTS[$position] ?? 0);
        $breakdown['clean_sheet'] = round($csPoints, 2);

        // Bonus points (conditional on playing)
        $bonusPoints = $bonus * $minutes['prob_any'];
        $breakdown['bonus'] = round($bonusPoints, 2);

        // Goals conceded penalty (for GK, DEF, MID)
        $gcPenalty = 0;
        if ($position <= 3) {
            $gcPenalty = $this->calculateGoalsConcededPenalty($player, $fixture, $fixtureOdds, $minutes['prob_60']);
        }
        $breakdown['goals_conceded'] = round($gcPenalty, 2);

        // Save points (GK only)
        $savePoints = 0;
        if ($position === 1) {
            $savePoints = $this->calculateSavePoints($player, $fixture, $minutes['prob_60']);
        }
        $breakdown['saves'] = round($savePoints, 2);

        // Defensive contribution points (outfield players only: DEF, MID, FWD)
        $dcPoints = 0;
        if ($position >= 2) {
            $dcPoints = $this->dcProb->calculate($player, $position, $minutes['expected_mins']);
        }
        $breakdown['defensive_contribution'] = round($dcPoints, 2);

        // Total predicted points
        $total = $appearancePoints + $goalPoints + $assistPoints + $csPoints
            + $bonusPoints + $gcPenalty + $savePoints + $dcPoints;

        // Calculate confidence based on data quality
        $confidence = $this->calculateConfidence($player, $fixtureOdds, $goalscorerOdds);

        return [
            'predicted_points' => round($total, 2),
            'breakdown' => $breakdown,
            'confidence' => $confidence,
        ];
    }

    /**
     * Calculate assist probability based on xA and historical data.
     * Uses odds-based adjustments instead of FPL difficulty ratings.
     */
    private function calculateAssistProb(array $player, ?array $fixture, ?array $fixtureOdds = null): float
    {
        $xa = (float) ($player['expected_assists'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $assists = (int) ($player['assists'] ?? 0);

        if ($minutes > 0 && $xa > 0) {
            $xaPer95 = ($xa / $minutes) * 95;  // 95 mins = full match with injury time
            // Use Poisson for P(assists >= 1)
            $prob = 1 - exp(-$xaPer95);
        } else {
            // Fallback to historical
            $gamesPlayed = max(1, (int) ($player['starts'] ?? 1));
            $prob = min(0.5, $assists / $gamesPlayed);
        }

        // Adjust for fixture using odds if available
        if ($fixture !== null) {
            $clubId = $player['club_id'] ?? $player['team'] ?? 0;
            $isHome = ($fixture['home_club_id'] ?? 0) === $clubId;

            $multiplier = 1.0;

            // Use odds-based multiplier if available
            if ($fixtureOdds !== null) {
                $winProb = $isHome
                    ? (float) ($fixtureOdds['home_win_prob'] ?? 0.33)
                    : (float) ($fixtureOdds['away_win_prob'] ?? 0.33);

                // Win prob ranges ~0.15 to ~0.65
                // Map to multiplier range 0.8 - 1.15
                $multiplier = 0.8 + ($winProb * 0.54);
            }

            // Home advantage (+5%)
            if ($isHome) {
                $multiplier *= 1.05;
            }

            $prob *= $multiplier;
        }

        return min(0.6, $prob);
    }

    /**
     * Calculate expected penalty for goals conceded.
     * -1 point per 2 goals conceded (GK, DEF, MID)
     */
    private function calculateGoalsConcededPenalty(
        array $player,
        ?array $fixture,
        ?array $fixtureOdds,
        float $prob60
    ): float {
        // Estimate expected goals against
        $expectedGA = 1.3; // League average

        if ($fixtureOdds !== null && isset($fixtureOdds['expected_total_goals'])) {
            $clubId = $player['club_id'] ?? $player['team'] ?? 0;
            $isHome = $fixture && ($fixture['home_club_id'] ?? 0) === $clubId;

            // Estimate team's share of goals conceded
            $totalGoals = (float) $fixtureOdds['expected_total_goals'];
            $winProb = $isHome
                ? ($fixtureOdds['home_win_prob'] ?? 0.33)
                : ($fixtureOdds['away_win_prob'] ?? 0.33);

            // Better team concedes fewer goals
            $expectedGA = $totalGoals * (1 - $winProb) * 0.6;
        }

        // -1 point per 2 goals conceded
        $penalty = -($expectedGA / 2) * $prob60;

        return max(-2, $penalty); // Cap at -2
    }

    /**
     * Calculate expected save points for goalkeepers.
     * +1 point per 3 saves
     */
    private function calculateSavePoints(array $player, ?array $fixture, float $prob60): float
    {
        $saves = (int) ($player['saves'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);

        if ($minutes > 0) {
            $savesPer95 = ($saves / $minutes) * 95;  // 95 mins = full match with injury time
        } else {
            $savesPer95 = 3.2; // Average (~3 per 90, scaled to 95)
        }

        // +1 point per 3 saves, conditional on playing
        return ($savesPer95 / 3) * $prob60;
    }

    /**
     * Calculate confidence score based on data availability.
     */
    private function calculateConfidence(
        array $player,
        ?array $fixtureOdds,
        ?array $goalscorerOdds
    ): float {
        $confidence = 0.5; // Base confidence

        // More minutes = more reliable data
        $minutes = (int) ($player['minutes'] ?? 0);
        if ($minutes > 450) {
            $confidence += 0.15;
        } elseif ($minutes > 270) {
            $confidence += 0.1;
        }

        // xG data available
        if (($player['expected_goals'] ?? 0) > 0) {
            $confidence += 0.1;
        }

        // Odds data available
        if ($fixtureOdds !== null) {
            $confidence += 0.1;
        }
        if ($goalscorerOdds !== null) {
            $confidence += 0.1;
        }

        return min(0.95, $confidence);
    }
}
