<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates clean sheet probability for defenders and goalkeepers.
 *
 * Priority:
 * 1. Direct CS odds as primary (80% odds / 20% xGC-based)
 *    No separate home boost — odds already include it.
 * 2. Match odds available but no CS odds: derive from opponent expected goals
 *    oppXG = deriveOpponentGoals(fixtureOdds, isHome), csProb = exp(-oppXG)
 * 3. No odds: historical CS rate from actuals (no home/away boost)
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
        $clubId = $player['club_id'] ?? $player['team'] ?? 0;
        $isHome = $fixture && ($fixture['home_club_id'] ?? 0) === $clubId;

        // Method 1: Direct CS odds (primary signal)
        if ($fixtureOdds !== null) {
            $csProb = $isHome
                ? ($fixtureOdds['home_cs_prob'] ?? null)
                : ($fixtureOdds['away_cs_prob'] ?? null);

            if ($csProb !== null) {
                // Blend 80% odds / 20% xGC-based — no separate home boost
                $xgcBasedProb = $this->calculateFromXGC($player);
                $blendedProb = ((float) $csProb * 0.8) + ($xgcBasedProb * 0.2);
                return round(min(0.6, max(0.05, $blendedProb)), 4);
            }

            // Method 2: Derive from match odds (no CS odds available)
            $oppXG = $this->deriveOpponentGoals($fixtureOdds, $isHome);
            $derivedCsProb = exp(-$oppXG);
            return round(min(0.6, max(0.05, $derivedCsProb)), 4);
        }

        // Method 3: Historical CS rate from actuals (no home/away boost)
        $baseProb = $this->calculateFromActuals($player);
        return round(min(0.6, max(0.05, $baseProb)), 4);
    }

    /**
     * Derive opponent expected goals from match odds.
     */
    private function deriveOpponentGoals(array $fixtureOdds, bool $isHome): float
    {
        $totalGoals = (float) ($fixtureOdds['expected_total_goals'] ?? 2.5);
        $homeWinProb = (float) ($fixtureOdds['home_win_prob'] ?? 0.33);
        $drawProb = (float) ($fixtureOdds['draw_prob'] ?? 0.33);

        // Home share of goals
        $homeShare = $homeWinProb + 0.5 * $drawProb;

        // Opponent goals = total - team goals
        if ($isHome) {
            return $totalGoals * (1 - $homeShare);
        }
        return $totalGoals * $homeShare;
    }

    /**
     * Calculate CS probability from xGC data.
     */
    private function calculateFromXGC(array $player): float
    {
        $xgc = (float) ($player['expected_goals_conceded'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);

        if ($xgc > 0 && $minutes > 0) {
            $xgcPer90 = ($xgc / $minutes) * 90;
            return exp(-$xgcPer90);
        }

        // Fallback to league average if no xGC
        return 0.28;
    }

    /**
     * Calculate CS probability from actual clean sheet records.
     */
    private function calculateFromActuals(array $player): float
    {
        $cleanSheets = (int) ($player['clean_sheets'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);

        if ($minutes <= 0) {
            return 0.28; // League average
        }

        $gamesPlayed = max(1, floor($minutes / 60));
        return $cleanSheets / $gamesPlayed;
    }
}
