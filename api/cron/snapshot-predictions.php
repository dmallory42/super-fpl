#!/usr/bin/env php
<?php

/**
 * Snapshot predictions for a gameweek.
 * Usage: php snapshot-predictions.php [gw]
 *
 * If no gameweek specified, snapshots the previous gameweek.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Services\PredictionService;
use SuperFPL\Api\Services\GameweekService;

$gwService = new GameweekService($db);
$currentGw = $gwService->getCurrentGameweek();

$gameweek = isset($argv[1]) ? (int) $argv[1] : $currentGw - 1;

if ($gameweek < 1 || $gameweek > 38) {
    echo "Invalid gameweek: {$gameweek}\n";
    exit(1);
}

echo "Snapshotting predictions for GW{$gameweek}...\n";

$service = new PredictionService($db);
$count = $service->snapshotPredictions($gameweek);

echo "Snapshotted {$count} predictions for GW{$gameweek}\n";
