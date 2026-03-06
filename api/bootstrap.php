<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Maia\Auth\CorsMiddleware;
use Maia\Auth\SecurityHeadersMiddleware;
use Maia\Core\App;
use Maia\Core\Config\Config;
use Maia\Orm\Connection;
use Maia\Orm\Model;
use SuperFPL\Api\Controllers\AdminController;
use SuperFPL\Api\Controllers\FixtureController;
use SuperFPL\Api\Controllers\HealthController;
use SuperFPL\Api\Controllers\LeagueController;
use SuperFPL\Api\Controllers\LiveController;
use SuperFPL\Api\Controllers\ManagerController;
use SuperFPL\Api\Controllers\PlayerController;
use SuperFPL\Api\Controllers\PredictionController;
use SuperFPL\Api\Controllers\TransferController;
use SuperFPL\Api\SchemaMigrator;
use SuperFPL\FplClient\Cache\FileCache;
use SuperFPL\FplClient\FplClient;

$app = App::create(__DIR__ . '/config');

/** @var Config $maiaConfig */
$maiaConfig = $app->container()->resolve(Config::class);
$config = $maiaConfig->get('config');

if (!is_array($config)) {
    throw new RuntimeException('Application config could not be loaded from api/config/config.php.');
}

$connection = SchemaMigrator::createConnection(
    $config['database']['path'],
    __DIR__ . '/data/schema.sql',
    __DIR__ . '/data/migrations/add-performance-indexes.sql'
);

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

$app->addMiddleware(new CorsMiddleware((array) ($config['security']['cors_allowed_origins'] ?? [])));
$app->addMiddleware(new SecurityHeadersMiddleware());

$app->registerController(HealthController::class);
$app->registerController(PlayerController::class);
$app->registerController(FixtureController::class);
$app->registerController(ManagerController::class);
$app->registerController(LeagueController::class);
$app->registerController(PredictionController::class);
$app->registerController(LiveController::class);
$app->registerController(TransferController::class);
$app->registerController(AdminController::class);

return $app;
