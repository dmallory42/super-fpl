<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\LiveService;
use SuperFPL\FplClient\FplClient;

class LiveServiceTest extends TestCase
{
    private LiveService $service;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $fplClient = $this->createMock(FplClient::class);
        $this->service = new LiveService($db, $fplClient, sys_get_temp_dir());
    }

    public function testAssignBonusFromBpsHandlesTieForFirst(): void
    {
        $predictions = $this->invokeAssignBonusFromBps([
            ['player_id' => 1, 'bps' => 40, 'fixture_id' => 100],
            ['player_id' => 2, 'bps' => 40, 'fixture_id' => 100],
            ['player_id' => 3, 'bps' => 30, 'fixture_id' => 100],
            ['player_id' => 4, 'bps' => 20, 'fixture_id' => 100],
        ]);

        $this->assertSame([
            1 => 3,
            2 => 3,
            3 => 1,
        ], $this->mapBonusByPlayer($predictions));
    }

    public function testAssignBonusFromBpsHandlesTieForSecond(): void
    {
        $predictions = $this->invokeAssignBonusFromBps([
            ['player_id' => 1, 'bps' => 50, 'fixture_id' => 100],
            ['player_id' => 2, 'bps' => 30, 'fixture_id' => 100],
            ['player_id' => 3, 'bps' => 30, 'fixture_id' => 100],
            ['player_id' => 4, 'bps' => 20, 'fixture_id' => 100],
        ]);

        $this->assertSame([
            1 => 3,
            2 => 2,
            3 => 2,
        ], $this->mapBonusByPlayer($predictions));
    }

    public function testAssignBonusFromBpsHandlesTieForThird(): void
    {
        $predictions = $this->invokeAssignBonusFromBps([
            ['player_id' => 1, 'bps' => 50, 'fixture_id' => 100],
            ['player_id' => 2, 'bps' => 40, 'fixture_id' => 100],
            ['player_id' => 3, 'bps' => 30, 'fixture_id' => 100],
            ['player_id' => 4, 'bps' => 30, 'fixture_id' => 100],
        ]);

        $this->assertSame([
            1 => 3,
            2 => 2,
            3 => 1,
            4 => 1,
        ], $this->mapBonusByPlayer($predictions));
    }

    public function testFixtureEligibilityRequiresStartedOrRecentlyFinished(): void
    {
        $now = time();

        $this->assertFalse($this->invokeFixtureEligibility([
            'kickoff_time' => date('Y-m-d H:i:s', $now + 3600),
            'finished' => 0,
        ]));

        $this->assertTrue($this->invokeFixtureEligibility([
            'kickoff_time' => date('Y-m-d H:i:s', $now - 3600),
            'finished' => 0,
        ]));

        $this->assertTrue($this->invokeFixtureEligibility([
            'kickoff_time' => date('Y-m-d H:i:s', $now - 7200),
            'finished' => 1,
        ]));

        $this->assertFalse($this->invokeFixtureEligibility([
            'kickoff_time' => date('Y-m-d H:i:s', $now - 8 * 3600),
            'finished' => 1,
        ]));
    }

    /**
     * @param array<int, array{player_id: int, bps: int, fixture_id: int}> $fixturePlayers
     * @return array<int, array{player_id: int, bps: int, predicted_bonus: int, fixture_id: int}>
     */
    private function invokeAssignBonusFromBps(array $fixturePlayers): array
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('assignBonusFromBps');
        $method->setAccessible(true);

        /** @var array<int, array{player_id: int, bps: int, predicted_bonus: int, fixture_id: int}> $result */
        $result = $method->invoke($this->service, $fixturePlayers);
        return $result;
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function invokeFixtureEligibility(array $fixture): bool
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('isFixtureEligibleForProvisionalBonus');
        $method->setAccessible(true);

        return (bool) $method->invoke($this->service, $fixture);
    }

    /**
     * @param array<int, array{player_id: int, predicted_bonus: int}> $predictions
     * @return array<int, int>
     */
    private function mapBonusByPlayer(array $predictions): array
    {
        $map = [];
        foreach ($predictions as $prediction) {
            $map[(int) $prediction['player_id']] = (int) $prediction['predicted_bonus'];
        }
        ksort($map);
        return $map;
    }
}
