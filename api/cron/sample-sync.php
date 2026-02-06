<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Services\SampleService;
use SuperFPL\Api\Services\GameweekService;

// Get gameweek from argument or auto-detect
$gameweek = isset($argv[1]) ? (int) $argv[1] : null;

echo "Starting sample sync...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

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
