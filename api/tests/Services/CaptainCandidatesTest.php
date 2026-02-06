<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Services\TransferOptimizerService;

class CaptainCandidatesTest extends TestCase
{
    public function testSingleClearWinner(): void
    {
        $starting11 = [
            ['id' => 1, 'pred' => 8.0],
            ['id' => 2, 'pred' => 5.5],
            ['id' => 3, 'pred' => 4.0],
        ];

        $candidates = TransferOptimizerService::findCaptainCandidates($starting11);

        // Top player is 2.5+ pts ahead — only 1 candidate
        $this->assertCount(1, $candidates);
        $this->assertEquals(1, $candidates[0]['player_id']);
        $this->assertEquals(8.0, $candidates[0]['predicted_points']);
        $this->assertEquals(0.0, $candidates[0]['margin']);
    }

    public function testMultipleCloseOptions(): void
    {
        $starting11 = [
            ['id' => 1, 'pred' => 6.5],
            ['id' => 2, 'pred' => 6.3],
            ['id' => 3, 'pred' => 6.1],
            ['id' => 4, 'pred' => 3.0],
        ];

        $candidates = TransferOptimizerService::findCaptainCandidates($starting11, 0.5);

        // Top 3 are within 0.5 of the best
        $this->assertCount(3, $candidates);
        $this->assertEquals(1, $candidates[0]['player_id']);
        $this->assertEquals(0.0, $candidates[0]['margin']);
        $this->assertEquals(0.2, $candidates[1]['margin']);
        $this->assertEquals(0.4, $candidates[2]['margin']);
    }

    public function testEmptyInput(): void
    {
        $candidates = TransferOptimizerService::findCaptainCandidates([]);

        $this->assertIsArray($candidates);
        $this->assertEmpty($candidates);
    }

    public function testMarginCalculation(): void
    {
        $starting11 = [
            ['id' => 10, 'pred' => 7.0],
            ['id' => 20, 'pred' => 6.8],
            ['id' => 30, 'pred' => 2.0],
        ];

        $candidates = TransferOptimizerService::findCaptainCandidates($starting11, 0.5);

        $this->assertCount(2, $candidates);

        // First candidate margin = 0 (they're the top)
        $this->assertEquals(10, $candidates[0]['player_id']);
        $this->assertEquals(0.0, $candidates[0]['margin']);

        // Second candidate margin = 7.0 - 6.8 = 0.2
        $this->assertEquals(20, $candidates[1]['player_id']);
        $this->assertEquals(0.2, $candidates[1]['margin']);
    }
}
