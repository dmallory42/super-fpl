<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates probability of a player playing minutes.
 */
class MinutesProbability
{
    /**
     * Calculate probability of playing 60+ minutes.
     *
     * @param array<string, mixed> $player Player data from database
     * @return array{prob_60: float, prob_any: float, expected_mins: float}
     */
    public function calculate(array $player): array
    {
        $chanceOfPlaying = $player['chance_of_playing'] ?? 100;
        $starts = (int) ($player['starts'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);

        // If player has injury news, use chance_of_playing
        if ($chanceOfPlaying < 100) {
            $baseProb = $chanceOfPlaying / 100;
        } else {
            // Use historical minutes/starts to estimate playing probability
            $baseProb = $this->calculateFromHistory($starts, $minutes);
        }

        // Probability of playing any minutes
        $probAny = $baseProb;

        // Probability of playing 60+ minutes (conditional on playing)
        // Regular starters typically play 80+ mins when they play
        $starterRatio = $starts > 0 ? min(1.0, $minutes / ($starts * 90)) : 0.5;
        $prob60 = $probAny * min(0.95, $starterRatio * 1.1);

        // Expected minutes
        $expectedMins = $probAny * ($starterRatio * 90);

        return [
            'prob_60' => round($prob60, 4),
            'prob_any' => round($probAny, 4),
            'expected_mins' => round($expectedMins, 1),
        ];
    }

    /**
     * Calculate playing probability from historical data.
     */
    private function calculateFromHistory(int $starts, int $minutes): float
    {
        // Assume we're looking at ~5 gameweeks of data
        $gamesPlayed = $starts;
        $maxGames = 5; // Approximate recent games

        if ($gamesPlayed === 0) {
            // No starts - check if they got any minutes
            if ($minutes > 0) {
                return 0.3; // Rotation/sub player
            }
            return 0.1; // Unlikely to play
        }

        // Calculate based on start frequency
        $startRate = min(1.0, $gamesPlayed / $maxGames);

        // Players who consistently start have high probability
        if ($startRate >= 0.8) {
            return 0.9;
        } elseif ($startRate >= 0.5) {
            return 0.7;
        } elseif ($startRate >= 0.3) {
            return 0.5;
        }

        return 0.3;
    }
}
