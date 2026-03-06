<?php

declare(strict_types=1);

use Maia\Core\Http\Request;

try {
    $app = require __DIR__ . '/../bootstrap.php';
} catch (Throwable $exception) {
    $appEnv = strtolower((string) (getenv('SUPERFPL_APP_ENV') ?: 'development'));
    $debugEnv = getenv('SUPERFPL_DEBUG');
    $debug = $debugEnv === false
        ? $appEnv !== 'production'
        : in_array(strtolower((string) $debugEnv), ['1', 'true', 'yes', 'on'], true);

    http_response_code(500);
    header('Content-Type: application/json');

    $requestId = bin2hex(random_bytes(8));
    if ($debug) {
        echo json_encode([
            'error' => true,
            'message' => $exception->getMessage(),
            'request_id' => $requestId,
            'trace' => $exception->getTrace(),
        ]);
    } else {
        echo json_encode([
            'error' => 'Internal server error',
            'request_id' => $requestId,
        ]);
    }

    return;
}

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

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($requestPath) || $requestPath === '') {
    $requestPath = '/';
}

$headers = [];
if (function_exists('getallheaders')) {
    $rawHeaders = getallheaders();
    if (is_array($rawHeaders)) {
        $headers = $rawHeaders;
    }
}
if ($headers === []) {
    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with((string) $key, 'HTTP_')) {
            continue;
        }

        $name = str_replace('_', '-', strtolower(substr((string) $key, 5)));
        $name = implode('-', array_map('ucfirst', explode('-', $name)));
        $headers[$name] = is_string($value) ? $value : (string) $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE']) && !isset($headers['Content-Type'])) {
    $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
}

$bodyStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
$rawBody = file_get_contents($bodyStream);

$request = new Request(
    method: (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    path: $requestPath,
    query: $_GET,
    headers: $headers,
    body: $rawBody === false ? null : $rawBody,
    routeParams: []
);
$response = $app->handle($request);
$response->send();
