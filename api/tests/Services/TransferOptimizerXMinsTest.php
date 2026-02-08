<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Services\TransferOptimizerService;

/**
 * Testable subclass to access protected methods.
 */
class TestableTransferOptimizerService extends TransferOptimizerService
{
    public function __construct()
    {
        // Skip parent constructor — we only test pure computation methods
    }

    public function publicComputePerGwXMins(
        array $playerMap,
        array $upcomingGws,
        array $teamGames,
        array $userOverrides = []
    ): array {
        return $this->computePerGwXMins($playerMap, $upcomingGws, $teamGames, $userOverrides);
    }

    public function publicApplyPerGwXMinsToPredictions(
        array $predictions,
        array $perGwXMins,
        array $playerMap,
        array $teamGames
    ): array {
        return $this->applyPerGwXMinsToPredictions($predictions, $perGwXMins, $playerMap, $teamGames);
    }

    public function publicEstimateRecoveryWeeks(?int $chanceOfPlaying, string $news): ?int
    {
        return $this->estimateRecoveryWeeks($chanceOfPlaying, $news);
    }

    public function publicCalculateExpectedMins(array $player, bool $whenFit = false): int
    {
        return $this->calculateExpectedMins($player, $whenFit);
    }
}

class TransferOptimizerXMinsTest extends TestCase
{
    private TestableTransferOptimizerService $service;

    protected function setUp(): void
    {
        $this->service = new TestableTransferOptimizerService();
    }

