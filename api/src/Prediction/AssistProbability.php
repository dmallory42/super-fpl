<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates assist probability for a player.
 *
 * Same 3-tier priority as GoalProbability:
 * 1. Assist odds → inverse Poisson (new market)
 * 2. Season xA/90 regressed to career baseline, fixture-adjusted from match odds
 * 3. Historical assists per 90 minutes
 */
class AssistProbability
{
    private ?HistoricalBaselines $baselines;

    public function __construct(?HistoricalBaselines $baselines = null)
    {
        $this->baselines = $baselines;
    }

    /**
     * Calculate expected assists for a player in a fixture.
     *
     * @param array<string, mixed> $player Player data
     * @param array<string, mixed>|null $fixture Fixture data
     * @param array<string, mixed>|null $assistOdds Anytime assist odds
     * @param array<string, mixed>|null $fixtureOdds Fixture odds with win probabilities
     * @return float Expected assists
     */
    public function calculate(
        array $player,
        ?array $fixture = null,
        ?array $assistOdds = null,
        ?array $fixtureOdds = null
    ): float {
        $xa = (float) ($player['expected_assists'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $assists = (int) ($player['assists'] ?? 0);
        $playerCode = (int) ($player['code'] ?? 0);

        // Method 1: Use bookmaker assist odds (odds-first)
        if ($assistOdds !== null && isset($assistOdds['anytime_assist_prob'])) {
            $oddsProb = (float) $assistOdds['anytime_assist_prob'];

            // Convert P(>=1 assist) to expected assists: λ = -ln(1 - p)
            $oddsXA = $oddsProb < 0.99 ? -log(1 - $oddsProb) : 2.5;

            // Calculate season xA/90 regressed to career baseline
            $seasonXaPer90 = $this->getRegressedXaPer90($xa, $minutes, $playerCode);

            // Blend: 90% bookmaker-derived, 10% season xA (regressed)
            $expectedAssists = ($oddsXA * 0.9) + ($seasonXaPer90 * 0.1);

            return round(min(1.5, $expectedAssists), 4);
        }

        // Method 2: Use xA per 90, regressed to career baseline, fixture-adjusted
        if ($minutes > 0 && $xa > 0) {
            $xaPer90 = $this->getRegressedXaPer90($xa, $minutes, $playerCode);

            // Fixture adjustment using match odds
            if ($fixture !== null && $fixtureOdds !== null) {
                $xaPer90 = $this->adjustForFixtureFromOdds($xaPer90, $player, $fixture, $fixtureOdds);
            }

            return round(min(1.5, $xaPer90), 4);
        }

        // Method 3: Historical assists per 90 minutes
        if ($minutes > 0 && $assists > 0) {
            $assistsPer90 = ($assists / $minutes) * 90;
            return round(min(1.5, $assistsPer90), 4);
        }

        // Fallback: use starts if available
        $gamesPlayed = max(1, (int) ($player['starts'] ?? 1));
        $assistsPerGame = $assists / $gamesPlayed;

        return round(min(0.8, $assistsPerGame), 4);
    }

    /**
     * Get xA/90 regressed toward career baseline.
     */
    private function getRegressedXaPer90(float $seasonXa, int $minutes, int $playerCode): float
    {
        if ($minutes <= 0) {
            return 0.0;
        }

        $currentRate = ($seasonXa / $minutes) * 90;

        if ($this->baselines !== null && $playerCode > 0) {
            $historicalRate = $this->baselines->getXaPer90($playerCode);
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
     * Fixture adjustment using match odds (same approach as GoalProbability).
     */
    private function adjustForFixtureFromOdds(
        float $baseXA,
        array $player,
        array $fixture,
        array $fixtureOdds
    ): float {
        $clubId = $player['club_id'] ?? $player['team'] ?? 0;
        $isHome = ($fixture['home_club_id'] ?? 0) === $clubId;

        $totalGoals = (float) ($fixtureOdds['expected_total_goals'] ?? 2.5);
        $homeWinProb = (float) ($fixtureOdds['home_win_prob'] ?? 0.33);
        $drawProb = (float) ($fixtureOdds['draw_prob'] ?? 0.33);

        // Derive team's share of goals (assists follow goals)
        $homeShare = $homeWinProb + 0.5 * $drawProb;
        $teamXG = $totalGoals * ($isHome ? $homeShare : 1 - $homeShare);

        // League average team xG per match ≈ 1.25
        $leagueAvgTeamXG = 1.25;

        $multiplier = $teamXG / $leagueAvgTeamXG;

        return min(2.0, $baseXA * $multiplier);
    }
}
