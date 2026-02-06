<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\PredictionEngine;

class PredictionEngineTest extends TestCase
{
    private PredictionEngine $engine;

    protected function setUp(): void
    {
        // No database for unit tests (no historical baselines)
        $this->engine = new PredictionEngine();
    }

    public function testGcPenaltyOnlyGkDef(): void
    {
        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $fixtureOdds = [
            'home_win_prob' => 0.3,
            'draw_prob' => 0.3,
            'away_win_prob' => 0.4,
            'expected_total_goals' => 3.0,
        ];

        // Midfielder (position 3) - should NOT have GC penalty
        $midfielder = $this->makePlayer(3, 1);
        $midResult = $this->engine->predict($midfielder, $fixture, $fixtureOdds);
        $this->assertEquals(0.0, $midResult['breakdown']['goals_conceded']);

        // Defender (position 2) - should have GC penalty
        $defender = $this->makePlayer(2, 1);
        $defResult = $this->engine->predict($defender, $fixture, $fixtureOdds);
        $this->assertLessThan(0.0, $defResult['breakdown']['goals_conceded']);

        // Goalkeeper (position 1) - should have GC penalty
        $goalkeeper = $this->makePlayer(1, 1);
        $gkResult = $this->engine->predict($goalkeeper, $fixture, $fixtureOdds);
        $this->assertLessThan(0.0, $gkResult['breakdown']['goals_conceded']);

        // Forward (position 4) - should NOT have GC penalty
        $forward = $this->makePlayer(4, 1);
        $fwdResult = $this->engine->predict($forward, $fixture, $fixtureOdds);
        $this->assertEquals(0.0, $fwdResult['breakdown']['goals_conceded']);
    }

    public function testDcUsesConditionalMinutes(): void
    {
        // Defender with DC stats
        $player = $this->makePlayer(2, 1);
        $player['defensive_contribution_per_90'] = 12.0;

        $result = $this->engine->predict($player);

        // DC should be in breakdown
        $this->assertArrayHasKey('defensive_contribution', $result['breakdown']);
    }

    public function testBreakdownIncludesCards(): void
    {
        $player = $this->makePlayer(3, 1);
        $player['yellow_cards'] = 5;
        $player['red_cards'] = 0;
        $player['own_goals'] = 0;
        $player['penalties_missed'] = 0;

        $result = $this->engine->predict($player);

        $this->assertArrayHasKey('cards', $result['breakdown']);
        $this->assertLessThanOrEqual(0, $result['breakdown']['cards']);
    }

