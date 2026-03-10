<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use Maia\Orm\Connection;
use SuperFPL\Api\Prediction\PredictionScaler;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\PredictionService;

#[Controller('/predictions')]
class PredictionController extends LegacyController
{
    public function __construct(
        Connection $connection,
        Config $config
    ) {
        parent::__construct($connection, $config);
    }

    #[Route('/range', method: 'GET')]
    public function get_prediction_range(Request $request): Response
    {
        $gameweekService = new GameweekService($this->connection);
        $actionableGameweek = $gameweekService->getNextActionableGameweek();
        $xMinsOverrides = $this->parseXMinsOverridesFromRequest($request);

        $start = $request->query('start');
        $end = $request->query('end');
        $startGameweek = $start !== null ? (int) $start : $actionableGameweek;
        $endGameweek = $end !== null ? (int) $end : min($startGameweek + 5, 38);

        $startGameweek = max($actionableGameweek, min(38, $startGameweek));
        $endGameweek = max($startGameweek, min(38, $endGameweek));
        $gameweeks = range($startGameweek, $endGameweek);
        $placeholders = implode(',', array_fill(0, count($gameweeks), '?'));

        $fixtureRows = $this->fetchAll(
            "SELECT
                f.gameweek,
                f.home_club_id,
                f.away_club_id,
                h.short_name as home_short,
                a.short_name as away_short
            FROM fixtures f
            JOIN clubs h ON f.home_club_id = h.id
            JOIN clubs a ON f.away_club_id = a.id
            WHERE f.gameweek IN ($placeholders)",
            $gameweeks
        );

        $fixturesMap = [];
        $fixtureCounts = [];
        foreach ($fixtureRows as $row) {
            $gw = (int) $row['gameweek'];
            $homeId = (int) $row['home_club_id'];
            $awayId = (int) $row['away_club_id'];

            $fixturesMap[$homeId][$gw][] = ['opponent' => $row['away_short'], 'is_home' => true];
            $fixturesMap[$awayId][$gw][] = ['opponent' => $row['home_short'], 'is_home' => false];

            $fixtureCounts[$gw][$homeId] = ($fixtureCounts[$gw][$homeId] ?? 0) + 1;
            $fixtureCounts[$gw][$awayId] = ($fixtureCounts[$gw][$awayId] ?? 0) + 1;
        }

        $predictions = $this->fetchAll(
            "SELECT
                pp.player_id,
                pp.gameweek,
                pp.predicted_points,
                pp.predicted_if_fit,
                pp.expected_mins,
                pp.expected_mins_if_fit,
                pp.if_fit_breakdown_json,
                pp.confidence,
                p.web_name,
                p.club_id as team,
                p.position,
                p.now_cost,
                p.form,
                p.total_points
            FROM player_predictions pp
            JOIN players p ON pp.player_id = p.id
            WHERE pp.gameweek IN ($placeholders)
            ORDER BY pp.player_id, pp.gameweek",
            $gameweeks
        );

        $playerMap = [];
        foreach ($predictions as $prediction) {
            $playerId = (int) $prediction['player_id'];
            $teamId = (int) $prediction['team'];
            $position = (int) $prediction['position'];
            $gameweek = (int) $prediction['gameweek'];

            if (!isset($playerMap[$playerId])) {
                $playerMap[$playerId] = [
                    'player_id' => $playerId,
                    'web_name' => $prediction['web_name'],
                    'team' => $teamId,
                    'position' => $position,
                    'now_cost' => (int) $prediction['now_cost'],
                    'form' => (float) $prediction['form'],
                    'total_points' => (int) $prediction['total_points'],
                    'expected_mins' => [],
                    'expected_mins_if_fit' => (int) round((float) ($prediction['expected_mins_if_fit'] ?? 90)),
                    'predictions' => [],
                    'if_fit_predictions' => [],
                    'if_fit_breakdowns' => [],
                    'total_predicted' => 0,
                ];
            }

            $ifFitBreakdown = json_decode((string) ($prediction['if_fit_breakdown_json'] ?? '{}'), true);
            if (!is_array($ifFitBreakdown)) {
                $ifFitBreakdown = [];
            }

            $normalizedBreakdown = [];
            foreach ($ifFitBreakdown as $key => $value) {
                if (is_string($key) && is_numeric($value)) {
                    $normalizedBreakdown[$key] = round((float) $value, 2);
                }
            }

            $predictedPoints = (float) $prediction['predicted_points'];
            $override = $this->resolveXMinsOverrideForGameweek($xMinsOverrides, $playerId, $gameweek);
            if ($override !== null) {
                $ifFitPoints = (float) ($prediction['predicted_if_fit'] ?? $predictedPoints);
                $ifFitMinutes = (float) ($prediction['expected_mins_if_fit'] ?? $prediction['expected_mins'] ?? 90);
                $fixtureCount = max(1, (int) ($fixtureCounts[$gameweek][$teamId] ?? 1));
                $predictedPoints = PredictionScaler::scaleFromIfFitBreakdown(
                    $ifFitPoints,
                    $ifFitMinutes,
                    $override,
                    $ifFitBreakdown,
                    $fixtureCount,
                    $position
                );
            }

            $playerMap[$playerId]['expected_mins'][$gameweek] = (int) round((float) ($prediction['expected_mins'] ?? 90));
            $playerMap[$playerId]['predictions'][$gameweek] = round($predictedPoints, 1);
            $playerMap[$playerId]['if_fit_predictions'][$gameweek] = round((float) ($prediction['predicted_if_fit'] ?? 0), 2);
            $playerMap[$playerId]['if_fit_breakdowns'][$gameweek] = $normalizedBreakdown;
            $playerMap[$playerId]['total_predicted'] += $predictedPoints;
        }

        $players = array_values(array_map(
            static function (array $player): array {
                $player['total_predicted'] = round((float) $player['total_predicted'], 1);
                return $player;
            },
            $playerMap
        ));

        usort($players, static fn(array $a, array $b): int => $b['total_predicted'] <=> $a['total_predicted']);

        return Response::json([
            'gameweeks' => $gameweeks,
            'current_gameweek' => $actionableGameweek,
            'players' => $players,
            'fixtures' => $fixturesMap,
            'generated_at' => date('c'),
        ]);
    }

