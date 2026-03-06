<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Tests\Support\TestDatabase;
use SuperFPL\Api\Services\SampleService;
use SuperFPL\FplClient\FplClient;

class SampleServiceTest extends TestCase
{
    private TestDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new TestDatabase(':memory:');
        $this->db->init();
    }

    public function testGetSampleDataComputesTierAverageAndOwnership(): void
    {
        // Two managers in top_10k tier:
        // Manager 1: player 1 captain (x2), player 2.
        // Manager 2: player 1, player 3.
        $this->insertSamplePick(30, 'top_10k', 1001, 1, 2);
        $this->insertSamplePick(30, 'top_10k', 1001, 2, 1);
        $this->insertSamplePick(30, 'top_10k', 1002, 1, 1);
        $this->insertSamplePick(30, 'top_10k', 1002, 3, 1);

        $liveElements = [
            ['id' => 1, 'stats' => ['total_points' => 10]],
            ['id' => 2, 'stats' => ['total_points' => 5]],
            ['id' => 3, 'stats' => ['total_points' => 2]],
        ];

        $service = new SampleService($this->db, $this->createMock(FplClient::class), sys_get_temp_dir());
        $result = $service->getSampleData(30, $liveElements);

        $this->assertArrayHasKey('top_10k', $result['samples']);
        $tier = $result['samples']['top_10k'];

        // Manager points: 25 and 12 => average 18.5
        $this->assertSame(18.5, (float) $tier['avg_points']);
        $this->assertSame(2, (int) $tier['sample_size']);
        $this->assertSame(150.0, (float) $tier['effective_ownership'][1]);
        $this->assertSame(50.0, (float) $tier['effective_ownership'][2]);
        $this->assertSame(50.0, (float) $tier['effective_ownership'][3]);
        $this->assertSame(50.0, (float) $tier['captain_percent'][1]);
    }

    public function testGetSampleDataSkipsTiersWithoutSamples(): void
    {
        $service = new SampleService($this->db, $this->createMock(FplClient::class), sys_get_temp_dir());
        $result = $service->getSampleData(31, []);

        $this->assertSame(31, (int) $result['gameweek']);
        $this->assertSame([], $result['samples']);
    }

    private function insertSamplePick(
        int $gameweek,
        string $tier,
        int $managerId,
        int $playerId,
        int $multiplier
    ): void {
        $this->db->query(
            'INSERT INTO sample_picks (gameweek, tier, manager_id, player_id, multiplier) VALUES (?, ?, ?, ?, ?)',
            [$gameweek, $tier, $managerId, $playerId, $multiplier]
        );
    }
}
