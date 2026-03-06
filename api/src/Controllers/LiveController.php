<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\LiveService;
use SuperFPL\Api\Services\OwnershipService;
use SuperFPL\Api\Services\SampleService;
use SuperFPL\FplClient\FplClient;

#[Controller('/live')]
class LiveController extends LegacyController
{
    public function __construct(
        Database $db,
        Config $config,
        private readonly FplClient $fplClient
    ) {
        parent::__construct($db, $config);
    }

    #[Route('/current', method: 'GET')]
    public function current(): Response
    {
        $gameweekService = new GameweekService($this->db);
        $currentGameweek = $gameweekService->getCurrentGameweek();

        $service = new LiveService($this->db, $this->fplClient, $this->cachePath('/live'));
        $data = $service->getLiveData($currentGameweek);
        $data['current_gameweek'] = $currentGameweek;

        return Response::json($data);
    }

    #[Route('/{gw}', method: 'GET')]
    public function gameweek(int $gw): Response
    {
        $service = new LiveService($this->db, $this->fplClient, $this->cachePath('/live'));

        return Response::json($service->getLiveData($gw));
    }

    #[Route('/{gw}/manager/{id}', method: 'GET')]
    public function manager(int $gw, int $id): Response
    {
        $service = new LiveService($this->db, $this->fplClient, $this->cachePath('/live'));

        return Response::json($service->getManagerLivePoints($id, $gw));
    }

    #[Route('/{gw}/manager/{id}/enhanced', method: 'GET')]
    public function managerEnhanced(int $gw, int $id): Response
    {
        $liveService = new LiveService($this->db, $this->fplClient, $this->cachePath('/live'));
        $ownershipService = new OwnershipService($this->db, $this->fplClient, $this->cachePath('/ownership'));

        $data = $liveService->getManagerLivePointsEnhanced($id, $gw, $ownershipService);
        if (isset($data['error'])) {
            return Response::json(['error' => 'Manager or gameweek data not found'], 404);
        }

        return Response::json($data);
    }

    #[Route('/{gw}/bonus', method: 'GET')]
    public function bonus(int $gw): Response
    {
        $service = new LiveService($this->db, $this->fplClient, $this->cachePath('/live'));

        return Response::json([
            'gameweek' => $gw,
            'bonus_predictions' => $service->getBonusPredictions($gw),
        ]);
    }

    #[Route('/{gw}/samples', method: 'GET')]
    public function samples(int $gw): Response
    {
        $liveService = new LiveService($this->db, $this->fplClient, $this->cachePath('/live'));
        $sampleService = new SampleService($this->db, $this->fplClient, $this->cachePath('/samples'));

        $liveData = $liveService->getLiveData($gw);
        $elements = is_array($liveData['elements'] ?? null) ? $liveData['elements'] : [];

        return Response::json($sampleService->getSampleData($gw, $elements));
    }

    private function cachePath(string $suffix): string
    {
        $base = (string) $this->config->get('config.cache.path', dirname(__DIR__, 2) . '/cache');

        return rtrim($base, '/\\') . $suffix;
    }
}
