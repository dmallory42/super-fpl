<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\PredictionScaler;

class PredictionScalerTest extends TestCase
{
    public function testFallsBackToLinearScalingWithoutBreakdown(): void
    {
        $scaled = PredictionScaler::scaleFromIfFitBreakdown(
            5.0,
            80.0,
            90.0,
            [],
            1,
            PredictionScaler::POSITION_MID
        );

        $this->assertSame(5.625, $scaled);
    }

    public function testAppliesCapsForBoundedComponents(): void
    {
        $scaled = PredictionScaler::scaleFromIfFitBreakdown(
            0.0,
            60.0,
            100.0,
            [
                'appearance' => 2.0,
                'goals' => 2.0,
                'assists' => 1.0,
                'clean_sheet' => 4.0,
                'bonus' => 3.0,
                'goals_conceded' => -0.5,
                'saves' => 1.0,
                'defensive_contribution' => 2.0,
                'cards' => -0.3,
                'penalties' => 0.0,
            ],
            1,
            PredictionScaler::POSITION_MID
        );

        $this->assertEqualsWithDelta(13.3333, $scaled, 0.0001);
    }

    public function testReturnsZeroWhenIfFitMinsIsZero(): void
    {
        $scaled = PredictionScaler::scaleFromIfFitBreakdown(
            6.0,
            0.0,
            80.0,
            ['goals' => 3.0],
            1,
            PredictionScaler::POSITION_DEF
        );

        $this->assertSame(0.0, $scaled);
    }

    public function testHigherOverrideCanIncreaseWhenComponentsAreNonNegative(): void
    {
        $base = PredictionScaler::scaleFromIfFitBreakdown(
            6.4,
            84.0,
            84.0,
            [
                'appearance' => 2.0,
                'goals' => 2.4,
                'assists' => 0.8,
                'clean_sheet' => 0.9,
                'bonus' => 0.3,
                'goals_conceded' => 0.0,
                'saves' => 0.0,
                'defensive_contribution' => 0.0,
                'cards' => 0.0,
                'penalties' => 0.0,
            ],
            1,
            PredictionScaler::POSITION_DEF
        );

        $higher = PredictionScaler::scaleFromIfFitBreakdown(
            6.4,
            84.0,
            90.0,
            [
                'appearance' => 2.0,
                'goals' => 2.4,
                'assists' => 0.8,
                'clean_sheet' => 0.9,
                'bonus' => 0.3,
                'goals_conceded' => 0.0,
                'saves' => 0.0,
                'defensive_contribution' => 0.0,
                'cards' => 0.0,
                'penalties' => 0.0,
            ],
            1,
            PredictionScaler::POSITION_DEF
        );

        $this->assertGreaterThan($base, $higher);
    }
}
