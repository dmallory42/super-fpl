<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\PredictionService;
use SuperFPL\Api\Services\TransferOptimizerService;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;
use SuperFPL\FplClient\FplClient;

class TransferOptimizerServiceTest extends TestCase
{
    public function testGetOptimalPlanReturnsUpgradeRecommendation(): void
    {
        $players = $this->buildBasePlayers();
        $players[] = $this->makePlayer(99, 'UpgradeMID', 3, 20, 50);

        $predictions = $this->buildPredictionsForGameweek($players, 27, 4.0);
        foreach ($predictions as &$row) {
            if ((int) $row['player_id'] === 12) {
                $row['predicted_points'] = 1.0;
                $row['predicted_if_fit'] = 1.0;
            }
            if ((int) $row['player_id'] === 99) {
                $row['predicted_points'] = 9.0;
                $row['predicted_if_fit'] = 9.0;
            }
        }
        unset($row);

        $service = $this->makeService($players, [27 => $predictions], [27]);
        $result = $service->getOptimalPlan(
            8028,
            freeTransfers: 1,
            skipSolve: true,
            planningHorizon: 1
        );

        $this->assertNotEmpty($result['recommendations']);
        $best = $result['recommendations'][0];
        $this->assertSame(12, (int) $best['out']['id']);
        $this->assertSame(99, (int) $best['in']['id']);
        $this->assertTrue((bool) $best['recommended']);
    }

    public function testGetOptimalPlanSupportsHorizonOneAndThree(): void
    {
        $players = $this->buildBasePlayers();
        $predGw27 = $this->buildPredictionsForGameweek($players, 27, 4.0);
        $predGw28 = $this->buildPredictionsForGameweek($players, 28, 4.0);
        $predGw29 = $this->buildPredictionsForGameweek($players, 29, 4.0);

        $service = $this->makeService(
            $players,
            [27 => $predGw27, 28 => $predGw28, 29 => $predGw29],
            [27, 28, 29]
        );

        $h1 = $service->getOptimalPlan(8028, freeTransfers: 1, skipSolve: true, planningHorizon: 1);
        $h3 = $service->getOptimalPlan(8028, freeTransfers: 1, skipSolve: true, planningHorizon: 3);

        $this->assertSame([27], $h1['planning_horizon']);
        $this->assertSame([27, 28, 29], $h3['planning_horizon']);
    }

    public function testSuggestChipPlanReturnsRankedOutputShape(): void
    {
        $players = $this->buildBasePlayers();
        $predGw27 = $this->buildPredictionsForGameweek($players, 27, 5.0);
        $predGw28 = $this->buildPredictionsForGameweek($players, 28, 6.0);
        $predGw29 = $this->buildPredictionsForGameweek($players, 29, 4.0);

        $service = $this->makeService(
            $players,
            [27 => $predGw27, 28 => $predGw28, 29 => $predGw29],
            [27, 28, 29]
        );

        $result = $service->suggestChipPlan(8028, planningHorizon: 3);

        $this->assertArrayHasKey('suggestions', $result);
        $this->assertArrayHasKey('recommended_plan', $result);
        $this->assertIsArray($result['suggestions']);
        $this->assertIsArray($result['recommended_plan']);
    }

