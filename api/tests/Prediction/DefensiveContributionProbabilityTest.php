<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\DefensiveContributionProbability;

class DefensiveContributionProbabilityTest extends TestCase
{
    private DefensiveContributionProbability $prob;

    protected function setUp(): void
    {
        $this->prob = new DefensiveContributionProbability();
    }

    public function testPoissonCdfCalculation(): void
    {
        // High DC rate defender should have reasonable prob of reaching threshold
        $player = [
            'defensive_contribution_per_90' => 12.0,
        ];

        $result = $this->prob->calculate($player, 2, 85.0);

        // With DC/90 of 12 and ~85 mins, expected DC ≈ 11.3
        // Should have decent probability of reaching 10 threshold → ~2 * P(>=10)
        $this->assertGreaterThan(0.5, $result);
        $this->assertLessThanOrEqual(2.0, $result);
    }

    public function testConditionalMinutesUsed(): void
    {
        $player = [
            'defensive_contribution_per_90' => 10.0,
        ];

        // Higher minutes = higher expected DC = higher probability
        $resultHighMins = $this->prob->calculate($player, 2, 85.0);
        $resultLowMins = $this->prob->calculate($player, 2, 45.0);

        $this->assertGreaterThan($resultLowMins, $resultHighMins);
    }

    public function testGoalkeeperReturnsZero(): void
    {
        $player = ['defensive_contribution_per_90' => 5.0];

        $result = $this->prob->calculate($player, 1, 90.0);

        $this->assertEquals(0.0, $result);
    }

    public function testZeroDcReturnsZero(): void
    {
        $player = ['defensive_contribution_per_90' => 0.0];

        $result = $this->prob->calculate($player, 2, 90.0);

        $this->assertEquals(0.0, $result);
    }

    public function testMidfielderUsesHigherThreshold(): void
    {
        $player = [
            'defensive_contribution_per_90' => 11.0,
        ];

        // DEF threshold is 10, MID threshold is 12
        $resultDef = $this->prob->calculate($player, 2, 85.0);
        $resultMid = $this->prob->calculate($player, 3, 85.0);

        // Same DC rate but higher threshold for MID → lower probability
        $this->assertGreaterThan($resultMid, $resultDef);
    }
}
