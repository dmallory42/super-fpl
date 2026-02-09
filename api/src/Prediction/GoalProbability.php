<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates goal-scoring probability for a player.
 *
 * Priority system (odds-first):
 * 1. Scorer odds → inverse Poisson, blended 90% odds / 10% season xG (regressed)
 * 2. Season xG/90 regressed toward career baseline, fixture-adjusted from match odds
 * 3. Historical goals/90 (actual goals per 90, no position weight suppression)
 */
class GoalProbability
{
    private ?HistoricalBaselines $baselines;
    private ?TeamStrength $teamStrength;

    public function __construct(?HistoricalBaselines $baselines = null, ?TeamStrength $teamStrength = null)
    {
        $this->baselines = $baselines;
        $this->teamStrength = $teamStrength;
    }

    /**
     * Calculate expected goals for a player in a fixture.
     *
     * @param array<string, mixed> $player Player data
     * @param array<string, mixed>|null $fixture Fixture data
     * @param array<string, mixed>|null $goalscorerOdds Anytime scorer odds
     * @param array<string, mixed>|null $fixtureOdds Fixture odds with win probabilities
     * @return array{expected_goals: float}
     */
    public function calculate(
        array $player,
        ?array $fixture = null,
        ?array $goalscorerOdds = null,
        ?array $fixtureOdds = null
    ): array {
        $xg = (float) ($player['expected_goals'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $goalsScored = (int) ($player['goals_scored'] ?? 0);
        $playerCode = (int) ($player['code'] ?? 0);

        // Use non-penalty xG from Understat when available
        $npxg = isset($player['npxg']) ? (float) $player['npxg'] : null;
        $openPlayXG = ($npxg !== null && $npxg > 0) ? $npxg : $xg;

        // Method 1: Use bookmaker odds (most accurate, odds-first)
        if ($goalscorerOdds !== null && isset($goalscorerOdds['anytime_scorer_prob'])) {
            $oddsProb = (float) $goalscorerOdds['anytime_scorer_prob'];

            // Convert P(>=1 goal) back to expected goals: λ = -ln(1 - p)
            $oddsXG = $oddsProb < 0.99 ? -log(1 - $oddsProb) : 2.5;

            // Calculate season npxG/90 regressed to career baseline
            $seasonXgPer90 = $this->getRegressedXgPer90($openPlayXG, $minutes, $playerCode);

            // Blend: 90% bookmaker-derived, 10% season xG (regressed)
            $expectedGoals = ($oddsXG * 0.9) + ($seasonXgPer90 * 0.1);

            return [
                'expected_goals' => round($expectedGoals, 4),
            ];
        }

        // Method 2: Use npxG per 90, regressed to career baseline, fixture-adjusted
        if ($minutes > 0 && $openPlayXG > 0) {
            $xgPer90 = $this->getRegressedXgPer90($openPlayXG, $minutes, $playerCode);

            // Fixture adjustment using match odds (no arbitrary multiplier)
            if ($fixture !== null && $fixtureOdds !== null) {
                $xgPer90 = $this->adjustForFixtureFromOdds($xgPer90, $player, $fixture, $fixtureOdds);
            } elseif ($fixture !== null && $this->teamStrength !== null && $this->teamStrength->isAvailable()) {
                $xgPer90 = $this->adjustForFixtureFromStrength($xgPer90, $player, $fixture);
            }

            return [
                'expected_goals' => round($xgPer90, 4),
            ];
        }

        // Method 3: Historical goals per 90 (no position weight suppression)
        if ($minutes > 0 && $goalsScored > 0) {
            $goalsPer90 = ($goalsScored / $minutes) * 90;
            return [
                'expected_goals' => round(min(1.5, $goalsPer90), 4),
            ];
        }

        // Fallback: use starts if available
        $gamesPlayed = max(1, (int) ($player['starts'] ?? 1));
        $goalsPerGame = $goalsScored / $gamesPlayed;

        return [
            'expected_goals' => round(min(1.5, $goalsPerGame), 4),
        ];
    }

    /**
     * Get xG/90 regressed toward career baseline via HistoricalBaselines.
     */
    private function getRegressedXgPer90(float $seasonXg, int $minutes, int $playerCode): float
    {
        if ($minutes <= 0) {
            return 0.0;
        }

        $currentRate = ($seasonXg / $minutes) * 90;

        if ($this->baselines !== null && $playerCode > 0) {
            $historicalRate = $this->baselines->getXgPer90($playerCode);
            if ($historicalRate > 0) {
                return $this->baselines->getEffectiveRate($currentRate, $historicalRate, $minutes);
            }
        }

        // No historical data: apply simple reliability discount for low minutes
        if ($minutes < 270) {
            $reliability = $minutes / 270;
            return $currentRate * $reliability;
        }

        return $currentRate;
    }

    /**
     * Fixture adjustment using team strength (fallback when no odds).
     */
    private function adjustForFixtureFromStrength(float $baseXG, array $player, array $fixture): float
    {
        $clubId = $player['club_id'] ?? $player['team'] ?? 0;
        $isHome = ($fixture['home_club_id'] ?? 0) === $clubId;
        $opponentId = $isHome ? ($fixture['away_club_id'] ?? 0) : ($fixture['home_club_id'] ?? 0);

        $teamXG = $this->teamStrength->getExpectedGoals($clubId, $opponentId, $isHome);
        $leagueAvg = $this->teamStrength->getLeagueAvgXGF();
        $rawMultiplier = $teamXG / $leagueAvg;

        // Shrink toward 1.0 — xG-based strength is noisier than odds
        $multiplier = 1.0 + ($rawMultiplier - 1.0) * 0.5;

        return min(2.0, $baseXG * $multiplier);
    }

    /**
     * Fixture adjustment using match odds.
     *
     * Derives team expected goals from total goals and home/away share,
     * then scales player xG proportionally.
     */
    private function adjustForFixtureFromOdds(
        float $baseXG,
        array $player,
        array $fixture,
        array $fixtureOdds
    ): float {
        $clubId = $player['club_id'] ?? $player['team'] ?? 0;
        $isHome = ($fixture['home_club_id'] ?? 0) === $clubId;

        $totalGoals = (float) ($fixtureOdds['expected_total_goals'] ?? 2.5);
        $homeWinProb = (float) ($fixtureOdds['home_win_prob'] ?? 0.33);
        $drawProb = (float) ($fixtureOdds['draw_prob'] ?? 0.33);

        // Derive team's share of goals from match probabilities
        $homeShare = $homeWinProb + 0.5 * $drawProb;
        $teamXG = $totalGoals * ($isHome ? $homeShare : 1 - $homeShare);

        // League average team xG per match ≈ 1.25
        $leagueAvgTeamXG = 1.25;

        $rawMultiplier = $teamXG / $leagueAvgTeamXG;

        // Shrink toward 1.0 — team-level xG boost doesn't distribute
        // equally across all players (top attackers capture more)
        $multiplier = 1.0 + ($rawMultiplier - 1.0) * 0.5;

        return min(2.0, $baseXG * $multiplier);
    }
}
