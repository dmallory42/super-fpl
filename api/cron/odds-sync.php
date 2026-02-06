#!/usr/bin/env php
<?php

/**
 * Sync odds data from The Odds API.
 * Run: php odds-sync.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Clients\OddsApiClient;
use SuperFPL\Api\Sync\OddsSync;

$apiKey = $config['odds_api']['api_key'] ?? '';
if (empty($apiKey)) {
    echo "Error: ODDS_API_KEY not configured\n";
    exit(1);
}

$oddsCacheDir = $cacheDir . '/odds';
if (!is_dir($oddsCacheDir)) {
    mkdir($oddsCacheDir, 0755, true);
}

$client = new OddsApiClient($apiKey, $oddsCacheDir);
$sync = new OddsSync($db, $client);

echo "Syncing odds from The Odds API...\n";

$startTime = microtime(true);

// Sync match odds
$matchResult = $sync->syncMatchOdds();
echo "Match odds: {$matchResult['fixtures']} fixtures found, {$matchResult['matched']} matched\n";

// Sync goalscorer odds
$gsResult = $sync->syncAllGoalscorerOdds();
echo "Goalscorer odds: {$gsResult['fixtures']} fixtures, {$gsResult['players']} players\n";

// Sync assist odds
$assistResult = $sync->syncAllAssistOdds();
echo "Assist odds: {$assistResult['fixtures']} fixtures, {$assistResult['players']} players\n";

// Show quota
$quota = $client->getQuota();
if ($quota) {
    echo "API quota: {$quota['requests_used']} used, {$quota['requests_remaining']} remaining\n";
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\nCompleted in {$elapsed}s\n";
