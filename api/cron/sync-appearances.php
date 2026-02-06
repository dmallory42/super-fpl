<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Sync\PlayerSync;

$playerSync = new PlayerSync($db, $fplClient);

echo "Syncing player appearances...\n";
$start = microtime(true);

$count = $playerSync->syncAppearances();

$elapsed = round(microtime(true) - $start, 2);
echo "Updated {$count} players with appearances in {$elapsed}s\n";
