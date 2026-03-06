<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\ManagerSeasonAnalysisService;
use SuperFPL\Api\Services\ManagerService;
use SuperFPL\FplClient\FplClient;

#[Controller('/managers')]
class ManagerController extends LegacyController
{
    public function __construct(
        Database $db,
        Config $config,
        private readonly FplClient $fplClient
    ) {
        parent::__construct($db, $config);
    }

    #[Route('/{id}', method: 'GET')]
    public function show(int $id): Response
    {
        $service = new ManagerService($this->db, $this->fplClient);
        $manager = $service->getById($id);

        if ($manager === null) {
            return Response::json(['error' => 'Manager not found'], 404);
        }

        return Response::json($manager);
    }

    #[Route('/{id}/picks/{gw}', method: 'GET')]
    public function picks(int $id, int $gw): Response
    {
        $service = new ManagerService($this->db, $this->fplClient);
        $picks = $service->getPicks($id, $gw);

        if ($picks === null) {
            return Response::json(['error' => 'Picks not found'], 404);
        }

        return Response::json($picks);
    }

    #[Route('/{id}/history', method: 'GET')]
    public function history(int $id): Response
    {
        $service = new ManagerService($this->db, $this->fplClient);
        $history = $service->getHistory($id);

        if ($history === null) {
            return Response::json(['error' => 'History not found'], 404);
        }

        return Response::json($history);
    }

    #[Route('/{id}/season-analysis', method: 'GET')]
    public function seasonAnalysis(int $id): Response
    {
        $service = new ManagerSeasonAnalysisService($this->db, $this->fplClient);
        $analysis = $service->analyze($id);

        if ($analysis === null) {
            return Response::json(['error' => 'Season analysis not found'], 404);
        }

        return Response::json($analysis);
    }
}
