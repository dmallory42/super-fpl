<?php

declare(strict_types=1);

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
];
