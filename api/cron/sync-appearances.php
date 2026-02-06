<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Sync\PlayerSync;
use SuperFPL\FplClient\FplClient;

$db = new Database(__DIR__ . '/../data/superfpl.db');
$fplClient = new FplClient();
$playerSync = new PlayerSync($db, $fplClient);

echo "Syncing player appearances...\n";
$start = microtime(true);

$count = $playerSync->syncAppearances();

$elapsed = round(microtime(true) - $start, 2);
echo "Updated {$count} players with appearances in {$elapsed}s\n";
