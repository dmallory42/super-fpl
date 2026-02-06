<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Sync\PlayerSync;

echo "Starting season history sync...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Run season history sync
echo "Syncing past season history (this may take a while)...\n";
$playerSync = new PlayerSync($db, $fplClient);
$count = $playerSync->syncSeasonHistory();
echo "  - Season history records synced: {$count}\n";

echo "\nSync completed successfully!\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
