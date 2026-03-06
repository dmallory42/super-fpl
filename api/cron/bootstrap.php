<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Maia\Orm\Connection;
use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\Cache\FileCache;

$config = require __DIR__ . '/../config/config.php';

$db = new Database($config['database']['path']);
$db->init();

$connection = new Connection('sqlite:' . $config['database']['path']);
$connection->execute('PRAGMA foreign_keys = ON');
$connection->execute('PRAGMA busy_timeout = 5000');

$cacheDir = $config['cache']['path'];
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cache = new FileCache($cacheDir);
$fplClient = new FplClient(
    cache: $cache,
    cacheTtl: $config['cache']['ttl'],
    rateLimitDir: $config['fpl']['rate_limit_dir']
);
