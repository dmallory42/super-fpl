<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates probability of a player playing minutes.
 */
class MinutesProbability
{
    /**
     * Minimum minutes for reliable predictions.
     * Below this, regress toward conservative baseline.
     */
    private const RELIABLE_MINUTES_THRESHOLD = 270;

    /**
     * Calculate probability of playing and expected minutes.
     *
     * Uses actual appearances data (synced from FPL API) to calculate
     * accurate minutes per appearance. This correctly handles:
     * - Super-subs who rarely start but appear often
     * - Injured players who play full games when fit
     * - Rotation players
     *
     * @param array<string, mixed> $player Player data from database
     * @param int $teamGames Number of games the player's team has played
     * @return array{prob_60: float, prob_any: float, expected_mins: float}
     */
    public function calculate(array $player, int $teamGames): array
    {
        $chanceOfPlaying = $player['chance_of_playing'] ?? null;
        $starts = (int) ($player['starts'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $appearances = (int) ($player['appearances'] ?? 0);

        // Only trust chance_of_playing if it equals 0 (confirmed out)
        // Cast to int to handle string "0" from API
        if ($chanceOfPlaying !== null && (int) $chanceOfPlaying === 0) {
            return [
                'prob_60' => 0.0,
                'prob_any' => 0.0,
                'expected_mins' => 0.0,
            ];
        }

        // No playing time this season
        if ($minutes === 0 || $appearances === 0) {
            return [
                'prob_60' => 0.1,
                'prob_any' => 0.15,
                'expected_mins' => 10.0,
            ];
        }

        // Core metrics from real data
        $minsPerAppearance = $minutes / $appearances;
        $appearanceRate = $appearances / $teamGames;
        $startRate = $starts / $teamGames;

        // Probability of any appearance (capped at 98%)
        $probAny = min(0.98, $appearanceRate);

        // Probability of 60+ mins depends on whether they typically start or sub
        $prob60 = $this->calculate60MinsProbability($minsPerAppearance, $startRate, $probAny);

        // Expected minutes = probability of playing × minutes when playing
        $expectedMins = $probAny * $minsPerAppearance;

        // Apply reliability discount for low-minutes players
        if ($minutes < self::RELIABLE_MINUTES_THRESHOLD) {
            $reliability = $minutes / self::RELIABLE_MINUTES_THRESHOLD;
            $baselineExpectedMins = 15.0;
            $baselineProb60 = 0.10;
            $baselineProbAny = 0.25;

            $expectedMins = ($expectedMins * $reliability) + ($baselineExpectedMins * (1 - $reliability));
            $prob60 = ($prob60 * $reliability) + ($baselineProb60 * (1 - $reliability));
            $probAny = ($probAny * $reliability) + ($baselineProbAny * (1 - $reliability));
        }

        return [
            'prob_60' => round($prob60, 4),
            'prob_any' => round($probAny, 4),
            'expected_mins' => round($expectedMins, 1),
        ];
    }

    /**
     * Calculate probability of playing 60+ minutes.
     */
    private function calculate60MinsProbability(float $minsPerAppearance, float $startRate, float $probAny): float
    {
        // High mins per appearance = usually plays full games
        if ($minsPerAppearance >= 85) {
            return 0.95 * $probAny;
        }
        if ($minsPerAppearance >= 70) {
            return 0.85 * $probAny;
        }

        // For lower mins per appearance, use start rate to estimate
        // A player with 40 mins/appearance could be rotation starter or super-sub
        if ($startRate > 0.5) {
            // Mostly starts - probably gets subbed off sometimes
            $prob60GivenPlay = min(0.80, 0.50 + ($minsPerAppearance / 150));
        } elseif ($startRate > 0.2) {
            // Mix of starts and subs
            $prob60GivenPlay = min(0.60, 0.30 + ($minsPerAppearance / 200));
        } else {
            // Mostly subs - rarely plays 60+
            $prob60GivenPlay = min(0.30, $minsPerAppearance / 150);
        }

        return $prob60GivenPlay * $probAny;
    }
}
