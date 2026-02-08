<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\PenaltyProbability;

class PenaltyProbabilityTest extends TestCase
{
    private PenaltyProbability $prob;

    protected function setUp(): void
    {
        $this->prob = new PenaltyProbability();
    }

    public function testPlayerWithNoPenaltyOrderReturnsZero(): void
    {
        $player = ['penalty_order' => null, 'position' => 3];
        $result = $this->prob->calculate($player, []);
        $this->assertEquals(0.0, $result);
    }

    public function testPrimaryTakerGetsFullOnPitchFraction(): void
    {
        // Palmer: order 1, xMins 75 → f = 75/90 = 0.833
        $teamTakers = [
            ['player_id' => 100, 'expected_mins' => 75.0],
            ['player_id' => 200, 'expected_mins' => 70.0],
        ];

        $player = ['id' => 100, 'penalty_order' => 1, 'position' => 3];
        $result = $this->prob->calculate($player, $teamTakers);

        // takePct should be 75/90 ≈ 0.833
        // Expected: TEAM_PEN_RATE * 0.833 * expectedPerPen > 0
        $this->assertGreaterThan(0, $result);

        // Compare to a player with xMins 90 — they should get more
        $teamTakers90 = [
            ['player_id' => 100, 'expected_mins' => 90.0],
        ];
        $result90 = $this->prob->calculate($player, $teamTakers90);
        $this->assertGreaterThan($result, $result90);
    }

    public function testSecondTakerDependsOnFirstNotPlaying(): void
    {
        // Palmer: order 1, xMins 75 → on-pitch = 75/90 = 0.833
        // Enzo: order 2, xMins 70 → on-pitch = 70/90 = 0.778
        // Enzo takePct = (1 - 0.833) * 0.778 = 0.130
        $teamTakers = [
            ['player_id' => 100, 'expected_mins' => 75.0],
            ['player_id' => 200, 'expected_mins' => 70.0],
        ];

        $primary = ['id' => 100, 'penalty_order' => 1, 'position' => 3];
        $backup = ['id' => 200, 'penalty_order' => 2, 'position' => 3];

        $primaryResult = $this->prob->calculate($primary, $teamTakers);
        $backupResult = $this->prob->calculate($backup, $teamTakers);

        // Primary should get much more than backup
        $this->assertGreaterThan($backupResult, $primaryResult);

        // Backup should still be positive
        $this->assertGreaterThan(0, $backupResult);

        // Ratio should be roughly 0.833 / 0.130 ≈ 6.4x
        $ratio = $primaryResult / $backupResult;
        $this->assertGreaterThan(5.0, $ratio);
        $this->assertLessThan(8.0, $ratio);
    }

    public function testBackupValueIncreasesWhenPrimaryInjured(): void
    {
        // Palmer healthy: xMins 75
        $teamTakersHealthy = [
            ['player_id' => 100, 'expected_mins' => 75.0],
            ['player_id' => 200, 'expected_mins' => 70.0],
        ];

        // Palmer injured: xMins 0
        $teamTakersInjured = [
            ['player_id' => 100, 'expected_mins' => 0.0],
            ['player_id' => 200, 'expected_mins' => 70.0],
        ];

        $backup = ['id' => 200, 'penalty_order' => 2, 'position' => 3];

        $healthyResult = $this->prob->calculate($backup, $teamTakersHealthy);
        $injuredResult = $this->prob->calculate($backup, $teamTakersInjured);

        // Backup should get much more value when primary is out
        $this->assertGreaterThan($healthyResult * 4, $injuredResult);
    }

    public function testThirdTakerGetsVeryLittleWhenTopTwoFit(): void
    {
        $teamTakers = [
            ['player_id' => 100, 'expected_mins' => 80.0],
            ['player_id' => 200, 'expected_mins' => 75.0],
            ['player_id' => 300, 'expected_mins' => 70.0],
        ];

        $third = ['id' => 300, 'penalty_order' => 3, 'position' => 3];
        $result = $this->prob->calculate($third, $teamTakers);

        // Third taker when top two are fit: (1-80/90)*(1-75/90)*70/90 ≈ 0.029
        // Very small but positive
        $this->assertGreaterThan(0, $result);
        $this->assertLessThan(0.05, $result); // Very small expected points
    }

    public function testDefenderGetsMorePointsPerPenGoal(): void
    {
        $teamTakers = [
            ['player_id' => 100, 'expected_mins' => 80.0],
        ];

        $defender = ['id' => 100, 'penalty_order' => 1, 'position' => 2]; // 6 pts/goal
        $forward = ['id' => 100, 'penalty_order' => 1, 'position' => 4]; // 4 pts/goal

        $defResult = $this->prob->calculate($defender, $teamTakers);
        $fwdResult = $this->prob->calculate($forward, $teamTakers);

        $this->assertGreaterThan($fwdResult, $defResult);
    }

    public function testEmptyTeamTakersListReturnsZero(): void
    {
        $player = ['id' => 100, 'penalty_order' => 1, 'position' => 3];
        $result = $this->prob->calculate($player, []);
        $this->assertEquals(0.0, $result);
    }

    public function testPlayerNotInTeamTakersListReturnsZero(): void
    {
        // Player has penalty_order but isn't in the team takers list
        $teamTakers = [
            ['player_id' => 999, 'expected_mins' => 80.0],
        ];
        $player = ['id' => 100, 'penalty_order' => 1, 'position' => 3];
        $result = $this->prob->calculate($player, $teamTakers);
        $this->assertEquals(0.0, $result);
    }
}
