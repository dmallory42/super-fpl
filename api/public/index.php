<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Services\PlayerService;
use SuperFPL\Api\Services\FixtureService;
use SuperFPL\Api\Services\TeamService;
use SuperFPL\Api\Services\ManagerService;
use SuperFPL\Api\Services\PredictionService;
use SuperFPL\Api\Services\LeagueService;
use SuperFPL\Api\Services\ComparisonService;
use SuperFPL\Api\Services\LiveService;
use SuperFPL\Api\Services\SampleService;
use SuperFPL\Api\Services\TransferService;
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
        $uri === '/players/enhanced' => handlePlayersEnhanced($db),
        $uri === '/fixtures' => handleFixtures($db),
        $uri === '/gameweek/current' => handleCurrentGameweek($db),
        $uri === '/teams' => handleTeams($db),
        $uri === '/sync/players' => handleSyncPlayers($db, $fplClient),
        $uri === '/sync/fixtures' => handleSyncFixtures($db, $fplClient),
        preg_match('#^/players/(\d+)$#', $uri, $m) === 1 => handlePlayer($db, (int) $m[1]),
        preg_match('#^/managers/(\d+)$#', $uri, $m) === 1 => handleManager($db, $fplClient, (int) $m[1]),
        preg_match('#^/managers/(\d+)/picks/(\d+)$#', $uri, $m) === 1 => handleManagerPicks($db, $fplClient, (int) $m[1], (int) $m[2]),
        preg_match('#^/managers/(\d+)/history$#', $uri, $m) === 1 => handleManagerHistory($db, $fplClient, (int) $m[1]),
        $uri === '/sync/managers' => handleSyncManagers($db, $fplClient),
        preg_match('#^/predictions/(\d+)$#', $uri, $m) === 1 => handlePredictions($db, (int) $m[1]),
        preg_match('#^/predictions/(\d+)/player/(\d+)$#', $uri, $m) === 1 => handlePlayerPrediction($db, (int) $m[1], (int) $m[2]),
        $uri === '/predictions/methodology' => handlePredictionMethodology(),
        preg_match('#^/leagues/(\d+)$#', $uri, $m) === 1 => handleLeague($db, $fplClient, (int) $m[1]),
        preg_match('#^/leagues/(\d+)/standings$#', $uri, $m) === 1 => handleLeagueStandings($db, $fplClient, (int) $m[1]),
        preg_match('#^/leagues/(\d+)/analysis$#', $uri, $m) === 1 => handleLeagueAnalysis($db, $fplClient, (int) $m[1]),
        $uri === '/compare' => handleCompare($db, $fplClient),
        $uri === '/live/current' => handleLiveCurrentGameweek($db, $fplClient, $config),
        preg_match('#^/live/(\d+)$#', $uri, $m) === 1 => handleLive($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/live/(\d+)/manager/(\d+)$#', $uri, $m) === 1 => handleLiveManager($db, $fplClient, $config, (int) $m[1], (int) $m[2]),
        preg_match('#^/live/(\d+)/manager/(\d+)/enhanced$#', $uri, $m) === 1 => handleLiveManagerEnhanced($db, $fplClient, $config, (int) $m[1], (int) $m[2]),
        preg_match('#^/live/(\d+)/bonus$#', $uri, $m) === 1 => handleLiveBonus($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/live/(\d+)/samples$#', $uri, $m) === 1 => handleLiveSamples($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/admin/sample/(\d+)$#', $uri, $m) === 1 => handleAdminSample($db, $fplClient, $config, (int) $m[1]),
        $uri === '/fixtures/status' => handleFixturesStatus($db),
        preg_match('#^/ownership/(\d+)$#', $uri, $m) === 1 => handleOwnership($db, $fplClient, $config, (int) $m[1]),
        $uri === '/transfers/suggest' => handleTransferSuggest($db, $fplClient),
        $uri === '/transfers/simulate' => handleTransferSimulate($db, $fplClient),
        $uri === '/transfers/targets' => handleTransferTargets($db, $fplClient),
        $uri === '/planner/optimize' => handlePlannerOptimize($db, $fplClient),
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

function handlePlayersEnhanced(Database $db): void
{
    $service = new \SuperFPL\Api\Services\PlayerMetricsService($db);

    $filters = [];
    if (isset($_GET['position'])) {
        $filters['position'] = (int) $_GET['position'];
    }
    if (isset($_GET['team'])) {
        $filters['team'] = (int) $_GET['team'];
    }

    $players = $service->getAllWithMetrics($filters);
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

function handlePredictions(Database $db, int $gameweek): void
{
    $gwService = new \SuperFPL\Api\Services\GameweekService($db);
    $currentGw = $gwService->getCurrentGameweek();

    // Don't predict for past gameweeks
    if ($gameweek < $currentGw) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Cannot predict for past gameweeks',
            'requested_gameweek' => $gameweek,
            'current_gameweek' => $currentGw,
        ]);
        return;
    }

    $service = new PredictionService($db);
    $predictions = $service->getPredictions($gameweek);

    $response = [
        'gameweek' => $gameweek,
        'current_gameweek' => $currentGw,
        'predictions' => $predictions,
        'generated_at' => date('c'),
    ];

    // Include methodology if requested
    if (isset($_GET['include_methodology'])) {
        $response['methodology'] = PredictionService::getMethodology();
    }

    echo json_encode($response);
}

