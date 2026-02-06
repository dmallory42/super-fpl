<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\BonusProbability;

class BonusProbabilityTest extends TestCase
{
    private BonusProbability $prob;

    protected function setUp(): void
    {
        $this->prob = new BonusProbability();
    }

    public function testSigmoidOnBps(): void
    {
        // High BPS player should get higher bonus
        $highBps = [
            'bps' => 800,
            'bonus' => 15,
            'minutes' => 1800,
            'position' => 3,
        ];

        $lowBps = [
            'bps' => 300,
            'bonus' => 3,
            'minutes' => 1800,
            'position' => 3,
        ];

        $resultHigh = $this->prob->calculate($highBps);
        $resultLow = $this->prob->calculate($lowBps);

        $this->assertGreaterThan($resultLow, $resultHigh);
    }

    public function testGoalAssistProbsBoostBonus(): void
    {
        $player = [
            'bps' => 500,
            'bonus' => 8,
            'minutes' => 1800,
            'position' => 4,
        ];

        // Without goal/assist expectations
        $resultBase = $this->prob->calculate($player, 0.0, 0.0);

        // With goal/assist expectations
        $resultBoosted = $this->prob->calculate($player, 0.5, 0.3);

        // Goals/assists should boost bonus estimate
        $this->assertGreaterThan($resultBase, $resultBoosted);
    }

    public function testLowMinutesFallbackToPosition(): void
    {
        $player = [
            'bps' => 50,
            'bonus' => 1,
            'minutes' => 60, // Below 90 threshold
            'position' => 4,
        ];

        $result = $this->prob->calculate($player);

        // Should use position-based estimate
        $this->assertEqualsWithDelta(0.6, $result, 0.01);
    }

    public function testResultCappedAt25(): void
    {
        $player = [
            'bps' => 1500,
            'bonus' => 20,
            'minutes' => 1800,
            'position' => 4,
        ];

        $result = $this->prob->calculate($player, 1.0, 0.5);

        $this->assertLessThanOrEqual(2.5, $result);
    }

    public function testNoArbitraryFixtureMultiplier(): void
    {
        $player = [
            'bps' => 600,
            'bonus' => 10,
            'minutes' => 1800,
            'position' => 3,
        ];

        // The new BonusProbability doesn't take fixture/fixtureOdds params
        // It gets fixture context through expectedGoals/expectedAssists
        $result = $this->prob->calculate($player, 0.3, 0.2);

        $this->assertGreaterThan(0.0, $result);
        $this->assertLessThanOrEqual(2.5, $result);
    }
}
