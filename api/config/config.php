<?php

declare(strict_types=1);

return [
    'database' => [
        'path' => __DIR__ . '/../data/superfpl.db',
    ],
    'cache' => [
        'path' => __DIR__ . '/../cache',
        'ttl' => 300, // 5 minutes
    ],
    'fpl' => [
        'rate_limit_dir' => '/tmp/fpl-rate-limit',
    ],
];
