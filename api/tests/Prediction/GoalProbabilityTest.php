<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\GoalProbability;

class GoalProbabilityTest extends TestCase
{
    private GoalProbability $prob;

    protected function setUp(): void
    {
        // No baselines for unit tests (tests regression to mean separately)
        $this->prob = new GoalProbability();
    }

    public function testScorerOddsInversePoisson(): void
    {
        $player = [
            'expected_goals' => 10.0,
            'minutes' => 1800,
            'goals_scored' => 12,
            'code' => 100,
        ];

        // Anytime scorer prob of 0.5 → λ = -ln(0.5) ≈ 0.693
        $goalscorerOdds = ['anytime_scorer_prob' => 0.5];

        $result = $this->prob->calculate($player, null, $goalscorerOdds, null);

        // Should be dominated by odds (90% weight)
        $expectedLambda = -log(0.5); // ~0.693
        $this->assertGreaterThan(0.5, $result['expected_goals']);
        $this->assertLessThan(1.0, $result['expected_goals']);
    }

    public function testXgWithRegressionFallback(): void
    {
        // Low minutes: should apply reliability discount
        $player = [
            'expected_goals' => 1.0,
            'minutes' => 200, // below 270 threshold
            'goals_scored' => 1,
            'code' => 100,
        ];

        $result = $this->prob->calculate($player);

        // xG/90 raw = (1.0/200)*90 = 0.45, but discounted for low minutes
        $this->assertGreaterThan(0.0, $result['expected_goals']);
        $this->assertLessThan(0.45, $result['expected_goals']);
    }

    public function testNoHomeDoubleCountWhenOddsPresent(): void
    {
        $player = [
            'expected_goals' => 5.0,
            'minutes' => 1800,
            'goals_scored' => 6,
            'code' => 100,
            'club_id' => 1,
        ];

        $fixture = [
            'home_club_id' => 1,
            'away_club_id' => 2,
        ];

        $goalscorerOdds = ['anytime_scorer_prob' => 0.4];

        $resultHome = $this->prob->calculate($player, $fixture, $goalscorerOdds, null);

        // With scorer odds, fixture odds are not used for adjustment
        // so home/away should not affect the result
        $fixtureAway = ['home_club_id' => 2, 'away_club_id' => 1];
        $resultAway = $this->prob->calculate($player, $fixtureAway, $goalscorerOdds, null);

        // Both should be essentially the same (odds dominate)
        $this->assertEqualsWithDelta($resultHome['expected_goals'], $resultAway['expected_goals'], 0.05);
    }

    public function testHistoricalFallbackNoPositionSuppression(): void
    {
        // Defender with actual goals
        $player = [
            'expected_goals' => 0,
            'minutes' => 1800,
            'goals_scored' => 4,
            'code' => 100,
            'position' => 2,
        ];

        $result = $this->prob->calculate($player);

        // goals/90 = (4/1800)*90 = 0.2 - should NOT be multiplied by position weight
        $this->assertEqualsWithDelta(0.2, $result['expected_goals'], 0.01);
    }

    public function testFixtureAdjustmentFromMatchOdds(): void
    {
        $player = [
            'expected_goals' => 8.0,
            'minutes' => 1800,
            'goals_scored' => 9,
            'code' => 100,
            'club_id' => 1,
        ];

        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $fixtureOdds = [
            'home_win_prob' => 0.6,
            'draw_prob' => 0.25,
            'away_win_prob' => 0.15,
            'expected_total_goals' => 2.8,
        ];

        $resultFavourable = $this->prob->calculate($player, $fixture, null, $fixtureOdds);

        // Now test with unfavourable odds
        $fixtureOddsUnfav = [
            'home_win_prob' => 0.15,
            'draw_prob' => 0.25,
            'away_win_prob' => 0.6,
            'expected_total_goals' => 2.8,
        ];

        $resultUnfavourable = $this->prob->calculate($player, $fixture, null, $fixtureOddsUnfav);

        // Favourable fixture should produce higher expected goals
        $this->assertGreaterThan($resultUnfavourable['expected_goals'], $resultFavourable['expected_goals']);
    }

    public function testZeroMinutesPlayer(): void
    {
        $player = [
            'expected_goals' => 0,
            'minutes' => 0,
            'goals_scored' => 0,
            'code' => 100,
            'starts' => 0,
        ];

        $result = $this->prob->calculate($player);

        $this->assertEquals(0.0, $result['expected_goals']);
    }
}
