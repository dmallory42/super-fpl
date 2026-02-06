<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\CleanSheetProbability;

class CleanSheetProbabilityTest extends TestCase
{
    private CleanSheetProbability $prob;

    protected function setUp(): void
    {
        $this->prob = new CleanSheetProbability();
    }

    public function testDirectCsOddsAsPrimary(): void
    {
        $player = [
            'club_id' => 1,
            'clean_sheets' => 10,
            'minutes' => 1800,
            'expected_goals_conceded' => 15.0,
        ];

        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $fixtureOdds = [
            'home_cs_prob' => 0.40,
            'away_cs_prob' => 0.20,
            'expected_total_goals' => 2.5,
            'home_win_prob' => 0.5,
            'draw_prob' => 0.3,
        ];

        $result = $this->prob->calculate($player, $fixture, $fixtureOdds);

        // Should be dominated by CS odds (80% weight)
        $this->assertGreaterThan(0.30, $result);
        $this->assertLessThan(0.50, $result);
    }

    public function testDeriveFromMatchOddsWhenNoCsOdds(): void
    {
        $player = [
            'club_id' => 1,
            'clean_sheets' => 10,
            'minutes' => 1800,
            'expected_goals_conceded' => 15.0,
        ];

        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $fixtureOdds = [
            'home_cs_prob' => null,
            'away_cs_prob' => null,
            'expected_total_goals' => 2.0,
            'home_win_prob' => 0.6,
            'draw_prob' => 0.25,
            'away_win_prob' => 0.15,
        ];

        $result = $this->prob->calculate($player, $fixture, $fixtureOdds);

        // Derived from match odds: opponent xG = totalGoals * (1-homeShare)
        // homeShare = 0.6 + 0.5*0.25 = 0.725, oppXG = 2.0 * 0.275 = 0.55
        // csProb = exp(-0.55) ≈ 0.577
        $this->assertGreaterThan(0.35, $result);
        $this->assertLessThan(0.60, $result);
    }

    public function testNoSeparateHomeBoostWhenOddsPresent(): void
    {
        $player = [
            'club_id' => 1,
            'clean_sheets' => 10,
            'minutes' => 1800,
            'expected_goals_conceded' => 15.0,
        ];

        $fixtureHome = ['home_club_id' => 1, 'away_club_id' => 2];
        $fixtureAway = ['home_club_id' => 2, 'away_club_id' => 1];

        // Same CS odds for both - result difference should only come from
        // which CS prob is used (home vs away), not a separate boost
        $oddsHome = [
            'home_cs_prob' => 0.35,
            'away_cs_prob' => 0.35,
            'expected_total_goals' => 2.5,
            'home_win_prob' => 0.5,
            'draw_prob' => 0.3,
        ];

        $resultHome = $this->prob->calculate($player, $fixtureHome, $oddsHome);
        $resultAway = $this->prob->calculate($player, $fixtureAway, $oddsHome);

        // Both use the same CS prob (0.35), so results should be identical
        $this->assertEqualsWithDelta($resultHome, $resultAway, 0.01);
    }

    public function testFallbackToHistoricalRate(): void
    {
        $player = [
            'club_id' => 1,
            'clean_sheets' => 10,
            'minutes' => 1800, // ~30 games * 60
        ];

        // No fixture, no odds
        $result = $this->prob->calculate($player, null, null);

        // Historical rate: 10/30 = 0.333
        $this->assertGreaterThan(0.20, $result);
        $this->assertLessThan(0.50, $result);
    }

    public function testResultsClampedToRange(): void
    {
        // Very strong team odds: CS prob shouldn't exceed 0.6
        $player = ['club_id' => 1, 'minutes' => 1800, 'clean_sheets' => 18, 'expected_goals_conceded' => 5.0];
        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $odds = ['home_cs_prob' => 0.80, 'expected_total_goals' => 1.5, 'home_win_prob' => 0.8, 'draw_prob' => 0.15];

        $result = $this->prob->calculate($player, $fixture, $odds);
        $this->assertLessThanOrEqual(0.60, $result);
        $this->assertGreaterThanOrEqual(0.05, $result);
    }
}
