#!/usr/bin/env php
<?php

/**
 * Sync odds data from Oddschecker.
 * Run: php odds-sync.php [gameweek]
 *
 * If no gameweek specified, syncs for the next upcoming gameweek.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Clients\OddscheckerScraper;
use SuperFPL\Api\Sync\OddsSync;

$config = require __DIR__ . '/../config/config.php';

// Initialize database
$db = new Database($config['database']['path']);
$db->init();

// Initialize scraper with cache
$cacheDir = $config['cache']['path'] . '/oddschecker';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$scraper = new OddscheckerScraper($cacheDir);

$sync = new OddsSync($db, $scraper);

echo "Syncing match odds from Oddschecker...\n";

$startTime = microtime(true);

// Sync match odds
$result = $sync->syncMatchOdds();
echo "Fetched odds for {$result['fixtures']} fixtures, matched {$result['matched']} to database\n";

// Optionally sync goalscorer odds for a specific gameweek
if (isset($argv[1])) {
    $gameweek = (int) $argv[1];
    echo "\nSyncing goalscorer odds for GW{$gameweek}...\n";

    $gsResult = $sync->syncGoalscorerOddsForGameweek($gameweek);
    echo "Processed {$gsResult['fixtures']} fixtures, {$gsResult['players']} player odds\n";
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\nCompleted in {$elapsed}s\n";
