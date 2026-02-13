<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\PredictionService;
use SuperFPL\Api\Services\TransferOptimizerService;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;

/**
 * Tests that TransferOptimizerService fetches the correct GW picks
 * based on whether the current gameweek has started.
 *
 * Bug: When GW hasn't started, picks(currentGw) returns 404 on the FPL API.
 * The PHP warning from file_get_contents leaked into the HTTP response,
 * prepending HTML before JSON and causing "JSON.parse: unexpected character".
 *
 * Fix: Check hasGameweekStarted() before deciding which GW picks to fetch,
 * avoiding the doomed request entirely.
 */
class TransferOptimizerPicksTest extends TestCase
{
    private function createMockPicks(): array
    {
        return [
            'picks' => array_map(fn($id) => ['element' => $id, 'position' => $id, 'multiplier' => 1], range(1, 15)),
        ];
    }

    public function testFetchesPreviousGwPicksWhenCurrentGwNotStarted(): void
    {
        $db = $this->createMock(Database::class);
        $fplClient = $this->createMock(FplClient::class);
        $predictionService = $this->createMock(PredictionService::class);
        $gameweekService = $this->createMock(GameweekService::class);

        // GW26 is current, has NOT started
        $gameweekService->method('getCurrentGameweek')->willReturn(26);
        $gameweekService->method('hasGameweekStarted')->with(26)->willReturn(false);
        $gameweekService->method('getNextActionableGameweek')->willReturn(26);
        $gameweekService->method('getUpcomingGameweeks')->willReturn([26, 27]);
        $gameweekService->method('getFixtureCounts')->willReturn([26 => [], 27 => []]);

        $entryEndpoint = $this->createMock(EntryEndpoint::class);
        $entryEndpoint->method('getRaw')->willReturn([
            'last_deadline_bank' => 20,
            'last_deadline_value' => 1000,
        ]);

        // Should fetch picks(25), NOT picks(26)
        $entryEndpoint->expects($this->once())
            ->method('picks')
            ->with(25)
            ->willReturn($this->createMockPicks());

        $fplClient->method('entry')->willReturn($entryEndpoint);

        // Stub remaining DB/prediction calls so getOptimalPlan doesn't blow up
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchOne')->willReturn(null);
        $predictionService->method('getPredictions')->willReturn([]);

        $service = new TransferOptimizerService($db, $fplClient, $predictionService, $gameweekService);
        $result = $service->getOptimalPlan(8028, skipSolve: true);

        $this->assertIsArray($result);
    }

    public function testFetchesCurrentGwPicksWhenGameweekStarted(): void
    {
        $db = $this->createMock(Database::class);
        $fplClient = $this->createMock(FplClient::class);
        $predictionService = $this->createMock(PredictionService::class);
        $gameweekService = $this->createMock(GameweekService::class);

        // GW26 is current, HAS started
        $gameweekService->method('getCurrentGameweek')->willReturn(26);
        $gameweekService->method('hasGameweekStarted')->with(26)->willReturn(true);
        $gameweekService->method('getNextActionableGameweek')->willReturn(27);
        $gameweekService->method('getUpcomingGameweeks')->willReturn([27, 28]);
        $gameweekService->method('getFixtureCounts')->willReturn([27 => [], 28 => []]);

        $entryEndpoint = $this->createMock(EntryEndpoint::class);
        $entryEndpoint->method('getRaw')->willReturn([
            'last_deadline_bank' => 20,
            'last_deadline_value' => 1000,
        ]);

        // Should fetch picks(26) since GW has started
        $entryEndpoint->expects($this->once())
            ->method('picks')
            ->with(26)
            ->willReturn($this->createMockPicks());

        $fplClient->method('entry')->willReturn($entryEndpoint);

        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchOne')->willReturn(null);
        $predictionService->method('getPredictions')->willReturn([]);

        $service = new TransferOptimizerService($db, $fplClient, $predictionService, $gameweekService);
        $result = $service->getOptimalPlan(8028, skipSolve: true);

        $this->assertIsArray($result);
    }

    public function testGw1NotStartedFetchesPicks1NotPicks0(): void
    {
        $db = $this->createMock(Database::class);
        $fplClient = $this->createMock(FplClient::class);
        $predictionService = $this->createMock(PredictionService::class);
        $gameweekService = $this->createMock(GameweekService::class);

        // GW1 hasn't started — edge case: max(1, 1-1) = max(1, 0) = 1
        $gameweekService->method('getCurrentGameweek')->willReturn(1);
        $gameweekService->method('hasGameweekStarted')->with(1)->willReturn(false);
        $gameweekService->method('getNextActionableGameweek')->willReturn(1);
        $gameweekService->method('getUpcomingGameweeks')->willReturn([1, 2]);
        $gameweekService->method('getFixtureCounts')->willReturn([1 => [], 2 => []]);

        $entryEndpoint = $this->createMock(EntryEndpoint::class);
        $entryEndpoint->method('getRaw')->willReturn([
            'last_deadline_bank' => 20,
            'last_deadline_value' => 1000,
        ]);

        // Should fetch picks(1) not picks(0) — max(1, 0) guard
        $entryEndpoint->expects($this->once())
            ->method('picks')
            ->with(1)
            ->willReturn($this->createMockPicks());

        $fplClient->method('entry')->willReturn($entryEndpoint);

        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchOne')->willReturn(null);
        $predictionService->method('getPredictions')->willReturn([]);

        $service = new TransferOptimizerService($db, $fplClient, $predictionService, $gameweekService);
        $result = $service->getOptimalPlan(8028, skipSolve: true);

        $this->assertIsArray($result);
    }

