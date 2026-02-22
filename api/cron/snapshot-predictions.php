#!/usr/bin/env php
<?php

/**
 * Snapshot predictions for a gameweek.
 * Usage: php snapshot-predictions.php [gw] [--pre-deadline]
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
$isPreDeadline = in_array('--pre-deadline', $argv, true);
$source = $isPreDeadline ? 'manual_pre_deadline' : 'manual';
$count = $service->snapshotPredictions($gameweek, $isPreDeadline, $source);

echo "Snapshotted {$count} predictions for GW{$gameweek}\n";