function handlePredictionMethodology(): void
{
    echo json_encode(PredictionService::getMethodology());
}

function handleCurrentGameweek(Database $db): void
{
    $service = new \SuperFPL\Api\Services\GameweekService($db);
    $current = $service->getCurrentGameweek();
    $upcoming = $service->getUpcomingGameweeks(6);
    $fixtureCounts = $service->getFixtureCounts($upcoming);

    // Find DGW and BGW teams
    $dgwTeams = [];
    $bgwTeams = [];
    foreach ($upcoming as $gw) {
        $dgw = $service->getDoubleGameweekTeams($gw);
        $bgw = $service->getBlankGameweekTeams($gw);
        if (!empty($dgw)) {
            $dgwTeams[$gw] = $dgw;
        }
        if (!empty($bgw)) {
            $bgwTeams[$gw] = $bgw;
        }
    }

    echo json_encode([
        'current_gameweek' => $current,
        'upcoming_gameweeks' => $upcoming,
        'fixture_counts' => $fixtureCounts,
        'double_gameweek_teams' => $dgwTeams,
        'blank_gameweek_teams' => $bgwTeams,
    ]);
}

function handlePlayerPrediction(Database $db, int $gameweek, int $playerId): void
{
    $service = new PredictionService($db);
    $prediction = $service->getPlayerPrediction($playerId, $gameweek);

    if ($prediction === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Player not found']);
        return;
    }

    echo json_encode($prediction);
}

function handleLeague(Database $db, FplClient $fplClient, int $leagueId): void
{
    $service = new LeagueService($db, $fplClient);
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $league = $service->getLeague($leagueId, $page);

    if ($league === null) {
        http_response_code(404);
        echo json_encode(['error' => 'League not found']);
        return;
    }

    echo json_encode($league);
}

function handleLeagueStandings(Database $db, FplClient $fplClient, int $leagueId): void
{
    $service = new LeagueService($db, $fplClient);
    $standings = $service->getAllStandings($leagueId);

    echo json_encode([
        'league_id' => $leagueId,
        'standings' => $standings,
    ]);
}