    #[Route('/methodology', method: 'GET')]
    public function get_prediction_methodology(): Response
    {
        return Response::json(PredictionService::getMethodology());
    }

    #[Route('/{gw}', method: 'GET')]
    public function get_predictions_for_gameweek(int $gw, Request $request): Response
    {
        $gameweekService = new GameweekService($this->connection);
        $currentGameweek = $gameweekService->getCurrentGameweek();
        $service = new PredictionService($this->connection);

        if ($gw < $currentGameweek) {
            $predictions = $service->getSnapshotPredictions($gw);
            if ($predictions === []) {
                return Response::json([
                    'error' => 'No prediction snapshot found for this gameweek',
                    'requested_gameweek' => $gw,
                    'current_gameweek' => $currentGameweek,
                ], 404);
            }

            return Response::json([
                'gameweek' => $gw,
                'current_gameweek' => $currentGameweek,
                'source' => 'snapshot',
                'predictions' => $predictions,
                'generated_at' => date('c'),
            ]);
        }

        $response = [
            'gameweek' => $gw,
            'current_gameweek' => $currentGameweek,
            'predictions' => $service->getPredictions($gw),
            'generated_at' => date('c'),
        ];

        if ($request->query('include_methodology') !== null) {
            $response['methodology'] = PredictionService::getMethodology();
        }

        return Response::json($response);
    }

    #[Route('/{gw}/accuracy', method: 'GET')]
    public function get_prediction_accuracy(int $gw): Response
    {
        $service = new PredictionService($this->connection);
        $accuracy = $service->getAccuracy($gw);

        if ((int) (($accuracy['summary']['count'] ?? 0)) === 0) {
            return Response::json([
                'error' => 'No accuracy data available for this gameweek',
                'gameweek' => $gw,
            ], 404);
        }

        return Response::json([
            'gameweek' => $gw,
            'accuracy' => $accuracy,
        ]);
    }

    #[Route('/{gw}/player/{id}', method: 'GET')]
    public function get_player_prediction(int $gw, int $id): Response
    {
        $service = new PredictionService($this->connection);
        $prediction = $service->getPlayerPrediction($id, $gw);

        if ($prediction === null) {
            return Response::json(['error' => 'Player not found'], 404);
        }

        return Response::json($prediction);
    }

}
