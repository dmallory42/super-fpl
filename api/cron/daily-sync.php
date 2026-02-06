<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SuperFPL\Api\Sync\PlayerSync;
use SuperFPL\Api\Sync\FixtureSync;

echo "Starting daily FPL sync...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Run player sync
echo "Syncing players and teams...\n";
$playerSync = new PlayerSync($db, $fplClient);
$playerResult = $playerSync->sync();
echo "  - Teams synced: {$playerResult['teams']}\n";
echo "  - Players synced: {$playerResult['players']}\n";

// Run fixture sync
echo "Syncing fixtures...\n";
$fixtureSync = new FixtureSync($db, $fplClient);
$fixtureCount = $fixtureSync->sync();
echo "  - Fixtures synced: {$fixtureCount}\n";

echo "\nSync completed successfully!\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
