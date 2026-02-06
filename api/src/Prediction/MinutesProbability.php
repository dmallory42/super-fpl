<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates probability of a player playing minutes.
 *
 * Uses minutes, starts, and (when available) appearances from the FPL API.
 * Separates "what happens when selected" from "current availability" so that
 * past injury absences don't penalize nailed players who are now fit.
 */
class MinutesProbability
{
    /**
     * Minimum minutes for reliable predictions.
     * Below this, regress toward conservative baseline.
     */
    private const RELIABLE_MINUTES_THRESHOLD = 270;

    /**
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

        // Confirmed out
        if ($chanceOfPlaying !== null && (int) $chanceOfPlaying === 0) {
            return [
                'prob_60' => 0.0,
                'prob_any' => 0.0,
                'expected_mins' => 0.0,
            ];
        }

        // No playing time this season
        if ($minutes === 0) {
            return [
                'prob_60' => 0.1,
                'prob_any' => 0.15,
                'expected_mins' => 10.0,
            ];
        }

        $teamGames = max(1, $teamGames);
        $availability = $this->getAvailability($chanceOfPlaying);

        // Derive per-appearance stats from best available data
        if ($appearances > 0) {
            // Slow sync has run — use real appearances
            $minsPerAppearance = $minutes / $appearances;
            $appearanceRate = $appearances / $teamGames;
        } elseif ($starts > 0) {
            // Bootstrap only — use starts as lower bound for appearances
            $minsPerAppearance = $minutes / $starts;
            $appearanceRate = $starts / $teamGames;
        } else {
            // Pure sub (minutes > 0, zero starts) — estimate ~20 mins per sub
            $minsPerAppearance = 20.0;
            $appearanceRate = ($minutes / $teamGames) / 20.0;
        }

        $startRate = $starts / $teamGames;

        // Selection probability when available.
        // High mins-per-appearance = nailed starter; gap between
        // appearanceRate and 1.0 is mostly injury, not rotation.
        if ($minsPerAppearance >= 75) {
            $selectionProb = 0.98;
        } elseif ($minsPerAppearance >= 60) {
            $selectionProb = min(0.95, $appearanceRate * 1.2);
        } else {
            $selectionProb = min(0.90, $appearanceRate * 1.1);
        }

        $probAny = $availability * $selectionProb;
        $prob60 = $this->calculate60MinsProbability($minsPerAppearance, $startRate, $probAny);
        $expectedMins = $probAny * $minsPerAppearance;

        // Reliability discount for low-minutes players
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
     * Map FPL's chance_of_playing to an availability factor.
     * null means "no news" — treat as fully available.
     */
    private function getAvailability(mixed $chanceOfPlaying): float
    {
        if ($chanceOfPlaying === null || $chanceOfPlaying === '') {
            return 1.0;
        }
        $chance = (int) $chanceOfPlaying;
        return max(0.0, min(1.0, $chance / 100));
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
        if ($startRate > 0.5) {
            // Mostly starts — gets subbed off sometimes
            $prob60GivenPlay = min(0.80, 0.50 + ($minsPerAppearance / 150));
        } elseif ($startRate > 0.2) {
            // Mix of starts and subs
            $prob60GivenPlay = min(0.60, 0.30 + ($minsPerAppearance / 200));
        } else {
            // Mostly subs — rarely plays 60+
            $prob60GivenPlay = min(0.30, $minsPerAppearance / 150);
        }

        return $prob60GivenPlay * $probAny;
    }
}