    public function testChipModeNoneClearsResolvedChipPlan(): void
    {
        $db = $this->createMock(Database::class);
        $fplClient = $this->createMock(FplClient::class);
        $predictionService = $this->createMock(PredictionService::class);
        $gameweekService = $this->createMock(GameweekService::class);

        $gameweekService->method('getCurrentGameweek')->willReturn(26);
        $gameweekService->method('hasGameweekStarted')->with(26)->willReturn(true);
        $gameweekService->method('getNextActionableGameweek')->willReturn(27);
        $gameweekService->method('getUpcomingGameweeks')->willReturn([27, 28]);
        $gameweekService->method('getFixtureCounts')->willReturn([27 => [], 28 => []]);

        $entryEndpoint = $this->createMock(EntryEndpoint::class);
        $entryEndpoint->method('getRaw')->willReturn([
            'last_deadline_bank' => 20,
            'last_deadline_value' => 1000,
        ]);
        $entryEndpoint->method('picks')->with(26)->willReturn($this->createMockPicks());
        $fplClient->method('entry')->willReturn($entryEndpoint);

        $db->method('fetchAll')->willReturn([]);
        $predictionService->method('getPredictions')->willReturn([]);

        $service = new TransferOptimizerService($db, $fplClient, $predictionService, $gameweekService);
        $result = $service->getOptimalPlan(
            8028,
            ['triple_captain' => 27],
            skipSolve: true,
            chipMode: 'none'
        );

        $this->assertSame([], $result['resolved_chip_plan']);
        $this->assertSame('none', $result['chip_mode']);
    }

    public function testSuggestChipPlanIncludesRankedSuggestions(): void
    {
        $db = $this->createMock(Database::class);
        $fplClient = $this->createMock(FplClient::class);
        $predictionService = $this->createMock(PredictionService::class);
        $gameweekService = $this->createMock(GameweekService::class);

        $gameweekService->method('getCurrentGameweek')->willReturn(26);
        $gameweekService->method('hasGameweekStarted')->with(26)->willReturn(true);
        $gameweekService->method('getNextActionableGameweek')->willReturn(27);
        $gameweekService->method('getUpcomingGameweeks')->willReturn([27, 28]);
        $gameweekService->method('getFixtureCounts')->willReturn([27 => [], 28 => []]);

        $entryEndpoint = $this->createMock(EntryEndpoint::class);
        $entryEndpoint->method('getRaw')->willReturn([
            'last_deadline_bank' => 20,
            'last_deadline_value' => 1000,
        ]);
        $entryEndpoint->method('picks')->with(26)->willReturn($this->createMockPicks());
        $fplClient->method('entry')->willReturn($entryEndpoint);

        $db->method('fetchAll')->willReturn([]);
        $predictionService->method('getPredictions')->willReturn([]);

        $service = new TransferOptimizerService($db, $fplClient, $predictionService, $gameweekService);
        $result = $service->suggestChipPlan(8028);

        $this->assertArrayHasKey('suggestions', $result);
        $this->assertIsArray($result['suggestions']);
        $this->assertArrayHasKey('recommended_plan', $result);
    }

    public function testInvalidOverlappingLockAndAvoidConstraintsThrows(): void
    {
        $db = $this->createMock(Database::class);
        $fplClient = $this->createMock(FplClient::class);
        $predictionService = $this->createMock(PredictionService::class);
        $gameweekService = $this->createMock(GameweekService::class);

        $gameweekService->method('getCurrentGameweek')->willReturn(26);
        $gameweekService->method('hasGameweekStarted')->with(26)->willReturn(true);
        $gameweekService->method('getNextActionableGameweek')->willReturn(27);
        $gameweekService->method('getUpcomingGameweeks')->willReturn([27, 28]);
        $gameweekService->method('getFixtureCounts')->willReturn([27 => [], 28 => []]);

        $entryEndpoint = $this->createMock(EntryEndpoint::class);
        $entryEndpoint->method('getRaw')->willReturn([
            'last_deadline_bank' => 20,
            'last_deadline_value' => 1000,
        ]);
        $entryEndpoint->method('picks')->with(26)->willReturn($this->createMockPicks());
        $fplClient->method('entry')->willReturn($entryEndpoint);

        $db->method('fetchAll')->willReturn([]);
        $predictionService->method('getPredictions')->willReturn([]);

        $service = new TransferOptimizerService($db, $fplClient, $predictionService, $gameweekService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lock and avoid lists overlap');

        $service->getOptimalPlan(
            8028,
            skipSolve: true,
            constraints: [
                'lock_ids' => [13],
                'avoid_ids' => [13],
            ]
        );
    }
}
