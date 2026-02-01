#!/usr/bin/env php
<?php

/**
 * Generate predictions for the upcoming gameweek.
 * Run: php predictions.php [gameweek]
 *
 * If no gameweek specified, attempts to detect the next gameweek.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Services\PredictionService;

$config = require __DIR__ . '/../config/config.php';

// Initialize database
$db = new Database($config['database']['path']);
$db->init();

// Determine gameweek
$gameweek = isset($argv[1]) ? (int) $argv[1] : detectNextGameweek($db);

if ($gameweek < 1 || $gameweek > 38) {
    echo "Invalid gameweek: {$gameweek}\n";
    exit(1);
}

echo "Generating predictions for GW{$gameweek}...\n";

$startTime = microtime(true);

$service = new PredictionService($db);
$predictions = $service->generatePredictions($gameweek);

$elapsed = round(microtime(true) - $startTime, 2);

echo "Generated predictions for " . count($predictions) . " players in {$elapsed}s\n";

// Show top 10 predicted players
echo "\nTop 10 Predicted Players:\n";
echo str_repeat('-', 50) . "\n";

$top10 = array_slice($predictions, 0, 10);
foreach ($top10 as $i => $pred) {
    printf(
        "%2d. %-20s %5.2f pts (confidence: %.0f%%)\n",
        $i + 1,
        $pred['web_name'],
        $pred['predicted_points'],
        $pred['confidence'] * 100
    );
}

/**
 * Detect the next gameweek from fixtures.
 */
function detectNextGameweek(Database $db): int
{
    $fixture = $db->fetchOne(
        "SELECT gameweek FROM fixtures
        WHERE finished = 0
        ORDER BY kickoff_time ASC
        LIMIT 1"
    );

    return $fixture ? (int) $fixture['gameweek'] : 1;
}
