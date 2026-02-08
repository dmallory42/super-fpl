#!/usr/bin/env php
<?php

/**
 * Unified sync orchestrator.
 *
 * Usage: php sync-all.php --phase=<name>
 *
 * Phases:
 *   pre-deadline  — fixtures, players, odds, snapshot prev GW, predictions (current + next 5), managers
 *   post-gameweek — fixtures, players, managers, snapshot current GW
 *   slow          — appearances (~600 API calls), season history (~700 API calls)
 *   samples       — sample manager picks for current GW
 *   all           — pre-deadline + slow + samples
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Sync\PlayerSync;
use SuperFPL\Api\Sync\FixtureSync;
use SuperFPL\Api\Sync\ManagerSync;
use SuperFPL\Api\Sync\OddsSync;
use SuperFPL\Api\Sync\UnderstatSync;
use SuperFPL\Api\Clients\OddsApiClient;
use SuperFPL\Api\Clients\UnderstatClient;
use SuperFPL\Api\Services\PredictionService;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\SampleService;

// Parse --phase argument
$phase = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--phase=')) {
        $phase = substr($arg, 8);
    }
}

$validPhases = ['pre-deadline', 'post-gameweek', 'slow', 'samples', 'all'];
if ($phase === null || !in_array($phase, $validPhases, true)) {
    echo "Usage: php sync-all.php --phase=<name>\n";
    echo "Phases: " . implode(', ', $validPhases) . "\n";
    exit(1);
}

echo "=== Sync phase: {$phase} ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$failed = false;

/**
 * Run a named task with error handling.
 */
function runTask(string $name, callable $fn): void
{
    global $failed;
    echo "[{$name}] Starting...\n";
    $start = microtime(true);
    try {
        $fn();
        $elapsed = round(microtime(true) - $start, 2);
        echo "[{$name}] Done ({$elapsed}s)\n\n";
    } catch (Throwable $e) {
        $elapsed = round(microtime(true) - $start, 2);
        echo "[{$name}] FAILED ({$elapsed}s): {$e->getMessage()}\n";
        echo "  " . $e->getFile() . ":" . $e->getLine() . "\n\n";
        $failed = true;
    }
}

// Build task lists per phase
$tasks = [];

$syncFixtures = function () use ($db, $fplClient) {
    $sync = new FixtureSync($db, $fplClient);
    $count = $sync->sync();
    echo "  Fixtures synced: {$count}\n";
};

$syncPlayers = function () use ($db, $fplClient) {
    $sync = new PlayerSync($db, $fplClient);
    $result = $sync->sync();
    echo "  Teams: {$result['teams']}, Players: {$result['players']}\n";
};

$syncOdds = function () use ($db, $config, $cacheDir) {
    $apiKey = $config['odds_api']['api_key'] ?? '';
    if (empty($apiKey)) {
        echo "  Skipped (ODDS_API_KEY not set)\n";
        return;
    }
    $oddsCacheDir = $cacheDir . '/odds';
    if (!is_dir($oddsCacheDir)) {
        mkdir($oddsCacheDir, 0755, true);
    }
    $client = new OddsApiClient($apiKey, $oddsCacheDir);
    $sync = new OddsSync($db, $client);

    $matchResult = $sync->syncMatchOdds();
    echo "  Match odds: {$matchResult['fixtures']} fixtures, {$matchResult['matched']} matched\n";

    $gsResult = $sync->syncAllGoalscorerOdds();
    echo "  Goalscorer odds: {$gsResult['fixtures']} fixtures, {$gsResult['players']} players\n";

    $assistResult = $sync->syncAllAssistOdds();
    echo "  Assist odds: {$assistResult['fixtures']} fixtures, {$assistResult['players']} players\n";

    $quota = $client->getQuota();
    if ($quota) {
        echo "  API quota: {$quota['requests_used']} used, {$quota['requests_remaining']} remaining\n";
    }
};

$syncUnderstat = function () use ($db, $cacheDir) {
    $understatCacheDir = $cacheDir . '/understat';
    if (!is_dir($understatCacheDir)) {
        mkdir($understatCacheDir, 0755, true);
    }
    // Current season: year of August start (2025 for 2025-26 season)
    $season = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;
    $client = new UnderstatClient($understatCacheDir);
    $sync = new UnderstatSync($db, $client);
    $result = $sync->sync($season);
    echo "  Matched: {$result['matched']}/{$result['total']}, Unmatched: {$result['unmatched']}\n";
    if (!empty($result['unmatched_players'])) {
        echo "  Unmatched: " . implode(', ', array_slice($result['unmatched_players'], 0, 10)) . "\n";
    }
};

$syncManagers = function () use ($db, $fplClient) {
    $sync = new ManagerSync($db, $fplClient);
    $result = $sync->syncAll();
    echo "  Synced: {$result['synced']}, Failed: {$result['failed']}\n";
};

