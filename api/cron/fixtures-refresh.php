#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Sync\FixtureSync;

echo "Starting fixture refresh...\n";
echo "Time (UTC): " . gmdate('Y-m-d H:i:s') . "\n";

$start = microtime(true);

$sync = new FixtureSync($db, $fplClient);
$count = $sync->sync();

$elapsed = round(microtime(true) - $start, 2);
echo "Fixtures synced: {$count}\n";
echo "Done in {$elapsed}s\n";
