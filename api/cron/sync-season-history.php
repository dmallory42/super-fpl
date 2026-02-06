<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Sync\PlayerSync;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\Cache\FileCache;

// Load config
$config = require __DIR__ . '/../config/config.php';

echo "Starting season history sync...\n";
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

// Run season history sync
echo "Syncing past season history (this may take a while)...\n";
$playerSync = new PlayerSync($db, $fplClient);
$count = $playerSync->syncSeasonHistory();
echo "  - Season history records synced: {$count}\n";

echo "\nSync completed successfully!\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
