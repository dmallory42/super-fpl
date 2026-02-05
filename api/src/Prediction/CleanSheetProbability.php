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
        $clubId = $player['club_id'] ?? $player['team'] ?? 0;
        $isHome = $fixture && ($fixture['home_club_id'] ?? 0) === $clubId;

        // Calculate xGC-based probability
        $xgcBasedProb = $this->calculateFromStats($player, $fixture, $isHome);

        // When odds available, blend bookmaker CS probability (60%) with xGC-based estimate (40%)
        if ($fixtureOdds !== null) {
            $csProb = $isHome
                ? ($fixtureOdds['home_cs_prob'] ?? null)
                : ($fixtureOdds['away_cs_prob'] ?? null);

            if ($csProb !== null) {
                $blendedProb = ((float) $csProb * 0.6) + ($xgcBasedProb * 0.4);
                return round(min(0.6, max(0.05, $blendedProb)), 4);
            }
        }

        // Fallback to xGC-based probability only
        return round(min(0.6, max(0.05, $xgcBasedProb)), 4);
    }

    /**
     * Calculate CS probability from player/team stats.
     *
     * No longer uses FPL difficulty ratings - uses neutral baseline
     * when odds are not available.
     */
    private function calculateFromStats(array $player, ?array $fixture, bool $isHome): float
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
            $xgcPer95 = ($xgc / $minutes) * 95;  // 95 mins = full match with injury time
            // Lower xGC = higher CS probability
            // xGC of 1.0 per game ≈ 35% CS, xGC of 0.5 ≈ 55% CS
            $xgcProb = exp(-$xgcPer95 * 0.9);
            // Blend historical CS rate with xGC-based estimate
            $baseProb = ($csRate * 0.4) + ($xgcProb * 0.6);
        } else {
            $baseProb = $csRate;
        }

        // Home advantage for clean sheets (+15% home, -10% away)
        // No longer using FPL difficulty ratings
        $homeBoost = $isHome ? 1.15 : 0.9;
        $baseProb *= $homeBoost;

        return $baseProb;
    }
}
