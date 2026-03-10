<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use Maia\Orm\Connection;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\LiveService;
use SuperFPL\Api\Services\OwnershipService;
use SuperFPL\Api\Services\SampleService;
use SuperFPL\FplClient\FplClient;

#[Controller('/live')]
class LiveController extends LegacyController
{
    public function __construct(
        Connection $connection,
        Config $config,
        private readonly FplClient $fplClient
    ) {
        parent::__construct($connection, $config);
    }

    #[Route('/current', method: 'GET')]
    public function get_current_live(): Response
    {
        $gameweekService = new GameweekService($this->connection);
        $currentGameweek = $gameweekService->getCurrentGameweek();

        $service = new LiveService($this->connection, $this->fplClient, $this->cachePath('/live'));
        $data = $service->getLiveData($currentGameweek);
        $data['current_gameweek'] = $currentGameweek;

        return Response::json($data);
    }

    #[Route('/{gw}', method: 'GET')]
    public function get_live_gameweek(int $gw): Response
    {
        $service = new LiveService($this->connection, $this->fplClient, $this->cachePath('/live'));

        return Response::json($service->getLiveData($gw));
    }

    #[Route('/{gw}/manager/{id}', method: 'GET')]
    public function get_live_manager(int $gw, int $id): Response
    {
        $service = new LiveService($this->connection, $this->fplClient, $this->cachePath('/live'));

        return Response::json($service->getManagerLivePoints($id, $gw));
    }

    #[Route('/{gw}/manager/{id}/enhanced', method: 'GET')]
    public function get_live_manager_enhanced(int $gw, int $id): Response
    {
        $liveService = new LiveService($this->connection, $this->fplClient, $this->cachePath('/live'));
        $ownershipService = new OwnershipService($this->connection, $this->fplClient, $this->cachePath('/ownership'));

        $data = $liveService->getManagerLivePointsEnhanced($id, $gw, $ownershipService);
        if (isset($data['error'])) {
            return Response::json(['error' => 'Manager or gameweek data not found'], 404);
        }

        return Response::json($data);
    }

    #[Route('/{gw}/bonus', method: 'GET')]
    public function get_live_bonus(int $gw): Response
    {
        $service = new LiveService($this->connection, $this->fplClient, $this->cachePath('/live'));

        return Response::json([
            'gameweek' => $gw,
            'bonus_predictions' => $service->getBonusPredictions($gw),
        ]);
    }

    #[Route('/{gw}/samples', method: 'GET')]
    public function get_live_samples(int $gw): Response
    {
        $liveService = new LiveService($this->connection, $this->fplClient, $this->cachePath('/live'));
        $sampleService = new SampleService($this->connection, $this->fplClient, $this->cachePath('/samples'));

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
