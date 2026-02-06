<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\MinutesProbability;

class MinutesProbabilityTest extends TestCase
{
    private MinutesProbability $prob;

    protected function setUp(): void
    {
        $this->prob = new MinutesProbability();
    }

    public function testConfirmedOutHandlesStringZero(): void
    {
        // chance_of_playing comes as string "0" from API
        $player = [
            'chance_of_playing' => "0",
            'starts' => 10,
            'minutes' => 900,
            'appearances' => 10,
        ];

        $result = $this->prob->calculate($player, 24);

        $this->assertEquals(0.0, $result['prob_60']);
        $this->assertEquals(0.0, $result['prob_any']);
        $this->assertEquals(0.0, $result['expected_mins']);
    }

    public function testConfirmedOutWithIntZero(): void
    {
        $player = [
            'chance_of_playing' => 0,
            'starts' => 10,
            'minutes' => 900,
            'appearances' => 10,
        ];

        $result = $this->prob->calculate($player, 24);

        $this->assertEquals(0.0, $result['prob_60']);
        $this->assertEquals(0.0, $result['prob_any']);
    }

    public function testDynamicTeamGames(): void
    {
        $player = [
            'starts' => 10,
            'minutes' => 900,
            'appearances' => 12,
        ];

        // With 20 team games: appearance rate = 12/20 = 0.6
        $result20 = $this->prob->calculate($player, 20);
        // With 30 team games: appearance rate = 12/30 = 0.4
        $result30 = $this->prob->calculate($player, 30);

        $this->assertGreaterThan($result30['prob_any'], $result20['prob_any']);
    }

    public function testRegularStarter(): void
    {
        $player = [
            'starts' => 22,
            'minutes' => 1980,
            'appearances' => 22,
        ];

        $result = $this->prob->calculate($player, 24);

        // Regular starter: high prob of playing 60+ mins
        $this->assertGreaterThan(0.8, $result['prob_60']);
        $this->assertGreaterThan(0.85, $result['prob_any']);
        $this->assertGreaterThan(70, $result['expected_mins']);
    }

    public function testSuperSub(): void
    {
        // Super-sub: plays often but rarely starts, low minutes per appearance
        $player = [
            'starts' => 3,
            'minutes' => 400,
            'appearances' => 18,
        ];

        $result = $this->prob->calculate($player, 24);

        // Super-sub: high prob_any but low prob_60
        $this->assertGreaterThan(0.5, $result['prob_any']);
        $this->assertLessThan(0.4, $result['prob_60']);
        $this->assertLessThan(40, $result['expected_mins']);
    }

    public function testLowMinuteRegression(): void
    {
        // Very few minutes: should regress toward baseline
        $player = [
            'starts' => 2,
            'minutes' => 100,
            'appearances' => 2,
        ];

        $result = $this->prob->calculate($player, 24);

        // Should be regressed - not extreme values
        $this->assertGreaterThan(0.05, $result['prob_any']);
        $this->assertLessThan(0.5, $result['prob_any']);
    }

    public function testNullChanceOfPlayingIsIgnored(): void
    {
        $player = [
            'chance_of_playing' => null,
            'starts' => 20,
            'minutes' => 1800,
            'appearances' => 20,
        ];

        $result = $this->prob->calculate($player, 24);

        // null should not be treated as 0
        $this->assertGreaterThan(0.7, $result['prob_any']);
    }

    public function testNonZeroChanceOfPlayingIsIgnored(): void
    {
        // chance_of_playing = 75 should be ignored
        $player = [
            'chance_of_playing' => 75,
            'starts' => 20,
            'minutes' => 1800,
            'appearances' => 20,
        ];

        $result = $this->prob->calculate($player, 24);

        $this->assertGreaterThan(0.7, $result['prob_any']);
    }
}
