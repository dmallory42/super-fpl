<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

use SuperFPL\Api\Database;

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
    private AssistProbability $assistProb;
    private CleanSheetProbability $csProb;
    private BonusProbability $bonusProb;
    private DefensiveContributionProbability $dcProb;
    private CardProbability $cardProb;

    public function __construct(?Database $db = null)
    {
        $baselines = $db !== null ? new HistoricalBaselines($db) : null;
        $this->minutesProb = new MinutesProbability();
        $this->goalProb = new GoalProbability($baselines);
        $this->assistProb = new AssistProbability($baselines);
        $this->csProb = new CleanSheetProbability();
        $this->bonusProb = new BonusProbability();
        $this->dcProb = new DefensiveContributionProbability();
        $this->cardProb = new CardProbability();
    }

    /**
     * Calculate predicted points for a player in a given fixture.
     *
     * @param array<string, mixed> $player Player data
     * @param array<string, mixed>|null $fixture Fixture data
     * @param array<string, mixed>|null $fixtureOdds Odds data for the fixture
     * @param array<string, mixed>|null $goalscorerOdds Player-specific goalscorer odds
     * @param array<string, mixed>|null $assistOdds Player-specific assist odds
     * @param int $teamGames Number of games the player's team has played
     * @return array{predicted_points: float, breakdown: array<string, float>, confidence: float}
     */
    public function predict(
        array $player,
        ?array $fixture = null,
        ?array $fixtureOdds = null,
        ?array $goalscorerOdds = null,
        ?array $assistOdds = null,
        int $teamGames = 24
    ): array {
        $position = (int) ($player['position'] ?? $player['element_type'] ?? 3);

        // Calculate individual probabilities
        $minutes = $this->minutesProb->calculate($player, $teamGames);
        $goal = $this->goalProb->calculate($player, $fixture, $goalscorerOdds, $fixtureOdds);
        $expectedGoals = $goal['expected_goals'];
        $expectedAssists = $this->assistProb->calculate($player, $fixture, $assistOdds, $fixtureOdds);
        $cs = $this->csProb->calculate($player, $fixture, $fixtureOdds);
        $bonus = $this->bonusProb->calculate($player, $expectedGoals, $expectedAssists);
        $cardDeductions = $this->cardProb->calculate($player);

        // Expected points breakdown
        $breakdown = [];

        // Appearance points
        $appearancePoints = ($minutes['prob_60'] * self::APPEARANCE_POINTS_60)
            + (($minutes['prob_any'] - $minutes['prob_60']) * self::APPEARANCE_POINTS_SUB);
        $breakdown['appearance'] = round($appearancePoints, 2);

        // Goal points: expected goals * points per goal * probability of playing
        $goalPoints = $expectedGoals * $minutes['prob_any'] * (self::GOAL_POINTS[$position] ?? 4);
        $breakdown['goals'] = round($goalPoints, 2);

        // Assist points: expected assists * points per assist * probability of playing
        $assistPoints = $expectedAssists * $minutes['prob_any'] * self::ASSIST_POINTS;
        $breakdown['assists'] = round($assistPoints, 2);

        // Clean sheet points (conditional on playing 60+ mins)
        $csPoints = $cs * $minutes['prob_60'] * (self::CS_POINTS[$position] ?? 0);
        $breakdown['clean_sheet'] = round($csPoints, 2);

        // Bonus points (conditional on playing)
        $bonusPoints = $bonus * $minutes['prob_any'];
        $breakdown['bonus'] = round($bonusPoints, 2);

        // Goals conceded penalty (GK and DEF only, position <= 2)
        $gcPenalty = 0;
        if ($position <= 2) {
            $gcPenalty = $this->calculateGoalsConcededPenalty($player, $fixture, $fixtureOdds, $minutes['prob_60']);
        }
        $breakdown['goals_conceded'] = round($gcPenalty, 2);

        // Save points (GK only)
        $savePoints = 0;
        if ($position === 1) {
            $savePoints = $this->calculateSavePoints($player, $fixtureOdds, $minutes['prob_60']);
        }
        $breakdown['saves'] = round($savePoints, 2);

        // Defensive contribution points (outfield players only: DEF, MID, FWD)
        // Use conditional minutes (minsIfPlaying * prob_60), not probability-weighted expected_mins
        $dcPoints = 0;
        if ($position >= 2) {
            $minsIfPlaying = $minutes['prob_any'] > 0
                ? $minutes['expected_mins'] / $minutes['prob_any']
                : 0;
            $conditionalMins = $minsIfPlaying * $minutes['prob_60'];
            $dcPoints = $this->dcProb->calculate($player, $position, $conditionalMins);
        }
        $breakdown['defensive_contribution'] = round($dcPoints, 2);

        // Card/own goal/pen miss deductions (conditional on playing)
        $cardPoints = $cardDeductions * $minutes['prob_any'];
        $breakdown['cards'] = round($cardPoints, 2);

        // Total predicted points
        $total = $appearancePoints + $goalPoints + $assistPoints + $csPoints
            + $bonusPoints + $gcPenalty + $savePoints + $dcPoints + $cardPoints;

        // Apply calibration adjustment
        $total = $this->calibrate($total);

        // Calculate confidence based on data quality
        $confidence = $this->calculateConfidence($player, $fixtureOdds, $goalscorerOdds, $assistOdds);

        return [
            'predicted_points' => round($total, 2),
            'breakdown' => $breakdown,
            'confidence' => $confidence,
        ];
    }

    /**
     * Calculate expected penalty for goals conceded.
     * -1 point per 2 goals conceded (GK, DEF only)
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

            // Derive opponent expected goals from match odds
            $totalGoals = (float) $fixtureOdds['expected_total_goals'];
            $homeWinProb = (float) ($fixtureOdds['home_win_prob'] ?? 0.33);
            $drawProb = (float) ($fixtureOdds['draw_prob'] ?? 0.33);

            $homeShare = $homeWinProb + 0.5 * $drawProb;

            // Opponent goals
            $expectedGA = $isHome
                ? $totalGoals * (1 - $homeShare)
                : $totalGoals * $homeShare;
        }

        // -1 point per 2 goals conceded
        $penalty = -($expectedGA / 2) * $prob60;

        return max(-2, $penalty);
    }

    /**
     * Calculate expected save points for goalkeepers.
     * +1 point per 3 saves
     */
    private function calculateSavePoints(array $player, ?array $fixtureOdds, float $prob60): float
    {
        $saves = (int) ($player['saves'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);

        if ($minutes > 0) {
            $savesPer90 = ($saves / $minutes) * 90;
        } else {
            $savesPer90 = 3.0; // Average ~3 per 90
        }

        // Adjust for opponent expected goals when odds available
        if ($fixtureOdds !== null && isset($fixtureOdds['expected_total_goals'])) {
            $totalGoals = (float) $fixtureOdds['expected_total_goals'];
            // More expected goals = more shots = more saves
            $goalsMultiplier = $totalGoals / 2.5; // Normalize around league average
            $savesPer90 *= $goalsMultiplier;
        }

        // +1 point per 3 saves, conditional on playing
        return ($savesPer90 / 3) * $prob60;
    }

    /**
     * Apply piecewise linear calibration adjustment.
     *
     * Conservative initial curve:
     * - < 1.0: no change (includes 0.0 unavailable)
     * - >= 5.0: no change (already well-calibrated)
     * - 1.0-5.0: smooth upward bump centered at ~3.0, peak +0.8
     *
     * This accounts for appearance floor being deflated by conservative prob_any estimates.
     */
    private function calibrate(float $rawPoints): float
    {
        if ($rawPoints < 1.0 || $rawPoints >= 5.0) {
            return $rawPoints;
        }

        // Smooth bump using a triangular function centered at 3.0
        // Ramps up from 0 at 1.0 to 0.8 at 3.0, then back down to 0 at 5.0
        $center = 3.0;
        $peak = 0.8;

        if ($rawPoints <= $center) {
            $bump = $peak * ($rawPoints - 1.0) / ($center - 1.0);
        } else {
            $bump = $peak * (5.0 - $rawPoints) / (5.0 - $center);
        }

        return $rawPoints + $bump;
    }

    /**
     * Calculate confidence score based on data availability.
     */
    private function calculateConfidence(
        array $player,
        ?array $fixtureOdds,
        ?array $goalscorerOdds,
        ?array $assistOdds = null
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
            $confidence += 0.05;
        }
        if ($assistOdds !== null) {
            $confidence += 0.05;
        }

        return min(0.95, $confidence);
    }
}
