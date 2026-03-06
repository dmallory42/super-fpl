<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\ComparisonService;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\LeagueSeasonAnalysisService;
use SuperFPL\Api\Services\LeagueService;
use SuperFPL\Api\Services\ManagerSeasonAnalysisService;
use SuperFPL\FplClient\FplClient;

#[Controller('/leagues')]
class LeagueController extends LegacyController
{
    public function __construct(
        Database $db,
        Config $config,
        private readonly FplClient $fplClient
    ) {
        parent::__construct($db, $config);
    }

    #[Route('/{id}', method: 'GET')]
    public function show(int $id, Request $request): Response
    {
        $page = $request->query('page');
        $service = new LeagueService($this->db, $this->fplClient);
        $league = $service->getLeague($id, $page !== null ? (int) $page : 1);

        if ($league === null) {
            return Response::json(['error' => 'League not found'], 404);
        }

        return Response::json($league);
    }

    #[Route('/{id}/standings', method: 'GET')]
    public function standings(int $id): Response
    {
        $service = new LeagueService($this->db, $this->fplClient);
        $standings = $service->getAllStandings($id);

        return Response::json([
            'league_id' => $id,
            'standings' => $standings,
        ]);
    }

    #[Route('/{id}/analysis', method: 'GET')]
    public function analysis(int $id, Request $request): Response
    {
        $gameweek = $request->query('gw');
        if ($gameweek === null) {
            $gameweek = (new GameweekService($this->db))->getCurrentGameweek();
        } else {
            $gameweek = (int) $gameweek;
        }

        $leagueService = new LeagueService($this->db, $this->fplClient);
        $league = $leagueService->getLeague($id);

        if ($league === null) {
            return Response::json(['error' => 'League not found'], 404);
        }

        $standings = $league['standings']['results'] ?? [];
        if (!is_array($standings)) {
            $standings = [];
        }

        $managerIds = array_slice(array_column($standings, 'entry'), 0, 20);
        if (count($managerIds) < 2) {
            return Response::json(['error' => 'League needs at least 2 managers'], 400);
        }

        $comparisonService = new ComparisonService($this->db, $this->fplClient);
        $comparison = $comparisonService->compare($managerIds, (int) $gameweek);

        return Response::json([
            'league' => [
                'id' => $id,
                'name' => $league['league']['name'] ?? 'Unknown',
            ],
            'gameweek' => (int) $gameweek,
            'managers' => array_map(
                static fn(array $standing): array => [
                    'id' => $standing['entry'] ?? null,
                    'name' => $standing['player_name'] ?? null,
                    'team_name' => $standing['entry_name'] ?? null,
                    'rank' => $standing['rank'] ?? null,
                    'total' => $standing['total'] ?? null,
                ],
                array_slice($standings, 0, 20)
            ),
            'comparison' => $comparison,
        ]);
    }

    #[Route('/{id}/season-analysis', method: 'GET')]
    public function seasonAnalysis(int $id, Request $request): Response
    {
        $gwFrom = $request->query('gw_from');
        $gwTo = $request->query('gw_to');
        $topN = $request->query('top_n');

        $leagueService = new LeagueService($this->db, $this->fplClient);
        $managerSeasonService = new ManagerSeasonAnalysisService($this->db, $this->fplClient);
        $service = new LeagueSeasonAnalysisService($leagueService, $managerSeasonService);
        $analysis = $service->analyze(
            $id,
            $gwFrom !== null ? (int) $gwFrom : null,
            $gwTo !== null ? (int) $gwTo : null,
            max(2, min((int) ($topN ?? 20), 50))
        );

        if (isset($analysis['error'])) {
            return Response::json(['error' => $analysis['error']], (int) ($analysis['status'] ?? 400));
        }

        return Response::json($analysis);
    }
}
