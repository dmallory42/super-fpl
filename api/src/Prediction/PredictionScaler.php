<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

final class PredictionScaler
{
    public const POSITION_GKP = 1;
    public const POSITION_DEF = 2;
    public const POSITION_MID = 3;

    /**
     * Scale if-fit predicted points to custom xMins while capping bounded components.
     *
     * @param array<string, mixed> $ifFitBreakdown
     */
    public static function scaleFromIfFitBreakdown(
        float $ifFitPoints,
        float $ifFitMins,
        float $overrideMins,
        array $ifFitBreakdown,
        int $fixtureCount,
        int $position
    ): float {
        if ($ifFitMins <= 0.0) {
            return 0.0;
        }

        $ratio = $overrideMins / $ifFitMins;
        if ($ratio <= 0.0) {
            return 0.0;
        }

        $components = [];
        foreach ($ifFitBreakdown as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $components[$key] = (float) $value;
            }
        }

        if (empty($components)) {
            return round($ifFitPoints * $ratio, 4);
        }

        $fixtureMultiplier = max(1, $fixtureCount);
        $appearanceCap = 2.0 * $fixtureMultiplier;
        $bonusCap = 3.0 * $fixtureMultiplier;
        $cleanSheetCapPerFixture = match ($position) {
            self::POSITION_GKP, self::POSITION_DEF => 4.0,
            self::POSITION_MID => 1.0,
            default => 0.0,
        };
        $cleanSheetCap = $cleanSheetCapPerFixture * $fixtureMultiplier;
        $defContributionCap = $position >= self::POSITION_DEF ? 2.0 * $fixtureMultiplier : 0.0;

        $scaledTotal = 0.0;
        foreach ($components as $key => $value) {
            $scaled = $value * $ratio;
            if ($key === 'appearance' && $scaled > 0.0) {
                $scaled = min($appearanceCap, $scaled);
            } elseif ($key === 'bonus' && $scaled > 0.0) {
                $scaled = min($bonusCap, $scaled);
            } elseif ($key === 'clean_sheet' && $scaled > 0.0 && $cleanSheetCapPerFixture > 0.0) {
                $scaled = min($cleanSheetCap, $scaled);
            } elseif ($key === 'defensive_contribution' && $scaled > 0.0 && $defContributionCap > 0.0) {
                $scaled = min($defContributionCap, $scaled);
            }
            $scaledTotal += $scaled;
        }

        return round($scaledTotal, 4);
    }
}
