<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Services\PlayerService;
use SuperFPL\Api\Services\FixtureService;
use SuperFPL\Api\Services\TeamService;
use SuperFPL\Api\Services\ManagerService;
use SuperFPL\Api\Sync\PlayerSync;
use SuperFPL\Api\Sync\FixtureSync;
use SuperFPL\Api\Sync\ManagerSync;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\Cache\FileCache;

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load config
$config = require __DIR__ . '/../config/config.php';

// Initialize database
$db = new Database($config['database']['path']);
$db->init();

// Initialize FPL client with caching
$cacheDir = $config['cache']['path'];
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cache = new FileCache($cacheDir);
$fplClient = new FplClient(
    cache: $cache,
    cacheTtl: $config['cache']['ttl'],
    rateLimitDir: $config['fpl']['rate_limit_dir']
);

// Simple router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Remove /api prefix if present
if (str_starts_with($uri, '/api')) {
    $uri = substr($uri, 4);
}

try {
    match (true) {
        $uri === '' || $uri === '/health' => handleHealth(),
        $uri === '/players' => handlePlayers($db),
        $uri === '/fixtures' => handleFixtures($db),
        $uri === '/teams' => handleTeams($db),
        $uri === '/sync/players' => handleSyncPlayers($db, $fplClient),
        $uri === '/sync/fixtures' => handleSyncFixtures($db, $fplClient),
        preg_match('#^/players/(\d+)$#', $uri, $m) === 1 => handlePlayer($db, (int) $m[1]),
        preg_match('#^/managers/(\d+)$#', $uri, $m) === 1 => handleManager($db, $fplClient, (int) $m[1]),
        preg_match('#^/managers/(\d+)/picks/(\d+)$#', $uri, $m) === 1 => handleManagerPicks($db, $fplClient, (int) $m[1], (int) $m[2]),
        preg_match('#^/managers/(\d+)/history$#', $uri, $m) === 1 => handleManagerHistory($db, $fplClient, (int) $m[1]),
        $uri === '/sync/managers' => handleSyncManagers($db, $fplClient),
        default => handleNotFound(),
    };
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
}

function handleHealth(): void
{
    echo json_encode(['status' => 'ok', 'timestamp' => date('c')]);
}

function handlePlayers(Database $db): void
{
    $service = new PlayerService($db);

    $filters = [];
    if (isset($_GET['position'])) {
        $filters['position'] = (int) $_GET['position'];
    }
    if (isset($_GET['team'])) {
        $filters['team'] = (int) $_GET['team'];
    }

    $players = $service->getAll($filters);
    $teamService = new TeamService($db);
    $teams = $teamService->getAll();

    echo json_encode([
        'players' => $players,
        'teams' => $teams,
    ]);
}

function handlePlayer(Database $db, int $id): void
{
    $service = new PlayerService($db);
    $player = $service->getById($id);

    if ($player === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Player not found']);
        return;
    }

    echo json_encode($player);
}

function handleFixtures(Database $db): void
{
    $service = new FixtureService($db);
    $gameweek = isset($_GET['gameweek']) ? (int) $_GET['gameweek'] : null;

    $fixtures = $service->getAll($gameweek);
    echo json_encode(['fixtures' => $fixtures]);
}

function handleTeams(Database $db): void
{
    $service = new TeamService($db);
    $teams = $service->getAll();
    echo json_encode(['teams' => $teams]);
}

function handleSyncPlayers(Database $db, FplClient $fplClient): void
{
    $sync = new PlayerSync($db, $fplClient);
    $result = $sync->sync();

    echo json_encode([
        'success' => true,
        'players_synced' => $result['players'],
        'teams_synced' => $result['teams'],
    ]);
}

function handleSyncFixtures(Database $db, FplClient $fplClient): void
{
    $sync = new FixtureSync($db, $fplClient);
    $count = $sync->sync();

    echo json_encode([
        'success' => true,
        'fixtures_synced' => $count,
    ]);
}

function handleManager(Database $db, FplClient $fplClient, int $id): void
{
    $service = new ManagerService($db, $fplClient);
    $manager = $service->getById($id);

    if ($manager === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Manager not found']);
        return;
    }

    echo json_encode($manager);
}

function handleManagerPicks(Database $db, FplClient $fplClient, int $managerId, int $gameweek): void
{
    $service = new ManagerService($db, $fplClient);
    $picks = $service->getPicks($managerId, $gameweek);

    if ($picks === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Picks not found']);
        return;
    }

    echo json_encode($picks);
}

function handleManagerHistory(Database $db, FplClient $fplClient, int $managerId): void
{
    $service = new ManagerService($db, $fplClient);
    $history = $service->getHistory($managerId);

    if ($history === null) {
        http_response_code(404);
        echo json_encode(['error' => 'History not found']);
        return;
    }

    echo json_encode($history);
}

function handleSyncManagers(Database $db, FplClient $fplClient): void
{
    $sync = new ManagerSync($db, $fplClient);
    $result = $sync->syncAll();

    echo json_encode([
        'success' => true,
        'synced' => $result['synced'],
        'failed' => $result['failed'],
    ]);
}

function handleNotFound(): void
{
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