    /**
     * Helper: make a fit starter player with realistic stats.
     */
    private function makePlayer(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'web_name' => "Player$id",
            'position' => 3, // MID
            'club_id' => 1,
            'now_cost' => 80,
            'minutes' => 2000,
            'appearances' => 24,
            'starts' => 22,
            'total_points' => 120,
            'chance_of_playing' => null,
            'news' => '',
        ], $overrides);
    }

    /**
     * Fit starter: 85 xMins with 3% decay per GW offset.
     */
    public function testBaseXMinsWithDecay(): void
    {
        $player = $this->makePlayer(1);
        $playerMap = [1 => $player];
        $gws = [26, 27, 28, 29, 30, 31];
        $teamGames = [1 => 24];

        $result = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames);

        // Player is a regular starter → baseFitXMins = 85
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(26, $result[1]);

        // GW26 (offset 0): 85
        $this->assertEquals(85, $result[1][26]);

        // GW27 (offset 1): 85 * 0.97 ≈ 82
        $this->assertEquals(round(85 * 0.97), $result[1][27]);

        // GW28 (offset 2): 85 * 0.97^2 ≈ 80
        $this->assertEquals(round(85 * pow(0.97, 2)), $result[1][28]);

        // Verify decay continues
        $this->assertGreaterThan($result[1][31], $result[1][26]);
    }

    /**
     * Injured player (chance_of_playing=0) with 3-week recovery:
     * 0 for first 3 GWs, then base after.
     */
    public function testInjuredPlayerZeroUntilRecovery(): void
    {
        $player = $this->makePlayer(2, [
            'chance_of_playing' => 0,
            'news' => 'Hamstring injury - Expected back 1 Mar',
        ]);
        $playerMap = [2 => $player];
        $gws = [26, 27, 28, 29, 30, 31];
        $teamGames = [1 => 24];

        $result = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames);

        $this->assertArrayHasKey(2, $result);

        // Estimate recovery from news string
        $recoveryWeeks = $this->service->publicEstimateRecoveryWeeks(0, 'Hamstring injury - Expected back 1 Mar');
        $this->assertNotNull($recoveryWeeks);

        // Before recovery: should be 0
        for ($i = 0; $i < $recoveryWeeks; $i++) {
            $gw = $gws[$i];
            $this->assertEquals(0, $result[2][$gw], "GW$gw should be 0 during injury");
        }

        // After recovery: should be > 0 (base with decay)
        for ($i = $recoveryWeeks; $i < count($gws); $i++) {
            $gw = $gws[$i];
            $this->assertGreaterThan(0, $result[2][$gw], "GW$gw should be > 0 after recovery");
        }
    }

    /**
     * Partially available (chance_of_playing=50): linear ramp during recovery.
     */
    public function testPartiallyAvailableRamp(): void
    {
        $player = $this->makePlayer(3, [
            'chance_of_playing' => 50,
            'news' => 'Knock - 50% chance',
        ]);
        $playerMap = [3 => $player];
        $gws = [26, 27, 28, 29, 30, 31];
        $teamGames = [1 => 24];

        $result = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames);

        $this->assertArrayHasKey(3, $result);

        // First GW should be reduced but > 0 (partial availability)
        $this->assertGreaterThan(0, $result[3][26], 'GW26 should be > 0 for partial availability');

        // Should ramp up over recovery period
        $recoveryWeeks = $this->service->publicEstimateRecoveryWeeks(50, 'Knock - 50% chance');
        if ($recoveryWeeks !== null && $recoveryWeeks > 1) {
            // Values should increase during ramp then stabilise
            $this->assertGreaterThanOrEqual($result[3][26], $result[3][27]);
        }

        // After recovery: near-base levels (with decay)
        $lastGw = end($gws);
        $this->assertGreaterThan(0, $result[3][$lastGw]);
    }

    /**
     * Long-term injury (ACL/surgery in news): all GWs = 0.
     */
    public function testLongTermInjuryAllZero(): void
    {
        $player = $this->makePlayer(4, [
            'chance_of_playing' => 0,
            'news' => 'ACL injury - out for the season',
        ]);
        $playerMap = [4 => $player];
        $gws = [26, 27, 28, 29, 30, 31];
        $teamGames = [1 => 24];

        $result = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames);

        $this->assertArrayHasKey(4, $result);

        foreach ($gws as $gw) {
            $this->assertEquals(0, $result[4][$gw], "GW$gw should be 0 for long-term injury");
        }
    }

    /**
     * User uniform override: {playerId: 40} → 40 for all GWs.
     */
    public function testUserUniformOverride(): void
    {
        $player = $this->makePlayer(5);
        $playerMap = [5 => $player];
        $gws = [26, 27, 28, 29, 30, 31];
        $teamGames = [1 => 24];
        $overrides = [5 => 40]; // uniform int

        $result = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames, $overrides);

        $this->assertArrayHasKey(5, $result);

        foreach ($gws as $gw) {
            $this->assertEquals(40, $result[5][$gw], "GW$gw should be 40 with uniform override");
        }
    }

    /**
     * User per-GW override: {playerId: {26: 0, 27: 0, 28: 75}} → exact values, others keep auto.
     */
    public function testUserPerGwOverride(): void
    {
        $player = $this->makePlayer(6);
        $playerMap = [6 => $player];
        $gws = [26, 27, 28, 29, 30, 31];
        $teamGames = [1 => 24];
        $overrides = [6 => [26 => 0, 27 => 0, 28 => 75]]; // per-GW array

        $result = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames, $overrides);

        $this->assertArrayHasKey(6, $result);

        // Overridden GWs: exact values
        $this->assertEquals(0, $result[6][26]);
        $this->assertEquals(0, $result[6][27]);
        $this->assertEquals(75, $result[6][28]);

        // Non-overridden GWs: should have auto-computed values (baseFitXMins * decay)
        $this->assertGreaterThan(0, $result[6][29]);
        $this->assertGreaterThan(0, $result[6][30]);
        $this->assertGreaterThan(0, $result[6][31]);
    }

    /**
     * Injured player with 0 cached predictions gets fit estimate for return GWs.
     */
    public function testZeroBasePredictionRecovery(): void
    {
        $player = $this->makePlayer(7, [
            'chance_of_playing' => 0,
            'news' => 'Hamstring - Expected back 15 Feb',
            'total_points' => 100,
            'appearances' => 20,
        ]);
        $playerMap = [7 => $player];
        $gws = [26, 27, 28, 29, 30, 31];
        $teamGames = [1 => 24];

        // Cached predictions are all 0 (injured at prediction time)
        $predictions = [7 => array_fill_keys($gws, 0.0)];

        // Compute per-GW xMins (should show recovery)
        $perGwXMins = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames);

        // Apply to predictions
        $adjusted = $this->service->publicApplyPerGwXMinsToPredictions(
            $predictions,
            $perGwXMins,
            $playerMap,
            $teamGames
        );

        $this->assertArrayHasKey(7, $adjusted);

        // GWs where xMins > 0 should have non-zero predictions (fit estimate)
        $foundNonZero = false;
        foreach ($gws as $gw) {
            if ($perGwXMins[7][$gw] > 0) {
                $this->assertGreaterThan(0, $adjusted[7][$gw],
                    "GW$gw: xMins={$perGwXMins[7][$gw]} but prediction is 0 - fit estimate should fill in");
                $foundNonZero = true;
            }
        }
        $this->assertTrue($foundNonZero, 'At least one GW should have recovered predictions');
    }

    /**
     * Fit player: predictions scale ~1.0 with minor decay.
     */
    public function testFitPlayerPredictionsMinimalChange(): void
    {
        $player = $this->makePlayer(8, [
            'minutes' => 2000,
            'appearances' => 24,
            'starts' => 22,
        ]);
        $playerMap = [8 => $player];
        $gws = [26, 27, 28];
        $teamGames = [1 => 24];

        // Cached predictions with non-zero values
        $predictions = [8 => [26 => 6.0, 27 => 5.5, 28 => 7.0]];

        $perGwXMins = $this->service->publicComputePerGwXMins($playerMap, $gws, $teamGames);

        $adjusted = $this->service->publicApplyPerGwXMinsToPredictions(
            $predictions,
            $perGwXMins,
            $playerMap,
            $teamGames
        );

        $this->assertArrayHasKey(8, $adjusted);

        // For a fit player, the GW26 prediction should be very close to original (within 5%)
        $this->assertEqualsWithDelta(6.0, $adjusted[8][26], 0.3,
            'GW26 prediction should be near original for fit player');

        // Later GWs should have slightly lower values due to decay
        $this->assertLessThanOrEqual($adjusted[8][26] / ($predictions[8][26] ?: 1),
            $adjusted[8][28] / ($predictions[8][28] ?: 1) + 0.01,
            'Decay should cause later GW scale factors to be slightly lower'
        );
    }
}