    /**
     * @param array<int, array<string, mixed>> $players
     * @param array<int, array<int, array<string, mixed>>> $predictionsByGw
     * @param array<int, int> $allUpcomingGws
     */
    private function makeService(array $players, array $predictionsByGw, array $allUpcomingGws): TransferOptimizerService
    {
        $db = $this->createMock(Database::class);
        $fplClient = $this->createMock(FplClient::class);
        $predictionService = $this->createMock(PredictionService::class);
        $gameweekService = $this->createMock(GameweekService::class);

        $gameweekService->method('getCurrentGameweek')->willReturn(27);
        $gameweekService->method('hasGameweekStarted')->with(27)->willReturn(true);
        $gameweekService->method('getNextActionableGameweek')->willReturn(27);
        $gameweekService->method('getUpcomingGameweeks')->willReturnCallback(
            static function (int $horizon, int $startGw = 27) use ($allUpcomingGws): array {
                $filtered = array_values(array_filter(
                    $allUpcomingGws,
                    static fn(int $gw): bool => $gw >= $startGw
                ));
                return array_slice($filtered, 0, $horizon);
            }
        );
        $gameweekService->method('getFixtureCounts')->willReturnCallback(
            static function (array $gws) use ($players): array {
                $counts = [];
                foreach ($gws as $gw) {
                    $counts[$gw] = [];
                    foreach ($players as $player) {
                        $team = (int) $player['club_id'];
                        $counts[$gw][$team] = 1;
                    }
                }
                return $counts;
            }
        );

        $entryEndpoint = $this->createMock(EntryEndpoint::class);
        $entryEndpoint->method('getRaw')->willReturn([
            'last_deadline_bank' => 20,
            'last_deadline_value' => 1000,
        ]);
        $entryEndpoint->method('picks')->with(27)->willReturn([
            'picks' => array_map(
                static fn(array $player, int $index): array => [
                    'element' => (int) $player['id'],
                    'position' => $index + 1,
                    'multiplier' => 1,
                ],
                array_slice($players, 0, 15),
                array_keys(array_slice($players, 0, 15))
            ),
        ]);
        $entryEndpoint->method('history')->willReturn([
            'current' => [],
            'chips' => [],
        ]);
        $fplClient->method('entry')->willReturn($entryEndpoint);

        $db->method('fetchAll')->willReturn($players);
        $predictionService->method('getPredictions')->willReturnCallback(
            static fn(int $gw): array => $predictionsByGw[$gw] ?? []
        );

        return new TransferOptimizerService($db, $fplClient, $predictionService, $gameweekService);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBasePlayers(): array
    {
        return [
            $this->makePlayer(1, 'GK1', 1, 1, 50),
            $this->makePlayer(2, 'GK2', 1, 2, 50),
            $this->makePlayer(3, 'DEF1', 2, 3, 50),
            $this->makePlayer(4, 'DEF2', 2, 4, 50),
            $this->makePlayer(5, 'DEF3', 2, 5, 50),
            $this->makePlayer(6, 'DEF4', 2, 6, 50),
            $this->makePlayer(7, 'DEF5', 2, 7, 50),
            $this->makePlayer(8, 'MID1', 3, 8, 50),
            $this->makePlayer(9, 'MID2', 3, 9, 50),
            $this->makePlayer(10, 'MID3', 3, 10, 50),
            $this->makePlayer(11, 'MID4', 3, 11, 50),
            $this->makePlayer(12, 'MID5', 3, 12, 50),
            $this->makePlayer(13, 'FWD1', 4, 13, 50),
            $this->makePlayer(14, 'FWD2', 4, 14, 50),
            $this->makePlayer(15, 'FWD3', 4, 15, 50),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $players
     * @return array<int, array<string, mixed>>
     */
    private function buildPredictionsForGameweek(array $players, int $gw, float $basePoints): array
    {
        return array_map(
            static fn(array $player): array => [
                'player_id' => (int) $player['id'],
                'predicted_points' => $basePoints,
                'predicted_if_fit' => $basePoints,
                'expected_mins' => 90.0,
                'confidence' => 1.0,
                'position' => (int) $player['position'],
                'team' => (int) $player['club_id'],
                'gameweek' => $gw,
            ],
            $players
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makePlayer(int $id, string $name, int $position, int $team, int $cost): array
    {
        return [
            'id' => $id,
            'web_name' => $name,
            'position' => $position,
            'club_id' => $team,
            'now_cost' => $cost,
            'chance_of_playing' => 100,
        ];
    }
}