$snapshotPrevGw = function () use ($db) {
    $gwService = new GameweekService($db);
    $currentGw = $gwService->getCurrentGameweek();
    if ($currentGw <= 1) {
        echo "  Skipped (GW1, no previous GW)\n";
        return;
    }
    $prevGw = $currentGw - 1;
    $existing = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM prediction_snapshots WHERE gameweek = ?",
        [$prevGw]
    );
    if ((int) ($existing['cnt'] ?? 0) > 0) {
        echo "  Skipped (GW{$prevGw} already snapshotted)\n";
        return;
    }
    $service = new PredictionService($db);
    $count = $service->snapshotPredictions($prevGw);
    echo "  Snapshotted GW{$prevGw}: {$count} predictions\n";
};

$snapshotCurrentGw = function () use ($db) {
    $gwService = new GameweekService($db);
    $currentGw = $gwService->getCurrentGameweek();
    $service = new PredictionService($db);
    $count = $service->snapshotPredictions($currentGw);
    echo "  Snapshotted GW{$currentGw}: {$count} predictions\n";
};

$generatePredictions = function () use ($db) {
    $gwService = new GameweekService($db);
    $currentGw = $gwService->getCurrentGameweek();
    $service = new PredictionService($db);
    $endGw = min($currentGw + 5, 38);
    for ($gw = $currentGw; $gw <= $endGw; $gw++) {
        $predictions = $service->generatePredictions($gw);
        echo "  GW{$gw}: " . count($predictions) . " predictions\n";
    }
};

$syncAppearances = function () use ($db, $fplClient) {
    $sync = new PlayerSync($db, $fplClient);
    $count = $sync->syncAppearances();
    echo "  Players updated: {$count}\n";
};

$syncSeasonHistory = function () use ($db, $fplClient) {
    $sync = new PlayerSync($db, $fplClient);
    $count = $sync->syncSeasonHistory();
    echo "  Records synced: {$count}\n";
};

$syncUnderstatHistory = function () use ($db, $cacheDir) {
    $understatCacheDir = $cacheDir . '/understat';
    if (!is_dir($understatCacheDir)) {
        mkdir($understatCacheDir, 0755, true);
    }
    $season = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;
    $client = new UnderstatClient($understatCacheDir);
    $sync = new UnderstatSync($db, $client);
    $result = $sync->syncHistory($season);
    echo "  Seasons: " . implode(', ', $result['seasons']) . "\n";
    echo "  Player records: {$result['player_records']}, Team records: {$result['team_records']}\n";
};

$syncSamples = function () use ($db, $fplClient, $cacheDir) {
    $gwService = new GameweekService($db);
    $currentGw = $gwService->getCurrentGameweek();
    $sampleService = new SampleService($db, $fplClient, $cacheDir . '/samples');
    $results = $sampleService->sampleManagersForGameweek($currentGw);
    foreach ($results as $tier => $count) {
        echo "  {$tier}: {$count} managers\n";
    }
};

switch ($phase) {
    case 'pre-deadline':
        $tasks = [
            'fixtures'    => $syncFixtures,
            'players'     => $syncPlayers,
            'appearances' => $syncAppearances,
            'odds'        => $syncOdds,
            'understat'   => $syncUnderstat,
            'snapshot'    => $snapshotPrevGw,
            'predictions' => $generatePredictions,
            'managers'    => $syncManagers,
        ];
        break;

    case 'post-gameweek':
        $tasks = [
            'fixtures' => $syncFixtures,
            'players'  => $syncPlayers,
            'managers' => $syncManagers,
            'snapshot' => $snapshotCurrentGw,
        ];
        break;

    case 'slow':
        $tasks = [
            'appearances'       => $syncAppearances,
            'season-history'    => $syncSeasonHistory,
            'understat-history' => $syncUnderstatHistory,
        ];
        break;

    case 'samples':
        $tasks = [
            'samples' => $syncSamples,
        ];
        break;

    case 'all':
        $tasks = [
            'fixtures'          => $syncFixtures,
            'players'           => $syncPlayers,
            'odds'              => $syncOdds,
            'understat'         => $syncUnderstat,
            'snapshot'          => $snapshotPrevGw,
            'predictions'       => $generatePredictions,
            'managers'          => $syncManagers,
            'appearances'       => $syncAppearances,
            'season-history'    => $syncSeasonHistory,
            'understat-history' => $syncUnderstatHistory,
            'samples'           => $syncSamples,
        ];
        break;
}

foreach ($tasks as $name => $fn) {
    runTask($name, $fn);
}

echo "=== Finished: " . date('Y-m-d H:i:s') . " ===\n";

if ($failed) {
    echo "WARNING: One or more tasks failed.\n";
    exit(1);
}

// Write sync version so the frontend can detect fresh data
file_put_contents($cacheDir . '/sync_version.txt', (string) time());