function handleLeagueAnalysis(Database $db, FplClient $fplClient, int $leagueId): void
{
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;

    // Get current gameweek if not specified
    if ($gameweek === null) {
        $gwService = new \SuperFPL\Api\Services\GameweekService($db);
        $gameweek = $gwService->getCurrentGameweek();
    }

    // Get league standings
    $leagueService = new LeagueService($db, $fplClient);
    $league = $leagueService->getLeague($leagueId);

    if ($league === null) {
        http_response_code(404);
        echo json_encode(['error' => 'League not found']);
        return;
    }

    // Get top 20 manager IDs from league
    $standings = $league['standings']['results'] ?? [];
    $managerIds = array_slice(array_column($standings, 'entry'), 0, 20);

    if (count($managerIds) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'League needs at least 2 managers']);
        return;
    }

    // Run comparison on league members
    $comparisonService = new ComparisonService($db, $fplClient);
    $comparison = $comparisonService->compare($managerIds, $gameweek);

    echo json_encode([
        'league' => [
            'id' => $leagueId,
            'name' => $league['league']['name'] ?? 'Unknown',
        ],
        'gameweek' => $gameweek,
        'managers' => array_map(function($s) {
            return [
                'id' => $s['entry'],
                'name' => $s['player_name'],
                'team_name' => $s['entry_name'],
                'rank' => $s['rank'],
                'total' => $s['total'],
            ];
        }, array_slice($standings, 0, 20)),
        'comparison' => $comparison,
    ]);
}

function handleCompare(Database $db, FplClient $fplClient): void
{
    // Parse manager IDs from query string (e.g., ?ids=123,456,789&gw=25)
    $idsParam = $_GET['ids'] ?? '';
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;

    if (empty($idsParam)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing ids parameter (e.g., ?ids=123,456,789)']);
        return;
    }

    $managerIds = array_map('intval', explode(',', $idsParam));
    $managerIds = array_filter($managerIds, fn($id) => $id > 0);

    if (count($managerIds) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Need at least 2 manager IDs to compare']);
        return;
    }

    // If no gameweek specified, try to detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new ComparisonService($db, $fplClient);
    $comparison = $service->compare($managerIds, $gameweek);

    echo json_encode($comparison);
}

function handleLive(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $data = $service->getLiveData($gameweek);

    echo json_encode($data);
}

function handleLiveManager(Database $db, FplClient $fplClient, array $config, int $gameweek, int $managerId): void
{
    $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $data = $service->getManagerLivePoints($managerId, $gameweek);

    echo json_encode($data);
}

function handleLiveBonus(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $predictions = $service->getBonusPredictions($gameweek);

    echo json_encode([
        'gameweek' => $gameweek,
        'bonus_predictions' => $predictions,
    ]);
}

function handleLiveCurrentGameweek(Database $db, FplClient $fplClient, array $config): void
{
    $gwService = new \SuperFPL\Api\Services\GameweekService($db);
    $currentGw = $gwService->getCurrentGameweek();

    $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $data = $service->getLiveData($currentGw);

    $data['current_gameweek'] = $currentGw;

    echo json_encode($data);
}

