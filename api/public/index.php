<?php

declare(strict_types=1);

use Maia\Core\Http\Request;

$app = require __DIR__ . '/../bootstrap.php';

// Keep local dev and route harness compatibility where requests include /api prefix.
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($requestUri, PHP_URL_PATH);
if (is_string($path) && ($path === '/api' || str_starts_with($path, '/api/'))) {
    $stripped = substr($requestUri, 4);
    if ($stripped === '') {
        $stripped = '/';
    } elseif ($stripped[0] !== '/') {
        $stripped = '/' . $stripped;
    }

    $_SERVER['REQUEST_URI'] = $stripped;
}

$request = Request::capture();
$response = $app->handle($request);
$response->send();
