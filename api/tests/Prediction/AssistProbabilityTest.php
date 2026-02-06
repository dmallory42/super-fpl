<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\AssistProbability;

class AssistProbabilityTest extends TestCase
{
    private AssistProbability $prob;

    protected function setUp(): void
    {
        $this->prob = new AssistProbability();
    }

    public function testAssistOddsInversePoisson(): void
    {
        $player = [
            'expected_assists' => 6.0,
            'minutes' => 1800,
            'assists' => 7,
            'code' => 100,
        ];

        // Anytime assist prob of 0.4 → λ = -ln(0.6) ≈ 0.511
        $assistOdds = ['anytime_assist_prob' => 0.4];

        $result = $this->prob->calculate($player, null, $assistOdds, null);

        // Should be dominated by odds (90% weight)
        $this->assertGreaterThan(0.3, $result);
        $this->assertLessThan(0.8, $result);
    }

    public function testXaWithRegressionToMean(): void
    {
        // Low minutes: should apply reliability discount
        $player = [
            'expected_assists' => 0.8,
            'minutes' => 200,
            'assists' => 1,
            'code' => 100,
        ];

        $result = $this->prob->calculate($player);

        // xA/90 raw = (0.8/200)*90 = 0.36, but discounted
        $this->assertGreaterThan(0.0, $result);
        $this->assertLessThan(0.36, $result);
    }

    public function testNoHomeDoubleCount(): void
    {
        $player = [
            'expected_assists' => 5.0,
            'minutes' => 1800,
            'assists' => 5,
            'code' => 100,
            'club_id' => 1,
        ];

        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $assistOdds = ['anytime_assist_prob' => 0.35];

        $resultHome = $this->prob->calculate($player, $fixture, $assistOdds, null);

        $fixtureAway = ['home_club_id' => 2, 'away_club_id' => 1];
        $resultAway = $this->prob->calculate($player, $fixtureAway, $assistOdds, null);

        // With assist odds, home/away should not affect result (odds already include it)
        $this->assertEqualsWithDelta($resultHome, $resultAway, 0.05);
    }

    public function testHistoricalFallback(): void
    {
        $player = [
            'expected_assists' => 0,
            'minutes' => 1800,
            'assists' => 6,
            'code' => 100,
        ];

        $result = $this->prob->calculate($player);

        // assists/90 = (6/1800)*90 = 0.3
        $this->assertEqualsWithDelta(0.3, $result, 0.01);
    }

    public function testZeroMinutes(): void
    {
        $player = [
            'expected_assists' => 0,
            'minutes' => 0,
            'assists' => 0,
            'code' => 100,
            'starts' => 0,
        ];

        $result = $this->prob->calculate($player);

        $this->assertEquals(0.0, $result);
    }
}
