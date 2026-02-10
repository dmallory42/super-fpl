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
    private PenaltyProbability $penaltyProb;
    private ?TeamStrength $teamStrength;

    /** @var array<int, array<int, array{player_id: int, expected_mins: float}>> Team ID → ordered takers */
    private array $teamPenaltyTakers = [];

    public function __construct(?Database $db = null)
    {
        $baselines = $db !== null ? new HistoricalBaselines($db) : null;
        $this->teamStrength = $db !== null ? new TeamStrength($db) : null;
        $this->minutesProb = new MinutesProbability();
        $this->goalProb = new GoalProbability($baselines, $this->teamStrength);
        $this->assistProb = new AssistProbability($baselines, $this->teamStrength);
        $this->csProb = new CleanSheetProbability($this->teamStrength);
        $this->bonusProb = new BonusProbability();
        $this->dcProb = new DefensiveContributionProbability();
        $this->cardProb = new CardProbability();
        $this->penaltyProb = new PenaltyProbability();
    }

    /**
     * Pre-compute penalty taker chains for all teams.
     *
     * Must be called before predict() for penalty points to use the chain model.
     * Each team's takers are ordered by penalty_order with their expected_mins
     * pre-computed via MinutesProbability.
     *
     * @param array<int, array<string, mixed>> $allPlayers All players from DB
     * @param array<int, int> $teamGames Club ID → games played
     */
    public function precomputePenaltyTakers(array $allPlayers, array $teamGames): void
    {
        // Collect players with penalty_order, grouped by team
        $byTeam = [];
        foreach ($allPlayers as $player) {
            $penOrder = $player['penalty_order'] ?? null;
            if ($penOrder === null || (int) $penOrder <= 0) {
                continue;
            }

            $clubId = (int) ($player['club_id'] ?? $player['team'] ?? 0);
            $playerId = (int) $player['id'];

            // Calculate expected minutes for this taker
            $tg = $teamGames[$clubId] ?? 24;
            $minutes = $this->minutesProb->calculate($player, $tg);
            $expectedMins = $minutes['expected_mins'];

            // Apply xmins_override if set
            if (isset($player['xmins_override']) && $player['xmins_override'] !== null) {
                $expectedMins = (float) $player['xmins_override'];
            }

            $byTeam[$clubId][] = [
                'player_id' => $playerId,
                'expected_mins' => $expectedMins,
                'penalty_order' => (int) $penOrder,
            ];
        }

        // Sort each team's takers by penalty_order
        foreach ($byTeam as $clubId => &$takers) {
            usort($takers, fn($a, $b) => $a['penalty_order'] <=> $b['penalty_order']);
        }
        unset($takers);

        $this->teamPenaltyTakers = $byTeam;
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
        $minutesFit = $this->minutesProb->calculate($player, $teamGames, ignoreAvailability: true);
        $goal = $this->goalProb->calculate($player, $fixture, $goalscorerOdds, $fixtureOdds);
        $expectedGoals = $goal['expected_goals'];
        $expectedAssists = $this->assistProb->calculate($player, $fixture, $assistOdds, $fixtureOdds);
        $cs = $this->csProb->calculate($player, $fixture, $fixtureOdds);
        $bonus = $this->bonusProb->calculate($player, $expectedGoals, $expectedAssists);
        $cardDeductions = $this->cardProb->calculate($player);

        // Expected points breakdown
        $breakdown = [];

        // Per-game minutes fraction: converts per-90 rates to per-game values,
        // accounting for both probability of playing and minutes per appearance.
        // e.g. a 75-min player with 0.98 prob_any: 0.98 * 75/90 = 0.817
        $minutesFraction = $minutes['expected_mins'] / 90;

        // Appearance points (based on playing probability, not per-90 rates)
        $appearancePoints = ($minutes['prob_60'] * self::APPEARANCE_POINTS_60)
            + (($minutes['prob_any'] - $minutes['prob_60']) * self::APPEARANCE_POINTS_SUB);
        $breakdown['appearance'] = round($appearancePoints, 2);

        // Goal points: expected goals per 90 * minutes fraction * points per goal
        $goalPoints = $expectedGoals * $minutesFraction * (self::GOAL_POINTS[$position] ?? 4);
        $breakdown['goals'] = round($goalPoints, 2);

        // Assist points: expected assists per 90 * minutes fraction * points per assist
        $assistPoints = $expectedAssists * $minutesFraction * self::ASSIST_POINTS;
        $breakdown['assists'] = round($assistPoints, 2);

        // Clean sheet points (conditional on playing 60+ mins — not a per-90 rate)
        $csPoints = $cs * $minutes['prob_60'] * (self::CS_POINTS[$position] ?? 0);
        $breakdown['clean_sheet'] = round($csPoints, 2);

        // Bonus points (scales with playing time)
        $bonusPoints = $bonus * $minutesFraction;
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
            $savePoints = $this->calculateSavePoints($player, $fixture, $fixtureOdds, $minutes['prob_60']);
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

        // Card/own goal/pen miss deductions (scales with playing time)
        $cardPoints = $cardDeductions * $minutesFraction;
        $breakdown['cards'] = round($cardPoints, 2);

        // Penalty taker points (chain model: depends on takers above being off pitch)
        $clubId = (int) ($player['club_id'] ?? $player['team'] ?? 0);
        $teamTakers = $this->teamPenaltyTakers[$clubId] ?? [];
        $penaltyPoints = $this->penaltyProb->calculate($player, $teamTakers);
        // penaltyPoints already accounts for on-pitch probability via the chain model,
        // so we don't multiply by minutesFraction again
        $breakdown['penalties'] = round($penaltyPoints, 2);

        // Total predicted points
        $total = $appearancePoints + $goalPoints + $assistPoints + $csPoints
            + $bonusPoints + $gcPenalty + $savePoints + $dcPoints + $cardPoints + $penaltyPoints;

        // If-fit baseline: what this player would score if fully available.
        // Uses the same per-90 rates but with "if fit" minutes probabilities
        // (availability forced to 1.0). Used by the frontend for ratio scaling
        // when xMins is overridden (especially for injured players).
        $ifFitFraction = $minutesFit['expected_mins'] / 90;
        $ifFitAppearance = ($minutesFit['prob_60'] * self::APPEARANCE_POINTS_60)
            + (($minutesFit['prob_any'] - $minutesFit['prob_60']) * self::APPEARANCE_POINTS_SUB);
        $ifFitGoals = $expectedGoals * $ifFitFraction * (self::GOAL_POINTS[$position] ?? 4);
        $ifFitAssists = $expectedAssists * $ifFitFraction * self::ASSIST_POINTS;
        $ifFitCS = $cs * $minutesFit['prob_60'] * (self::CS_POINTS[$position] ?? 0);
        $ifFitBonus = $bonus * $ifFitFraction;
        $ifFitGC = $position <= 2
            ? $this->calculateGoalsConcededPenalty($player, $fixture, $fixtureOdds, $minutesFit['prob_60'])
            : 0;
        $ifFitSaves = $position === 1
            ? $this->calculateSavePoints($player, $fixture, $fixtureOdds, $minutesFit['prob_60'])
            : 0;
        $ifFitMinsIfPlaying = $minutesFit['prob_any'] > 0
            ? $minutesFit['expected_mins'] / $minutesFit['prob_any']
            : 0;
        $ifFitDC = $position >= 2
            ? $this->dcProb->calculate($player, $position, $ifFitMinsIfPlaying * $minutesFit['prob_60'])
            : 0;
        $ifFitCards = $cardDeductions * $ifFitFraction;
        $ifFitTotal = $ifFitAppearance + $ifFitGoals + $ifFitAssists + $ifFitCS
            + $ifFitBonus + $ifFitGC + $ifFitSaves + $ifFitDC + $ifFitCards
            + $penaltyPoints;

        // No post-hoc calibration — collect snapshots vs actuals to identify
        // systematic bias empirically before adding any correction.

        // Calculate confidence based on data quality
        $confidence = $this->calculateConfidence($player, $fixtureOdds, $goalscorerOdds, $assistOdds);

        return [
            'predicted_points' => round($total, 2),
            'predicted_if_fit' => round($ifFitTotal, 2),
            'expected_mins' => round($minutes['expected_mins'], 1),
            'expected_mins_if_fit' => round($minutesFit['expected_mins'], 1),
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
        } elseif ($fixture !== null && $this->teamStrength !== null && $this->teamStrength->isAvailable()) {
            $clubId = $player['club_id'] ?? $player['team'] ?? 0;
            $isHome = $fixture && ($fixture['home_club_id'] ?? 0) === $clubId;
            $opponentId = $isHome ? ($fixture['away_club_id'] ?? 0) : ($fixture['home_club_id'] ?? 0);
            $rawGA = $this->teamStrength->getExpectedGoalsAgainst($clubId, $opponentId, $isHome);
            // Shrink toward league average
            $expectedGA = $expectedGA + ($rawGA - $expectedGA) * 0.5;
        }

        // -1 point per 2 goals conceded
        $penalty = -($expectedGA / 2) * $prob60;

        return max(-2, $penalty);
    }

    /**
     * Calculate expected save points for goalkeepers.
     * +1 point per 3 saves
     */
    private function calculateSavePoints(array $player, ?array $fixture, ?array $fixtureOdds, float $prob60): float
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
        } elseif ($fixture !== null && $this->teamStrength !== null && $this->teamStrength->isAvailable()) {
            $clubId = $player['club_id'] ?? $player['team'] ?? 0;
            $isHome = $fixture && ($fixture['home_club_id'] ?? 0) === $clubId;
            $opponentId = $isHome ? ($fixture['away_club_id'] ?? 0) : ($fixture['home_club_id'] ?? 0);
            $oppXG = $this->teamStrength->getExpectedGoalsAgainst($clubId, $opponentId, $isHome);
            $rawMultiplier = $oppXG / $this->teamStrength->getLeagueAvgXGF();
            $goalsMultiplier = 1.0 + ($rawMultiplier - 1.0) * 0.5;
            $savesPer90 *= $goalsMultiplier;
        }

        // +1 point per 3 saves, conditional on playing
        return ($savesPer90 / 3) * $prob60;
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
        } elseif ($this->teamStrength !== null && $this->teamStrength->isAvailable()) {
            $confidence += 0.05; // Less than odds since team strength is weaker signal
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