function handleLiveManagerEnhanced(Database $db, FplClient $fplClient, array $config, int $gameweek, int $managerId): void
{
    $liveService = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $ownershipService = new \SuperFPL\Api\Services\OwnershipService(
        $db,
        $fplClient,
        $config['cache']['path'] . '/ownership'
    );

    $data = $liveService->getManagerLivePointsEnhanced($managerId, $gameweek, $ownershipService);

    if (isset($data['error'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Manager or gameweek data not found']);
        return;
    }

    echo json_encode($data);
}

function handleLiveSamples(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    $liveService = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $sampleService = new SampleService($db, $fplClient, $config['cache']['path'] . '/samples');

    // Get live data for points
    $liveData = $liveService->getLiveData($gameweek);
    $elements = $liveData['elements'] ?? [];

    // Get sample data with calculated averages
    $data = $sampleService->getSampleData($gameweek, $elements);

    echo json_encode($data);
}

function handleAdminSample(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    // Simple sampling - just sample a small number for quick results
    $sampleService = new SampleService($db, $fplClient, $config['cache']['path'] . '/samples');

    // Check if we should do a full sync (will take a long time)
    $full = ($_GET['full'] ?? '') === '1';

    if ($full) {
        // Full sync - this will take several minutes due to API rate limits
        $results = $sampleService->sampleManagersForGameweek($gameweek);
    } else {
        // Quick sync - just verify samples exist
        $hasSamples = $sampleService->hasSamplesForGameweek($gameweek);
        $results = [
            'has_samples' => $hasSamples,
            'message' => $hasSamples
                ? 'Samples already exist for this gameweek'
                : 'No samples found. Use ?full=1 to run full sample sync (takes several minutes)',
        ];
    }

    echo json_encode([
        'gameweek' => $gameweek,
        'results' => $results,
    ]);
}

function handleFixturesStatus(Database $db): void
{
    // Get fixture status for current/active gameweek detection
    $fixtures = $db->fetchAll(
        'SELECT id, gameweek, kickoff_time, finished, home_score, away_score,
                home_club_id, away_club_id
         FROM fixtures
         WHERE kickoff_time IS NOT NULL
         ORDER BY kickoff_time ASC'
    );

    // Add started/finished status based on kickoff time
    // Use 120 minutes as a heuristic for match completion if not already marked finished
    $now = time();
    foreach ($fixtures as &$fixture) {
        $kickoff = strtotime($fixture['kickoff_time']);
        $fixture['started'] = $now >= $kickoff;

        // If kickoff was more than 120 minutes ago, consider it finished
        // This handles cases where the database sync hasn't run recently
        $minutesSinceKickoff = ($now - $kickoff) / 60;
        if (!$fixture['finished'] && $minutesSinceKickoff >= 120) {
            $fixture['finished'] = true;
        }

        $fixture['minutes'] = $fixture['started'] && !$fixture['finished']
            ? min(90, (int)$minutesSinceKickoff)
            : ($fixture['finished'] ? 90 : 0);
    }

    // Group by gameweek
    $byGameweek = [];
    foreach ($fixtures as $f) {
        $gw = $f['gameweek'];
        if (!isset($byGameweek[$gw])) {
            $byGameweek[$gw] = [
                'gameweek' => $gw,
                'fixtures' => [],
                'total' => 0,
                'started' => 0,
                'finished' => 0,
                'first_kickoff' => null,
                'last_kickoff' => null,
            ];
        }
        $byGameweek[$gw]['fixtures'][] = $f;
        $byGameweek[$gw]['total']++;
        if ($f['started']) $byGameweek[$gw]['started']++;
        if ($f['finished']) $byGameweek[$gw]['finished']++;

        if ($byGameweek[$gw]['first_kickoff'] === null || $f['kickoff_time'] < $byGameweek[$gw]['first_kickoff']) {
            $byGameweek[$gw]['first_kickoff'] = $f['kickoff_time'];
        }
        if ($byGameweek[$gw]['last_kickoff'] === null || $f['kickoff_time'] > $byGameweek[$gw]['last_kickoff']) {
            $byGameweek[$gw]['last_kickoff'] = $f['kickoff_time'];
        }
    }

    // Determine current/active gameweek
    // Active if: now >= first_kickoff - 90min AND now <= last_kickoff + 12hrs
    $activeGw = null;
    $latestFinished = null;

    foreach ($byGameweek as $gw => $data) {
        $firstKickoff = strtotime($data['first_kickoff']);
        $lastKickoff = strtotime($data['last_kickoff']);

        $gwStart = $firstKickoff - (90 * 60); // 90 min before first kickoff
        $gwEnd = $lastKickoff + (12 * 60 * 60); // 12 hours after last kickoff

        if ($now >= $gwStart && $now <= $gwEnd) {
            $activeGw = $gw;
            break;
        }

        if ($data['finished'] === $data['total'] && $data['total'] > 0) {
            $latestFinished = $gw;
        }
    }

    // If no active GW, use latest finished
    $currentGw = $activeGw ?? $latestFinished ?? 1;

    echo json_encode([
        'current_gameweek' => $currentGw,
        'is_live' => $activeGw !== null,
        'gameweeks' => array_values($byGameweek),
    ]);
}

function handleOwnership(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    $sampleSize = isset($_GET['sample']) ? (int) $_GET['sample'] : 100;
    $sampleSize = min(max($sampleSize, 50), 500); // Clamp between 50-500

    $service = new \SuperFPL\Api\Services\OwnershipService(
        $db,
        $fplClient,
        $config['cache']['path'] . '/ownership'
    );

    $data = $service->getEffectiveOwnership($gameweek, $sampleSize);

    echo json_encode($data);
}

function handleTransferSuggest(Database $db, FplClient $fplClient): void
{
    $managerId = isset($_GET['manager']) ? (int) $_GET['manager'] : null;
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    $transfers = isset($_GET['transfers']) ? (int) $_GET['transfers'] : 1;

    if ($managerId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing manager parameter']);
        return;
    }

    // If no gameweek specified, detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new TransferService($db, $fplClient);
    $suggestions = $service->getSuggestions($managerId, $gameweek, $transfers);

    echo json_encode($suggestions);
}

function handleTransferSimulate(Database $db, FplClient $fplClient): void
{
    $managerId = isset($_GET['manager']) ? (int) $_GET['manager'] : null;
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    $outPlayerId = isset($_GET['out']) ? (int) $_GET['out'] : null;
    $inPlayerId = isset($_GET['in']) ? (int) $_GET['in'] : null;

    if ($managerId === null || $outPlayerId === null || $inPlayerId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing parameters: manager, out, in required']);
        return;
    }

    // If no gameweek specified, detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new TransferService($db, $fplClient);
    $simulation = $service->simulateTransfer($managerId, $gameweek, $outPlayerId, $inPlayerId);

    echo json_encode($simulation);
}

function handleTransferTargets(Database $db, FplClient $fplClient): void
{
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    $position = isset($_GET['position']) ? (int) $_GET['position'] : null;
    $maxPrice = isset($_GET['max_price']) ? (float) $_GET['max_price'] : null;

    // If no gameweek specified, detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new TransferService($db, $fplClient);
    $targets = $service->getTopTargets($gameweek, $position, $maxPrice !== null ? $maxPrice * 10 : null);

    echo json_encode([
        'gameweek' => $gameweek,
        'targets' => $targets,
    ]);
}

function handlePlannerOptimize(Database $db, FplClient $fplClient): void
{
    $managerId = isset($_GET['manager']) ? (int) $_GET['manager'] : null;
    $freeTransfers = isset($_GET['ft']) ? (int) $_GET['ft'] : 1;

    // Parse chip plan from query params
    $chipPlan = [];
    if (isset($_GET['wildcard_gw'])) {
        $chipPlan['wildcard'] = (int) $_GET['wildcard_gw'];
    }
    if (isset($_GET['bench_boost_gw'])) {
        $chipPlan['bench_boost'] = (int) $_GET['bench_boost_gw'];
    }
    if (isset($_GET['free_hit_gw'])) {
        $chipPlan['free_hit'] = (int) $_GET['free_hit_gw'];
    }
    if (isset($_GET['triple_captain_gw'])) {
        $chipPlan['triple_captain'] = (int) $_GET['triple_captain_gw'];
    }

    if ($managerId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing manager parameter']);
        return;
    }

    $predictionService = new PredictionService($db);
    $gameweekService = new \SuperFPL\Api\Services\GameweekService($db);

    $optimizer = new \SuperFPL\Api\Services\TransferOptimizerService(
        $db,
        $fplClient,
        $predictionService,
        $gameweekService
    );

    try {
        $plan = $optimizer->getOptimalPlan($managerId, $chipPlan, $freeTransfers);
        echo json_encode($plan);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
        ]);
    }
}

function handleNotFound(): void
{
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
