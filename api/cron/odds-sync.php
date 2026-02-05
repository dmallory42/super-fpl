#!/usr/bin/env php
<?php

/**
 * Sync odds data from The Odds API.
 * Run: php odds-sync.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Clients\OddsApiClient;
use SuperFPL\Api\Sync\OddsSync;

$config = require __DIR__ . '/../config/config.php';

$apiKey = $config['odds_api']['api_key'] ?? '';
if (empty($apiKey)) {
    echo "Error: ODDS_API_KEY not configured\n";
    exit(1);
}

$db = new Database($config['database']['path']);
$db->init();

$cacheDir = $config['cache']['path'] . '/odds';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$client = new OddsApiClient($apiKey, $cacheDir);
$sync = new OddsSync($db, $client);

echo "Syncing odds from The Odds API...\n";

$startTime = microtime(true);

// Sync match odds
$matchResult = $sync->syncMatchOdds();
echo "Match odds: {$matchResult['fixtures']} fixtures found, {$matchResult['matched']} matched\n";

// Sync goalscorer odds
$gsResult = $sync->syncAllGoalscorerOdds();
echo "Goalscorer odds: {$gsResult['fixtures']} fixtures, {$gsResult['players']} players\n";

// Show quota
$quota = $client->getQuota();
if ($quota) {
    echo "API quota: {$quota['requests_used']} used, {$quota['requests_remaining']} remaining\n";
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\nCompleted in {$elapsed}s\n";
