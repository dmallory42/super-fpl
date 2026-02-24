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
    $corsAllowedOrigins = ['*'];
}

return [
    'database' => [
        'path' => getenv('SUPERFPL_DB_PATH') ?: (__DIR__ . '/../data/superfpl.db'),
    ],
    'cache' => [
        'path' => getenv('SUPERFPL_CACHE_PATH') ?: (__DIR__ . '/../cache'),
        'ttl' => 300, // 5 minutes
    ],
    'fpl' => [
        'rate_limit_dir' => getenv('SUPERFPL_RATE_LIMIT_DIR') ?: '/tmp/fpl-rate-limit',
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
