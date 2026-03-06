<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Maia\Core\App;
use Maia\Core\Config\Config;
use Maia\Orm\Connection;
use Maia\Orm\Model;
use SuperFPL\Api\Database;
use SuperFPL\FplClient\Cache\FileCache;
use SuperFPL\FplClient\FplClient;

$app = App::create(__DIR__ . '/config');

/** @var Config $maiaConfig */
$maiaConfig = $app->container()->resolve(Config::class);
$config = $maiaConfig->get('config');

if (!is_array($config)) {
    throw new RuntimeException('Application config could not be loaded from api/config/config.php.');
}

// Keep the existing schema bootstrap in place until the Database class is removed later in the migration.
$database = new Database($config['database']['path']);
$database->init();
$app->container()->instance(Database::class, $database);

$connection = new Connection('sqlite:' . $config['database']['path']);
$connection->execute('PRAGMA foreign_keys = ON');
$connection->execute('PRAGMA busy_timeout = 5000');

try {
    $connection->execute('PRAGMA journal_mode = WAL');
} catch (Throwable) {
    // Keep SQLite defaults when WAL is not available on the underlying filesystem.
}

$connection->execute('PRAGMA synchronous = NORMAL');

Model::setConnection($connection);
$app->container()->instance(Connection::class, $connection);

$cachePath = (string) ($config['cache']['path'] ?? (__DIR__ . '/cache'));
$fplCachePath = $cachePath . '/fpl';
if (!is_dir($fplCachePath)) {
    mkdir($fplCachePath, 0755, true);
}

$fplClient = new FplClient(
    cache: new FileCache($fplCachePath),
    cacheTtl: (int) ($config['cache']['ttl'] ?? 300),
    rateLimitDir: (string) ($config['fpl']['rate_limit_dir'] ?? '/tmp/fpl-rate-limit'),
    connectTimeout: (float) ($config['fpl']['connect_timeout'] ?? 8),
    requestTimeout: (float) ($config['fpl']['request_timeout'] ?? 15)
);

$app->container()->instance(FplClient::class, $fplClient);

return $app;
