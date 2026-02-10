<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Services\PredictionService;

/**
 * Testable subclass to access the protected recovery method.
 */
class TestablePredictionService extends PredictionService
{
    public function __construct()
    {
        // Skip parent constructor — we only test pure computation
    }

    public function publicAdjustAvailabilityForRecovery(array $player, string $gwDeadline): array
    {
        return $this->adjustAvailabilityForRecovery($player, $gwDeadline);
    }
}

class PredictionRecoveryTest extends TestCase
{
    private TestablePredictionService $service;

    protected function setUp(): void
    {
        $this->service = new TestablePredictionService();
    }

    private function makePlayer(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'chance_of_playing' => 0,
            'news' => '',
        ], $overrides);
    }

    /**
     * Player expected back before the GW deadline → chance_of_playing restored to 75.
     */
    public function testRecoveredPlayerGetsRestoredAvailability(): void
    {
        $player = $this->makePlayer([
            'chance_of_playing' => 0,
            'news' => 'Hamstring injury - Expected back 1 Mar',
        ]);

        // GW deadline is 8 Mar — player expected back 1 Mar, so they're recovered
        $result = $this->service->publicAdjustAvailabilityForRecovery($player, '2025-03-08 11:30:00');

        $this->assertEquals(75, $result['chance_of_playing']);
    }

    /**
     * Player expected back AFTER the GW deadline → stays at 0.
     */
    public function testStillInjuredPlayerKeepsZero(): void
    {
        $player = $this->makePlayer([
            'chance_of_playing' => 0,
            'news' => 'Hamstring injury - Expected back 15 Mar',
        ]);

        // GW deadline is 8 Mar — player not back yet
        $result = $this->service->publicAdjustAvailabilityForRecovery($player, '2025-03-08 11:30:00');

        $this->assertEquals(0, $result['chance_of_playing']);
    }

    /**
     * Fully available player (chance_of_playing null) → no change.
     */
    public function testFitPlayerUnchanged(): void
    {
        $player = $this->makePlayer([
            'chance_of_playing' => null,
            'news' => '',
        ]);

        $result = $this->service->publicAdjustAvailabilityForRecovery($player, '2025-03-08 11:30:00');

        $this->assertNull($result['chance_of_playing']);
    }

    /**
     * Long-term injury with no return date → stays at 0.
     */
    public function testLongTermInjuryStaysZero(): void
    {
        $player = $this->makePlayer([
            'chance_of_playing' => 0,
            'news' => 'ACL injury - out for the season',
        ]);

        $result = $this->service->publicAdjustAvailabilityForRecovery($player, '2025-03-08 11:30:00');

        $this->assertEquals(0, $result['chance_of_playing']);
    }

    /**
     * Doubtful player (chance_of_playing 50) → no change (only adjusts 0%).
     */
    public function testDoubtfulPlayerUnchanged(): void
    {
        $player = $this->makePlayer([
            'chance_of_playing' => 50,
            'news' => 'Knock - Expected back 1 Mar',
        ]);

        $result = $this->service->publicAdjustAvailabilityForRecovery($player, '2025-03-08 11:30:00');

        $this->assertEquals(50, $result['chance_of_playing']);
    }

    /**
     * Recovery on deadline day → still treated as recovered (expected back <= deadline).
     */
    public function testRecoveryOnDeadlineDayIsRecovered(): void
    {
        $player = $this->makePlayer([
            'chance_of_playing' => 0,
            'news' => 'Hamstring - Expected back 8 Mar',
        ]);

        $result = $this->service->publicAdjustAvailabilityForRecovery($player, '2025-03-08 11:30:00');

        $this->assertEquals(75, $result['chance_of_playing']);
    }

    /**
     * News with "Expected back early March" (no specific date) → no change.
     */
    public function testVagueReturnDateUnchanged(): void
    {
        $player = $this->makePlayer([
            'chance_of_playing' => 0,
            'news' => 'Expected back early March',
        ]);

        $result = $this->service->publicAdjustAvailabilityForRecovery($player, '2025-03-15 11:30:00');

        $this->assertEquals(0, $result['chance_of_playing']);
    }
}
