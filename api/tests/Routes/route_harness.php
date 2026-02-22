<?php

declare(strict_types=1);

$method = getenv('REQ_METHOD') ?: 'GET';
$uri = getenv('REQ_URI') ?: '/api/health';

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;

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

    echo json_encode([
        'status' => $status,
        'body' => (string) $body,
    ], JSON_UNESCAPED_SLASHES);
});

require dirname(__DIR__, 2) . '/public/index.php';