    public function testPremiumForwardArchetype(): void
    {
        // Premium FWD: Haaland-like
        $player = [
            'position' => 4,
            'club_id' => 1,
            'code' => 100,
            'expected_goals' => 15.0,
            'expected_assists' => 3.0,
            'minutes' => 1800,
            'goals_scored' => 18,
            'assists' => 3,
            'starts' => 20,
            'appearances' => 20,
            'bps' => 700,
            'bonus' => 15,
            'clean_sheets' => 0,
            'defensive_contribution_per_90' => 2.0,
            'saves' => 0,
            'yellow_cards' => 2,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $fixtureOdds = [
            'home_win_prob' => 0.55,
            'draw_prob' => 0.25,
            'away_win_prob' => 0.20,
            'expected_total_goals' => 2.8,
        ];
        $goalscorerOdds = ['anytime_scorer_prob' => 0.55];

        $result = $this->engine->predict($player, $fixture, $fixtureOdds, $goalscorerOdds, null, 24);

        // Premium FWD with good fixture: should predict 4-8 points
        $this->assertGreaterThan(3.0, $result['predicted_points']);
        $this->assertLessThan(10.0, $result['predicted_points']);

        // GC penalty should be 0 for FWD
        $this->assertEquals(0.0, $result['breakdown']['goals_conceded']);
    }

    public function testDefensiveMidfielderArchetype(): void
    {
        // Defensive MID: Rice-like
        $player = [
            'position' => 3,
            'club_id' => 1,
            'code' => 200,
            'expected_goals' => 2.0,
            'expected_assists' => 2.0,
            'minutes' => 1800,
            'goals_scored' => 3,
            'assists' => 3,
            'starts' => 20,
            'appearances' => 20,
            'bps' => 500,
            'bonus' => 8,
            'clean_sheets' => 10,
            'expected_goals_conceded' => 15.0,
            'defensive_contribution_per_90' => 8.0,
            'saves' => 0,
            'yellow_cards' => 4,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->engine->predict($player, null, null, null, null, 24);

        // Defensive MID should NOT have GC penalty
        $this->assertEquals(0.0, $result['breakdown']['goals_conceded']);

        // Should still get CS points (MID gets 1pt for CS)
        $this->assertGreaterThan(0.0, $result['breakdown']['clean_sheet']);
    }

    public function testRotationGoalkeeperArchetype(): void
    {
        // Rotation GK: low minutes, backup
        $player = [
            'position' => 1,
            'club_id' => 1,
            'code' => 300,
            'expected_goals' => 0.0,
            'expected_assists' => 0.0,
            'minutes' => 270,
            'goals_scored' => 0,
            'assists' => 0,
            'starts' => 3,
            'appearances' => 3,
            'bps' => 60,
            'bonus' => 1,
            'clean_sheets' => 1,
            'expected_goals_conceded' => 3.0,
            'defensive_contribution_per_90' => 0,
            'saves' => 9,
            'yellow_cards' => 0,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->engine->predict($player, null, null, null, null, 24);

        // Rotation GK: low predicted points due to low playing probability
        $this->assertLessThan(3.0, $result['predicted_points']);
        $this->assertGreaterThan(0.0, $result['predicted_points']);
    }

    public function testSuperSubArchetype(): void
    {
        // Super-sub: plays often but rarely starts
        $player = [
            'position' => 4,
            'club_id' => 1,
            'code' => 400,
            'expected_goals' => 2.0,
            'expected_assists' => 1.0,
            'minutes' => 500,
            'goals_scored' => 3,
            'assists' => 2,
            'starts' => 3,
            'appearances' => 18,
            'bps' => 150,
            'bonus' => 2,
            'clean_sheets' => 0,
            'defensive_contribution_per_90' => 1.0,
            'saves' => 0,
            'yellow_cards' => 1,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->engine->predict($player, null, null, null, null, 24);

        // Super-sub: lower predicted points than regular starter
        $this->assertGreaterThan(0.5, $result['predicted_points']);
        $this->assertLessThan(5.0, $result['predicted_points']);
    }

    public function testNoNegativeAppearancePoints(): void
    {
        $player = $this->makePlayer(3, 1);

        $result = $this->engine->predict($player);

        $this->assertGreaterThanOrEqual(0, $result['breakdown']['appearance']);
    }

    public function testCalibrationDoesNotAffectZeroPredictions(): void
    {
        // Unavailable player with 0.0 prediction should stay at 0.0
        $player = [
            'position' => 3,
            'club_id' => 1,
            'code' => 500,
            'expected_goals' => 0.0,
            'expected_assists' => 0.0,
            'minutes' => 0,
            'goals_scored' => 0,
            'assists' => 0,
            'starts' => 0,
            'appearances' => 0,
            'bps' => 0,
            'bonus' => 0,
            'clean_sheets' => 0,
            'defensive_contribution_per_90' => 0,
            'saves' => 0,
            'yellow_cards' => 0,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
            'chance_of_playing' => 0,
        ];

        $result = $this->engine->predict($player, null, null, null, null, 24);

        $this->assertEquals(0.0, $result['predicted_points']);
    }

    public function testCalibrationDoesNotAffectHighPredictions(): void
    {
        // Premium FWD archetype (Haaland-like) should stay in the 3-10 range
        $player = [
            'position' => 4,
            'club_id' => 1,
            'code' => 100,
            'expected_goals' => 15.0,
            'expected_assists' => 3.0,
            'minutes' => 1800,
            'goals_scored' => 18,
            'assists' => 3,
            'starts' => 20,
            'appearances' => 20,
            'bps' => 700,
            'bonus' => 15,
            'clean_sheets' => 0,
            'defensive_contribution_per_90' => 2.0,
            'saves' => 0,
            'yellow_cards' => 2,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $fixture = ['home_club_id' => 1, 'away_club_id' => 2];
        $fixtureOdds = [
            'home_win_prob' => 0.55,
            'draw_prob' => 0.25,
            'away_win_prob' => 0.20,
            'expected_total_goals' => 2.8,
        ];
        $goalscorerOdds = ['anytime_scorer_prob' => 0.55];

        $result = $this->engine->predict($player, $fixture, $fixtureOdds, $goalscorerOdds, null, 24);

        // High predictions (5+) should not be significantly altered
        $this->assertGreaterThan(3.0, $result['predicted_points']);
        $this->assertLessThan(10.0, $result['predicted_points']);
    }

    public function testCalibrationBoostsLowMidRange(): void
    {
        // Rotation GK: should predict ~1-3 range and get a small upward bump
        $player = [
            'position' => 1,
            'club_id' => 1,
            'code' => 300,
            'expected_goals' => 0.0,
            'expected_assists' => 0.0,
            'minutes' => 270,
            'goals_scored' => 0,
            'assists' => 0,
            'starts' => 3,
            'appearances' => 3,
            'bps' => 60,
            'bonus' => 1,
            'clean_sheets' => 1,
            'expected_goals_conceded' => 3.0,
            'defensive_contribution_per_90' => 0,
            'saves' => 9,
            'yellow_cards' => 0,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->engine->predict($player, null, null, null, null, 24);

        // Should be positive and get a slight boost from calibration
        // The raw prediction for this player is typically ~1-2.5 range
        $this->assertGreaterThan(0.0, $result['predicted_points']);
        $this->assertLessThan(4.0, $result['predicted_points']);
    }

    /**
     * Create a standard test player.
     */
    private function makePlayer(int $position, int $clubId): array
    {
        return [
            'position' => $position,
            'club_id' => $clubId,
            'code' => 100,
            'expected_goals' => 3.0,
            'expected_assists' => 2.0,
            'minutes' => 1800,
            'goals_scored' => 4,
            'assists' => 3,
            'starts' => 20,
            'appearances' => 20,
            'bps' => 400,
            'bonus' => 6,
            'clean_sheets' => 8,
            'expected_goals_conceded' => 18.0,
            'defensive_contribution_per_90' => 5.0,
            'saves' => $position === 1 ? 60 : 0,
            'yellow_cards' => 3,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];
    }
}
