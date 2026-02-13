#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Sync\FixtureSync;

/**
 * Run fixture sync once per GW in the 1h-to-2h window after deadline.
 *
 * Intended to be invoked hourly from cron.
 */

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$bootstrap = $fplClient->bootstrap()->get();
$events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];

$latestPastEvent = null;
$latestPastDeadline = null;

foreach ($events as $event) {
    $deadlineRaw = $event['deadline_time'] ?? null;
    if (!is_string($deadlineRaw) || $deadlineRaw === '') {
        continue;
    }
    try {
        $deadline = new DateTimeImmutable($deadlineRaw, new DateTimeZone('UTC'));
    } catch (Throwable) {
        continue;
    }
    if ($deadline > $now) {
        continue;
    }
    if ($latestPastDeadline === null || $deadline > $latestPastDeadline) {
        $latestPastDeadline = $deadline;
        $latestPastEvent = $event;
    }
}

if ($latestPastEvent === null || $latestPastDeadline === null) {
    echo "No past GW deadline found. Skipping.\n";
    exit(0);
}

$gw = (int) ($latestPastEvent['id'] ?? 0);
if ($gw < 1) {
    echo "Invalid GW id from bootstrap. Skipping.\n";
    exit(0);
}

$windowStart = $latestPastDeadline->modify('+1 hour');
$windowEnd = $latestPastDeadline->modify('+2 hours');

if ($now < $windowStart || $now > $windowEnd) {
    echo sprintf(
        "Outside post-deadline window for GW%d (window %s to %s UTC, now %s UTC). Skipping.\n",
        $gw,
        $windowStart->format('Y-m-d H:i:s'),
        $windowEnd->format('Y-m-d H:i:s'),
        $now->format('Y-m-d H:i:s')
    );
    exit(0);
}

$cachePath = $config['cache']['path'] ?? (__DIR__ . '/../cache');
if (!is_dir($cachePath)) {
    mkdir($cachePath, 0755, true);
}
$stateFile = $cachePath . '/fixtures_post_deadline_refresh.json';

$state = [];
if (file_exists($stateFile)) {
    $decoded = json_decode((string) file_get_contents($stateFile), true);
    if (is_array($decoded)) {
        $state = $decoded;
    }
}

if (isset($state[(string) $gw])) {
    echo "GW{$gw} post-deadline fixture refresh already completed at {$state[(string) $gw]}. Skipping.\n";
    exit(0);
}

echo "Running post-deadline fixture refresh for GW{$gw}...\n";
$start = microtime(true);

$sync = new FixtureSync($db, $fplClient);
$count = $sync->sync();

$state[(string) $gw] = $now->format(DateTimeInterface::ATOM);
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$elapsed = round(microtime(true) - $start, 2);
echo "Fixtures synced: {$count}\n";
echo "Done in {$elapsed}s\n";
