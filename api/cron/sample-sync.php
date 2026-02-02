<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Services\SampleService;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\Cache\FileCache;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Get gameweek from argument or auto-detect
$gameweek = isset($argv[1]) ? (int) $argv[1] : null;

echo "Starting sample sync...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

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

// Auto-detect gameweek if not provided
if ($gameweek === null) {
    $gwService = new GameweekService($db);
    $gameweek = $gwService->getCurrentGameweek();
}

echo "Sampling managers for GW{$gameweek}...\n\n";

// Run sample sync
$sampleService = new SampleService($db, $fplClient, $cacheDir . '/samples');
$results = $sampleService->sampleManagersForGameweek($gameweek);

echo "Results:\n";
foreach ($results as $tier => $count) {
    echo "  - {$tier}: {$count} managers sampled\n";
}

echo "\nSample sync completed!\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
