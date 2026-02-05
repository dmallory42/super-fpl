<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

/**
 * Calculates probability of a player playing minutes.
 */
class MinutesProbability
{
    /**
     * Default estimated team games played in the season so far.
     * Used when calculating start rate.
     */
    private const DEFAULT_TEAM_GAMES = 24;

    /**
     * Calculate probability of playing 60+ minutes.
     *
     * Uses minutes-per-start ratio to identify nailed starters vs rotation players.
     * A player averaging 85+ mins per start is clearly first-choice when fit.
     *
     * @param array<string, mixed> $player Player data from database
     * @param int|null $estimatedTeamGames Override for team games (for testing)
     * @return array{prob_60: float, prob_any: float, expected_mins: float}
     */
    public function calculate(array $player, ?int $estimatedTeamGames = null): array
    {
        $chanceOfPlaying = $player['chance_of_playing'] ?? null;
        $starts = (int) ($player['starts'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);

        // Only trust chance_of_playing if it equals 0 (confirmed out)
        if ($chanceOfPlaying === 0) {
            return [
                'prob_60' => 0.0,
                'prob_any' => 0.0,
                'expected_mins' => 0.0,
            ];
        }

        // No playing time this season
        if ($starts === 0 || $minutes === 0) {
            return [
                'prob_60' => 0.1,
                'prob_any' => 0.15,
                'expected_mins' => 10.0,
            ];
        }

        // Average minutes when playing
        $avgMinsWhenPlaying = $minutes / $starts;

        // Determine start probability based on minutes-per-start pattern
        // This accounts for players who were injured/unavailable early season
        // but are nailed-on starters when fit
        $probAny = $this->calculateStartProbability($starts, $avgMinsWhenPlaying, $estimatedTeamGames);

        // Probability of playing 60+ minutes (conditional on starting)
        // If they average 85+ mins, they almost always play 60+
        $prob60Given = match (true) {
            $avgMinsWhenPlaying >= 85 => 0.95,
            $avgMinsWhenPlaying >= 75 => 0.90,
            $avgMinsWhenPlaying >= 60 => 0.80,
            $avgMinsWhenPlaying >= 45 => 0.60,
            default => 0.40,
        };

        $prob60 = $probAny * $prob60Given;

        // Expected minutes
        $expectedMins = $probAny * $avgMinsWhenPlaying;

        return [
            'prob_60' => round($prob60, 4),
            'prob_any' => round($probAny, 4),
            'expected_mins' => round($expectedMins, 1),
        ];
    }

    /**
     * Calculate probability of starting based on playing patterns.
     *
     * Key insight: A player with 85+ mins per start is a nailed starter when fit.
     * Their low season starts may be due to injury, not rotation.
     */
    private function calculateStartProbability(int $starts, float $avgMinsWhenPlaying, ?int $estimatedTeamGames): float
    {
        $teamGames = $estimatedTeamGames ?? self::DEFAULT_TEAM_GAMES;

        // Nailed starter pattern: plays full games when they play
        // These players start 90%+ of games when available
        if ($avgMinsWhenPlaying >= 85) {
            // Still factor in volume - more starts = more confidence
            $volumeBonus = min(0.05, $starts / $teamGames * 0.1);
            return min(0.95, 0.90 + $volumeBonus);
        }

        // Regular starter pattern: usually plays 70-85 mins
        if ($avgMinsWhenPlaying >= 70) {
            $baseProb = 0.80;
            $volumeFactor = min(1.0, $starts / ($teamGames * 0.7));
            return min(0.90, $baseProb * $volumeFactor + 0.10);
        }

        // Rotation/squad player: 45-70 mins average
        if ($avgMinsWhenPlaying >= 45) {
            $startRate = $starts / $teamGames;
            return min(0.75, max(0.40, $startRate + 0.20));
        }

        // Fringe/bench player: sub appearances
        $startRate = $starts / $teamGames;
        return min(0.50, max(0.15, $startRate));
    }
}
