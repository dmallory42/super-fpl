<?php

declare(strict_types=1);

$rootAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($rootAutoload)) {
    require_once $rootAutoload;
}

$apiAutoload = __DIR__ . '/api/vendor/autoload.php';
if (file_exists($apiAutoload)) {
    require_once $apiAutoload;
}
