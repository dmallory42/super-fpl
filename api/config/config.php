<?php

declare(strict_types=1);

$appEnv = strtolower((string) (getenv('SUPERFPL_APP_ENV') ?: 'development'));
$debugEnv = getenv('SUPERFPL_DEBUG');
$debug = $debugEnv === false
    ? $appEnv !== 'production'
    : in_array(strtolower((string) $debugEnv), ['1', 'true', 'yes', 'on'], true);

$corsOriginsEnv = trim((string) (getenv('SUPERFPL_CORS_ALLOWED_ORIGINS') ?: ''));
$corsAllowedOrigins = [];
if ($corsOriginsEnv !== '') {
    $corsAllowedOrigins = array_values(array_filter(array_map(
        static fn(string $origin): string => trim($origin),
        explode(',', $corsOriginsEnv)
    )));
} elseif ($appEnv !== 'production') {
    $corsAllowedOrigins = ['http://localhost:5173'];
}

$fplConnectTimeout = (float) (getenv('SUPERFPL_FPL_CONNECT_TIMEOUT') ?: 8);
if ($fplConnectTimeout <= 0) {
    $fplConnectTimeout = 8;
}

$fplRequestTimeout = (float) (getenv('SUPERFPL_FPL_REQUEST_TIMEOUT') ?: 15);
if ($fplRequestTimeout <= 0) {
    $fplRequestTimeout = 15;
}

return [
    'database' => [
        'path' => getenv('SUPERFPL_DB_PATH') ?: (__DIR__ . '/../data/superfpl.db'),
    ],
    'cache' => [
        'path' => getenv('SUPERFPL_CACHE_PATH') ?: (__DIR__ . '/../cache'),
        'ttl' => 300, // 5 minutes
    ],
    'redis' => [
        'url' => getenv('REDIS_URL') ?: 'redis://redis:6379',
    ],
    'fpl' => [
        'rate_limit_dir' => getenv('SUPERFPL_RATE_LIMIT_DIR') ?: '/tmp/fpl-rate-limit',
        'connect_timeout' => $fplConnectTimeout,
        'request_timeout' => $fplRequestTimeout,
    ],
    'logs' => [
        'error_log' => __DIR__ . '/../cache/logs/api-error.log',
    ],
    'odds_api' => [
        'api_key' => getenv('ODDS_API_KEY') ?: '',
        'enabled' => (bool) getenv('ODDS_API_KEY'),
    ],
    'app' => [
        'env' => $appEnv,
        'debug' => $debug,
    ],
    'security' => [
        'cors_allowed_origins' => $corsAllowedOrigins,
        'admin_token' => trim((string) (getenv('SUPERFPL_ADMIN_TOKEN') ?: '')),
    ],
];
