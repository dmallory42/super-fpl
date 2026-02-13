<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Services\LeagueSeasonAnalysisService;
use SuperFPL\Api\Services\LeagueService;
use SuperFPL\Api\Services\ManagerSeasonAnalysisService;

class LeagueSeasonAnalysisServiceTest extends TestCase
{
    public function testAnalyzeBuildsDeterministicAxisAndStableOrdering(): void
    {
        $leagueService = $this->createMock(LeagueService::class);
        $leagueService->method('getLeague')->with(777)->willReturn([
            'league' => ['name' => 'Test League'],
            'standings' => [
                'results' => [
                    ['entry' => 200, 'player_name' => 'B', 'entry_name' => 'B XI', 'rank' => 2, 'total' => 1200],
                    ['entry' => 100, 'player_name' => 'A', 'entry_name' => 'A XI', 'rank' => 1, 'total' => 1250],
                ],
            ],
        ]);

        $managerService = $this->createMock(ManagerSeasonAnalysisService::class);
        $managerService->method('analyze')->willReturnCallback(static function (int $managerId): ?array {
            if ($managerId === 100) {
                return [
                    'gameweeks' => [
                        [
                            'gameweek' => 1,
                            'actual_points' => 50,
                            'expected_points' => 45,
                            'luck_delta' => 5,
                            'event_transfers' => 0,
                            'event_transfers_cost' => 0,
                            'captain_impact' => ['actual_gain' => 8],
                            'chip_impact' => ['chips' => []],
                        ],
                        [
                            'gameweek' => 2,
                            'actual_points' => 42,
                            'expected_points' => 40,
                            'luck_delta' => 2,
                            'event_transfers' => 1,
                            'event_transfers_cost' => 4,
                            'captain_impact' => ['actual_gain' => 2],
                            'chip_impact' => ['chips' => ['wildcard']],
                        ],
                    ],
                    'transfer_analytics' => [
                        ['gameweek' => 2, 'transfer_cost' => 4, 'net_gain' => 3],
                    ],
                ];
            }

            if ($managerId === 200) {
                return [
                    'gameweeks' => [
                        [
                            'gameweek' => 2,
                            'actual_points' => 41,
                            'expected_points' => 44,
                            'luck_delta' => -3,
                            'event_transfers' => 0,
                            'event_transfers_cost' => 0,
                            'captain_impact' => ['actual_gain' => 1],
                            'chip_impact' => ['chips' => []],
                        ],
                    ],
                    'transfer_analytics' => [],
                ];
            }

            return null;
        });

        $service = new LeagueSeasonAnalysisService($leagueService, $managerService);
        $result = $service->analyze(777, 1, 2);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame([1, 2], $result['gameweek_axis']);
        $this->assertCount(2, $result['managers']);
        $this->assertSame(100, $result['managers'][0]['manager_id']);
        $this->assertSame(200, $result['managers'][1]['manager_id']);

        $managerBgw1 = $result['managers'][1]['gameweeks'][0];
        $this->assertSame(1, $managerBgw1['gameweek']);
        $this->assertTrue($managerBgw1['missing']);

        $this->assertCount(2, $result['benchmarks']);
        $this->assertSame(1, $result['benchmarks'][0]['gameweek']);
        $this->assertSame(2, $result['benchmarks'][1]['gameweek']);
    }

    public function testAnalyzeReturnsErrorWhenLeagueTooSmall(): void
    {
        $leagueService = $this->createMock(LeagueService::class);
        $leagueService->method('getLeague')->willReturn([
            'league' => ['name' => 'Tiny League'],
            'standings' => ['results' => [['entry' => 123, 'rank' => 1]]],
        ]);

        $managerService = $this->createMock(ManagerSeasonAnalysisService::class);
        $service = new LeagueSeasonAnalysisService($leagueService, $managerService);
        $result = $service->analyze(1);

        $this->assertSame(400, $result['status']);
        $this->assertSame('League needs at least 2 managers', $result['error']);
    }

    public function testAnalyzeSupportsGameweekRangeFilter(): void
    {
        $leagueService = $this->createMock(LeagueService::class);
        $leagueService->method('getLeague')->willReturn([
            'league' => ['name' => 'Range League'],
            'standings' => [
                'results' => [
                    ['entry' => 100, 'player_name' => 'A', 'entry_name' => 'A XI', 'rank' => 1, 'total' => 1250],
                    ['entry' => 200, 'player_name' => 'B', 'entry_name' => 'B XI', 'rank' => 2, 'total' => 1200],
                ],
            ],
        ]);

        $managerService = $this->createMock(ManagerSeasonAnalysisService::class);
        $managerService->method('analyze')->willReturn([
            'gameweeks' => [
                ['gameweek' => 1, 'actual_points' => 10, 'expected_points' => 8, 'luck_delta' => 2, 'event_transfers' => 0, 'event_transfers_cost' => 0, 'captain_impact' => ['actual_gain' => 0], 'chip_impact' => ['chips' => []]],
                ['gameweek' => 2, 'actual_points' => 11, 'expected_points' => 9, 'luck_delta' => 2, 'event_transfers' => 0, 'event_transfers_cost' => 0, 'captain_impact' => ['actual_gain' => 0], 'chip_impact' => ['chips' => []]],
                ['gameweek' => 3, 'actual_points' => 12, 'expected_points' => 10, 'luck_delta' => 2, 'event_transfers' => 0, 'event_transfers_cost' => 0, 'captain_impact' => ['actual_gain' => 0], 'chip_impact' => ['chips' => []]],
            ],
            'transfer_analytics' => [],
        ]);

        $service = new LeagueSeasonAnalysisService($leagueService, $managerService);
        $result = $service->analyze(1, 2, 3);

        $this->assertSame([2, 3], $result['gameweek_axis']);
        $this->assertCount(2, $result['managers'][0]['gameweeks']);
        $this->assertSame(2, $result['managers'][0]['gameweeks'][0]['gameweek']);
        $this->assertSame(3, $result['managers'][0]['gameweeks'][1]['gameweek']);
    }
}
