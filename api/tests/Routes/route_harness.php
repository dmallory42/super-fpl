<?php

declare(strict_types=1);

$method = getenv('REQ_METHOD') ?: 'GET';
$uri = getenv('REQ_URI') ?: '/api/health';

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;

$origin = getenv('REQ_ORIGIN');
if (is_string($origin) && $origin !== '') {
    $_SERVER['HTTP_ORIGIN'] = $origin;
}

$authorization = getenv('REQ_AUTHORIZATION');
if (is_string($authorization) && $authorization !== '') {
    $_SERVER['HTTP_AUTHORIZATION'] = $authorization;
}

$adminToken = getenv('REQ_X_ADMIN_TOKEN');
if (is_string($adminToken) && $adminToken !== '') {
    $_SERVER['HTTP_X_ADMIN_TOKEN'] = $adminToken;
}

$_GET = [];
$query = parse_url($uri, PHP_URL_QUERY);
if (is_string($query) && $query !== '') {
    parse_str($query, $_GET);
}

ob_start();

register_shutdown_function(static function (): void {
    $body = ob_get_contents();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $status = http_response_code();
    if ($status === false || $status === 0) {
        $status = 200;
    }
    $headers = headers_list();

    echo json_encode([
        'status' => $status,
        'body' => (string) $body,
        'headers' => $headers,
    ], JSON_UNESCAPED_SLASHES);
});

require dirname(__DIR__, 2) . '/public/index.php';
