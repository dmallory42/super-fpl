<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SuperFPL\Api\Database;
use SuperFPL\Api\Services\PlayerService;
use SuperFPL\Api\Services\FixtureService;
use SuperFPL\Api\Services\TeamService;
use SuperFPL\Api\Services\ManagerService;
use SuperFPL\Api\Services\ManagerSeasonAnalysisService;
use SuperFPL\Api\Services\PredictionService;
use SuperFPL\Api\Services\LeagueService;
use SuperFPL\Api\Services\LeagueSeasonAnalysisService;
use SuperFPL\Api\Services\ComparisonService;
use SuperFPL\Api\Services\LiveService;
use SuperFPL\Api\Services\SampleService;
use SuperFPL\Api\Services\TransferService;
use SuperFPL\Api\Prediction\PredictionScaler;
use SuperFPL\Api\Sync\PlayerSync;
use SuperFPL\Api\Sync\FixtureSync;
use SuperFPL\Api\Sync\ManagerSync;
use SuperFPL\Api\Sync\OddsSync;
use SuperFPL\Api\Sync\UnderstatSync;
use SuperFPL\Api\Clients\OddsApiClient;
use SuperFPL\Api\Clients\UnderstatClient;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\Cache\FileCache;
use Predis\Client as RedisClient;

// Load config early so error/CORS behavior can be environment-aware.
$config = require __DIR__ . '/../config/config.php';
$appDebug = (bool) ($config['app']['debug'] ?? false);

// Ensure PHP notices/warnings never corrupt JSON API responses.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    global $appDebug;
    renderUnhandledExceptionResponse($e, $appDebug);
});

configureErrorLogDestination($config);
logAdminAuthConfiguration($config);
applyBaseHeaders($config);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    handleCorsPreflight($config);
}

// Initialize database
$db = new Database($config['database']['path']);
$db->init();
logDatabaseIntegrityWarning($db, $config);

// Initialize FPL client with caching
$cacheDir = $config['cache']['path'];
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cache = new FileCache($cacheDir);
$fplClient = new FplClient(
    cache: $cache,
    cacheTtl: $config['cache']['ttl'],
    rateLimitDir: $config['fpl']['rate_limit_dir'],
    connectTimeout: (float) ($config['fpl']['connect_timeout'] ?? 8.0),
    requestTimeout: (float) ($config['fpl']['request_timeout'] ?? 15.0)
);
$responseCacheClient = createResponseCacheClient();

// Simple router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Remove /api prefix if present
if (str_starts_with($uri, '/api')) {
    $uri = substr($uri, 4);
}

try {
    match (true) {
        $uri === '' || $uri === '/health' => handleHealth($db, $config),
        $uri === '/admin/login' && $_SERVER['REQUEST_METHOD'] === 'POST' => handleAdminLogin($config),
        $uri === '/admin/session' => handleAdminSession($config),
        $uri === '/players' => handlePlayers($db, $config),
        $uri === '/players/enhanced' => handlePlayersEnhanced($db, $config),
        $uri === '/fixtures' => handleFixtures($db, $config),
        $uri === '/gameweek/current' => handleCurrentGameweek($db),
        $uri === '/teams' => handleTeams($db, $config),
        $uri === '/sync/status' => handleSyncStatus($config),
        $uri === '/sync/players' => withAdminToken($config, static fn() => handleSyncPlayers($db, $fplClient)),
        $uri === '/sync/fixtures' => withAdminToken($config, static fn() => handleSyncFixtures($db, $fplClient)),
        $uri === '/sync/odds' => withAdminToken($config, static fn() => handleSyncOdds($db, $config)),
        $uri === '/sync/understat' => withAdminToken($config, static fn() => handleSyncUnderstat($db, $config)),
        $uri === '/sync/season-history' => withAdminToken($config, static fn() => handleSyncSeasonHistory($db, $fplClient)),
        preg_match('#^/players/(\d+)/xmins$#', $uri, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'PUT' => withAdminToken($config, static fn() => handleSetXMins($db, (int) $m[1])),
        preg_match('#^/players/(\d+)/xmins$#', $uri, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'DELETE' => withAdminToken($config, static fn() => handleClearXMins($db, (int) $m[1])),
        preg_match('#^/players/(\d+)/penalty-order$#', $uri, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'PUT' => withAdminToken($config, static fn() => handleSetPenaltyOrder($db, (int) $m[1])),
        preg_match('#^/players/(\d+)/penalty-order$#', $uri, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'DELETE' => withAdminToken($config, static fn() => handleClearPenaltyOrder($db, (int) $m[1])),
        preg_match('#^/teams/(\d+)/penalty-takers$#', $uri, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'PUT' => withAdminToken($config, static fn() => handleSetTeamPenaltyTakers($db, (int) $m[1])),
        $uri === '/penalty-takers' && $_SERVER['REQUEST_METHOD'] === 'GET' => handleGetPenaltyTakers($db),
        preg_match('#^/players/(\d+)$#', $uri, $m) === 1 => handlePlayer($db, (int) $m[1]),
        preg_match('#^/managers/(\d+)$#', $uri, $m) === 1 => handleManager($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/managers/(\d+)/picks/(\d+)$#', $uri, $m) === 1 => handleManagerPicks($db, $fplClient, $config, (int) $m[1], (int) $m[2]),
        preg_match('#^/managers/(\d+)/history$#', $uri, $m) === 1 => handleManagerHistory($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/managers/(\d+)/season-analysis$#', $uri, $m) === 1 => handleManagerSeasonAnalysis($db, $fplClient, $config, (int) $m[1]),
        $uri === '/sync/managers' => withAdminToken($config, static fn() => handleSyncManagers($db, $fplClient)),
        preg_match('#^/predictions/(\d+)/accuracy$#', $uri, $m) === 1 => handlePredictionAccuracy($db, (int) $m[1], $config),
        preg_match('#^/predictions/(\d+)$#', $uri, $m) === 1 => handlePredictions($db, (int) $m[1], $config),
        preg_match('#^/predictions/(\d+)/player/(\d+)$#', $uri, $m) === 1 => handlePlayerPrediction($db, (int) $m[1], (int) $m[2]),
        $uri === '/predictions/range' => handlePredictionsRange($db, $config),
        $uri === '/predictions/methodology' => handlePredictionMethodology(),
        preg_match('#^/leagues/(\d+)$#', $uri, $m) === 1 => handleLeague($db, $fplClient, (int) $m[1]),
        preg_match('#^/leagues/(\d+)/standings$#', $uri, $m) === 1 => handleLeagueStandings($db, $fplClient, (int) $m[1]),
        preg_match('#^/leagues/(\d+)/analysis$#', $uri, $m) === 1 => handleLeagueAnalysis($db, $fplClient, (int) $m[1]),
        preg_match('#^/leagues/(\d+)/season-analysis$#', $uri, $m) === 1 => handleLeagueSeasonAnalysis($db, $fplClient, (int) $m[1]),
        $uri === '/compare' => handleCompare($db, $fplClient),
        $uri === '/live/current' => handleLiveCurrentGameweek($db, $fplClient, $config),
        preg_match('#^/live/(\d+)$#', $uri, $m) === 1 => handleLive($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/live/(\d+)/manager/(\d+)$#', $uri, $m) === 1 => handleLiveManager($db, $fplClient, $config, (int) $m[1], (int) $m[2]),
        preg_match('#^/live/(\d+)/manager/(\d+)/enhanced$#', $uri, $m) === 1 => handleLiveManagerEnhanced($db, $fplClient, $config, (int) $m[1], (int) $m[2]),
        preg_match('#^/live/(\d+)/bonus$#', $uri, $m) === 1 => handleLiveBonus($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/live/(\d+)/samples$#', $uri, $m) === 1 => handleLiveSamples($db, $fplClient, $config, (int) $m[1]),
        preg_match('#^/admin/sample/(\d+)$#', $uri, $m) === 1 => withAdminToken($config, static fn() => handleAdminSample($db, $fplClient, $config, (int) $m[1])),
        $uri === '/fixtures/status' => handleFixturesStatus($db, $fplClient, $config),
        preg_match('#^/ownership/(\d+)$#', $uri, $m) === 1 => handleOwnership($db, $fplClient, $config, (int) $m[1]),
        $uri === '/transfers/suggest' => handleTransferSuggest($db, $fplClient),
        $uri === '/transfers/simulate' => handleTransferSimulate($db, $fplClient),
        $uri === '/transfers/targets' => handleTransferTargets($db, $fplClient),
        $uri === '/planner/chips/suggest' => handlePlannerChipSuggest($db, $fplClient),
        $uri === '/planner/optimize' => handlePlannerOptimize($db, $fplClient),
        default => handleNotFound(),
    };
} catch (Throwable $e) {
    renderUnhandledExceptionResponse($e, $appDebug);
}

function configureErrorLogDestination(array $config): void
{
    $envPath = trim((string) (getenv('SUPERFPL_ERROR_LOG') ?: ''));
    $configuredPath = $config['logs']['error_log'] ?? '';
    $logPath = $envPath !== '' ? $envPath : (is_string($configuredPath) ? $configuredPath : '');
    if ($logPath === '') {
        return;
    }

    $logDir = dirname($logPath);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logPath);
    }
}

function logUnhandledThrowable(Throwable $e, ?string $requestId = null): string
{
    if ($requestId === null || $requestId === '') {
        try {
            $requestId = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $requestId = uniqid('req_', true);
        }
    }

    $payload = [
        'timestamp' => date('c'),
        'request_id' => $requestId,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        $encoded = sprintf(
            '{"timestamp":"%s","request_id":"%s","message":"%s"}',
            date('c'),
            $requestId,
            addslashes($e->getMessage())
        );
    }

    error_log('[superfpl] ' . $encoded);

    return $requestId;
}

function getOrCreateRequestId(): string
{
    static $requestId = null;
    if (is_string($requestId) && $requestId !== '') {
        return $requestId;
    }

    try {
        $requestId = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $requestId = uniqid('req_', true);
    }

    return $requestId;
}

function appendVaryHeader(string $token): void
{
    $existing = headers_list();
    foreach ($existing as $header) {
        if (!str_starts_with(strtolower($header), 'vary:')) {
            continue;
        }

        $current = trim(substr($header, strlen('vary:')));
        $tokens = array_map('trim', explode(',', $current));
        foreach ($tokens as $existingToken) {
            if (strcasecmp($existingToken, $token) === 0) {
                return;
            }
        }
    }

    header("Vary: {$token}", false);
}

function getAllowedCorsOrigins(array $config): array
{
    $allowed = $config['security']['cors_allowed_origins'] ?? [];
    if (!is_array($allowed)) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn($origin): string => is_string($origin) ? trim($origin) : '',
        $allowed
    )));
}

function isCorsOriginAllowed(array $allowedOrigins, string $origin): bool
{
    if ($origin === '') {
        return true;
    }

    if (in_array('*', $allowedOrigins, true)) {
        return true;
    }

    return in_array($origin, $allowedOrigins, true);
}

function applyCorsHeaders(array $config): void
{
    $allowedOrigins = getAllowedCorsOrigins($config);
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origin !== '' && isCorsOriginAllowed($allowedOrigins, $origin)) {
        if (in_array('*', $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: *');
        } else {
            header("Access-Control-Allow-Origin: {$origin}");
            appendVaryHeader('Origin');
            header('Access-Control-Allow-Credentials: true');
        }
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-XSRF-Token');
    header('Access-Control-Max-Age: 600');
}

function applyBaseHeaders(array $config): void
{
    applyCorsHeaders($config);
    ensureXsrfTokenCookie();
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    header('X-Request-ID: ' . getOrCreateRequestId());
}

function handleCorsPreflight(array $config): void
{
    $allowedOrigins = getAllowedCorsOrigins($config);
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && !isCorsOriginAllowed($allowedOrigins, $origin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Origin not allowed']);
        exit;
    }

    http_response_code(204);
    exit;
}

function renderUnhandledExceptionResponse(Throwable $e, bool $debug): void
{
    $requestId = logUnhandledThrowable($e, getOrCreateRequestId());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-ID: ' . $requestId);
    }

    $payload = [
        'error' => $debug ? $e->getMessage() : 'Internal server error',
        'request_id' => $requestId,
    ];
    if ($debug) {
        $payload['type'] = get_class($e);
        $payload['trace'] = $e->getTraceAsString();
    }

    echo json_encode($payload);
}

function getExpectedAdminToken(array $config): string
{
    $security = $config['security'] ?? [];
    $token = $security['admin_token'] ?? '';
    return is_string($token) ? trim($token) : '';
}

function logAdminAuthConfiguration(array $config): void
{
    static $logged = false;
    if ($logged) {
        return;
    }
    $logged = true;

    if (getExpectedAdminToken($config) !== '') {
        return;
    }

    error_log('SECURITY WARNING: SUPERFPL_ADMIN_TOKEN is empty; admin endpoints are disabled.');
}

function isHttpsRequest(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }

    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto !== '' && $proto === 'https') {
        return true;
    }

    return false;
}

function setCookieValue(string $name, string $value, bool $httpOnly, int $ttlSeconds): void
{
    $expiresAt = time() + $ttlSeconds;
    $parts = [
        sprintf('%s=%s', $name, rawurlencode($value)),
        'Path=/',
        'Max-Age=' . $ttlSeconds,
        'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expiresAt),
        'SameSite=Lax',
    ];

    if (isHttpsRequest()) {
        $parts[] = 'Secure';
    }

    if ($httpOnly) {
        $parts[] = 'HttpOnly';
    }

    $cookieHeader = implode('; ', $parts);
    header('Set-Cookie: ' . $cookieHeader, false);
    if (!isset($GLOBALS['superfpl_set_cookies']) || !is_array($GLOBALS['superfpl_set_cookies'])) {
        $GLOBALS['superfpl_set_cookies'] = [];
    }
    $GLOBALS['superfpl_set_cookies'][] = $cookieHeader;
}

/**
 * @return array<string, string>
 */
function parseCookieHeader(): array
{
    $rawCookie = trim((string) ($_SERVER['HTTP_COOKIE'] ?? ''));
    if ($rawCookie === '') {
        return [];
    }

    $pairs = explode(';', $rawCookie);
    $cookies = [];
    foreach ($pairs as $pair) {
        $trimmed = trim($pair);
        if ($trimmed === '' || !str_contains($trimmed, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $trimmed, 2);
        $cookieName = trim($name);
        if ($cookieName === '') {
            continue;
        }

        $cookies[$cookieName] = urldecode(trim($value));
    }

    return $cookies;
}

function getRequestCookie(string $name): string
{
    if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) && $_COOKIE[$name] !== '') {
        return $_COOKIE[$name];
    }

    $cookies = parseCookieHeader();
    $value = $cookies[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

function createRandomToken(int $byteLength = 32): string
{
    try {
        return bin2hex(random_bytes($byteLength));
    } catch (\Throwable) {
        return bin2hex(pack('d', microtime(true))) . bin2hex(pack('d', mt_rand()));
    }
}

function ensureXsrfTokenCookie(): string
{
    $existing = getRequestCookie('XSRF-TOKEN');
    if ($existing !== '') {
        return $existing;
    }

    $token = createRandomToken(16);
    setCookieValue('XSRF-TOKEN', $token, false, 43200);
    return $token;
}

function getExpectedAdminSessionHash(array $config): string
{
    $expectedToken = getExpectedAdminToken($config);
    if ($expectedToken === '') {
        return '';
    }

    return hash('sha256', $expectedToken);
}

function hasValidAdminSession(array $config): bool
{
    $expectedHash = getExpectedAdminSessionHash($config);
    if ($expectedHash === '') {
        return false;
    }

    $session = getRequestCookie('superfpl_admin');
    return $session !== '' && hash_equals($expectedHash, $session);
}

function hasValidAdminCsrf(): bool
{
    $cookieToken = getRequestCookie('XSRF-TOKEN');
    $headerToken = trim((string) ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? ''));

    return $cookieToken !== '' && $headerToken !== '' && hash_equals($cookieToken, $headerToken);
}

function readRequestBody(): string
{
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody)) {
        $rawBody = '';
    }

    if ($rawBody === '' && PHP_SAPI === 'cli') {
        $stdin = file_get_contents('php://stdin');
        if (is_string($stdin)) {
            $rawBody = $stdin;
        }
    }

    return $rawBody;
}

/**
 * @return array<string, mixed>|null
 */
function decodeJsonRequestBody(): ?array
{
    $rawBody = readRequestBody();
    if (trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : null;
}

function handleAdminLogin(array $config): void
{
    $expectedToken = getExpectedAdminToken($config);
    if ($expectedToken === '') {
        http_response_code(503);
        echo json_encode(['error' => 'Admin auth not configured']);
        return;
    }

    $body = decodeJsonRequestBody();
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        return;
    }

    $providedToken = trim((string) ($body['token'] ?? ''));
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $sessionHash = getExpectedAdminSessionHash($config);
    setCookieValue('superfpl_admin', $sessionHash, true, 43200);
    ensureXsrfTokenCookie();

    echo json_encode(['success' => true]);
}

function handleAdminSession(array $config): void
{
    $expectedToken = getExpectedAdminToken($config);
    if ($expectedToken === '') {
        http_response_code(503);
        echo json_encode(['error' => 'Admin auth not configured']);
        return;
    }

    if (!hasValidAdminSession($config)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    echo json_encode(['authenticated' => true]);
}

function withAdminToken(array $config, callable $handler): void
{
    $expectedToken = getExpectedAdminToken($config);
    if ($expectedToken === '') {
        http_response_code(503);
        echo json_encode(['error' => 'Admin auth not configured']);
        return;
    }

    if (!hasValidAdminSession($config)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    if (!hasValidAdminCsrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        return;
    }

    $handler();
}

function handleHealth(Database $db, array $config): void
{
    $dbHealth = getDatabaseHealthStatus($db, $config);
    $status = ($dbHealth['status'] ?? 'error') === 'ok' ? 'ok' : 'degraded';

    if ($status !== 'ok') {
        http_response_code(503);
    }

    echo json_encode([
        'status' => $status,
        'timestamp' => date('c'),
        'checks' => [
            'database' => [
                'status' => $dbHealth['status'] ?? 'error',
                'checked_at' => $dbHealth['checked_at'] ?? date('c'),
                'message' => $dbHealth['message'] ?? null,
            ],
        ],
    ]);
}

/**
 * @return array{status: string, checked_at: string, message: string|null, source: string}
 */
function getDatabaseHealthStatus(Database $db, array $config, int $ttlSeconds = 60): array
{
    $cacheBase = is_string($config['cache']['path'] ?? null)
        ? (string) $config['cache']['path']
        : (__DIR__ . '/../cache');
    $healthDir = rtrim($cacheBase, '/\\') . '/health';
    $healthFile = $healthDir . '/db_integrity.json';
    $now = time();

    if (file_exists($healthFile)) {
        $mtime = @filemtime($healthFile);
        if (is_int($mtime) && ($now - $mtime) <= $ttlSeconds) {
            $cachedRaw = @file_get_contents($healthFile);
            if (is_string($cachedRaw) && $cachedRaw !== '') {
                $cached = json_decode($cachedRaw, true);
                if (
                    is_array($cached)
                    && is_string($cached['status'] ?? null)
                    && in_array($cached['status'], ['ok', 'error'], true)
                    && is_string($cached['checked_at'] ?? null)
                ) {
                    return [
                        'status' => $cached['status'],
                        'checked_at' => $cached['checked_at'],
                        'message' => isset($cached['message']) && is_string($cached['message'])
                            ? $cached['message']
                            : null,
                        'source' => 'cache',
                    ];
                }
            }
        }
    }

    $status = 'ok';
    $message = null;

    try {
        $result = $db->getPdo()->query('PRAGMA quick_check')->fetchColumn();
        $normalized = strtolower(trim((string) $result));
        if ($normalized !== 'ok') {
            $status = 'error';
            $message = (string) $result;
        }
    } catch (Throwable $e) {
        $status = 'error';
        $message = $e->getMessage();
    }

    $checkedAt = date('c');
    $payload = [
        'status' => $status,
        'checked_at' => $checkedAt,
        'message' => $message,
    ];

    if (!is_dir($healthDir)) {
        @mkdir($healthDir, 0755, true);
    }
    @file_put_contents($healthFile, (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

    return [
        'status' => $status,
        'checked_at' => $checkedAt,
        'message' => $message,
        'source' => 'fresh',
    ];
}

function logDatabaseIntegrityWarning(Database $db, array $config): void
{
    $health = getDatabaseHealthStatus($db, $config);
    if (($health['status'] ?? 'error') !== 'error') {
        return;
    }

    // Log only when a fresh check runs to avoid noisy duplicate warnings.
    if (($health['source'] ?? '') !== 'fresh') {
        return;
    }

    $message = trim((string) ($health['message'] ?? 'unknown'));
    error_log('HEALTH WARNING: SQLite integrity check failed: ' . $message);
}

function handleSyncStatus(array $config): void
{
    $file = $config['cache']['path'] . '/sync_version.txt';
    $lastSync = file_exists($file) ? (int) file_get_contents($file) : 0;
    echo json_encode(['last_sync' => $lastSync]);
}

function createResponseCacheClient(): ?RedisClient
{
    $host = getenv('REDIS_HOST') ?: 'redis';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $password = trim((string) (getenv('REDIS_PASSWORD') ?: ''));
    $redisUrl = getenv('REDIS_URL') ?: '';
    if ($redisUrl !== '') {
        $parts = parse_url($redisUrl);
        if (is_array($parts)) {
            $host = (string) ($parts['host'] ?? $host);
            $port = (int) ($parts['port'] ?? $port);
            $urlPassword = isset($parts['pass']) ? trim((string) $parts['pass']) : '';
            if ($urlPassword !== '') {
                $password = $urlPassword;
            }
        }
    }

    $hosts = array_values(array_unique([$host, '127.0.0.1', 'host.docker.internal']));
    foreach ($hosts as $candidateHost) {
        try {
            $parameters = [
                'scheme' => 'tcp',
                'host' => $candidateHost,
                'port' => $port,
            ];
            if ($password !== '') {
                $parameters['password'] = $password;
            }
            $client = new RedisClient($parameters, [
                'timeout' => 0.5,
                'read_write_timeout' => 0.5,
            ]);
            $client->ping();
            return $client;
        } catch (\Throwable) {
        }
    }

    return null;
}

function buildResponseCacheKey(array $config, string $namespace): string
{
    $dbPath = $config['database']['path'] ?? '';
    $dbMtime = ($dbPath && file_exists($dbPath)) ? (string) filemtime($dbPath) : '0';
    $syncVersionFile = ($config['cache']['path'] ?? (__DIR__ . '/../cache')) . '/sync_version.txt';
    $syncVersion = file_exists($syncVersionFile) ? trim((string) file_get_contents($syncVersionFile)) : '0';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    return 'resp:v1:' . sha1($namespace . '|' . $requestUri . '|' . $dbMtime . '|' . $syncVersion);
}

function withResponseCache(array $config, string $namespace, int $ttlSeconds, callable $producer): void
{
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || (($_GET['nocache'] ?? '0') === '1')
        || (($_GET['refresh'] ?? '0') === '1')
    ) {
        header('X-Response-Cache: BYPASS');
        $producer();
        return;
    }

    global $responseCacheClient;
    if (!$responseCacheClient instanceof RedisClient) {
        header('X-Response-Cache: BYPASS');
        $producer();
        return;
    }

    $cacheKey = buildResponseCacheKey($config, $namespace);

    try {
        $cached = $responseCacheClient->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            header('X-Response-Cache: HIT');
            echo $cached;
            return;
        }
    } catch (\Throwable) {
        header('X-Response-Cache: BYPASS');
        $producer();
        return;
    }

    ob_start();
    $producer();
    $body = (string) ob_get_clean();

    $status = http_response_code();
    if ($status === false || $status === 0) {
        $status = 200;
    }

    $cacheStatus = 'BYPASS';
    if ($status === 200 && $body !== '') {
        try {
            $responseCacheClient->setex($cacheKey, $ttlSeconds, $body);
            $cacheStatus = 'MISS';
        } catch (\Throwable) {
        }
    }

    header("X-Response-Cache: {$cacheStatus}");
    echo $body;
}

/**
 * Parse xMins overrides from query string.
 *
 * Accepted forms:
 * - {"123": 80}
 * - {"123": {"27": 80, "28": 78}}
 *
 * @return array<int, float|array<int, float>>
 */
function parseXMinsOverridesFromQuery(): array
{
    if (!isset($_GET['xmins'])) {
        return [];
    }

    $decoded = json_decode((string) $_GET['xmins'], true);
    if (!is_array($decoded)) {
        return [];
    }

    $overrides = [];
    foreach ($decoded as $playerId => $value) {
        $normalizedPlayerId = (int) $playerId;
        if ($normalizedPlayerId <= 0) {
            continue;
        }

        if (is_array($value)) {
            $perGw = [];
            foreach ($value as $gw => $mins) {
                if (!is_numeric($mins)) {
                    continue;
                }
                $normalizedGw = (int) $gw;
                $perGw[$normalizedGw] = max(0.0, min(95.0, (float) $mins));
            }
            if (!empty($perGw)) {
                $overrides[$normalizedPlayerId] = $perGw;
            }
            continue;
        }

        if (is_numeric($value)) {
            $overrides[$normalizedPlayerId] = max(0.0, min(95.0, (float) $value));
        }
    }

    return $overrides;
}

/**
 * @param array<int, float|array<int, float>> $xMinsOverrides
 */
function resolveXMinsOverrideForGameweek(array $xMinsOverrides, int $playerId, int $gameweek): ?float
{
    if (!isset($xMinsOverrides[$playerId])) {
        return null;
    }

    $raw = $xMinsOverrides[$playerId];
    if (is_array($raw)) {
        if (!isset($raw[$gameweek]) || !is_numeric($raw[$gameweek])) {
            return null;
        }
        return max(0.0, min(95.0, (float) $raw[$gameweek]));
    }

    if (!is_numeric($raw)) {
        return null;
    }

    return max(0.0, min(95.0, (float) $raw));
}

function handlePlayers(Database $db, array $config): void
{
    withResponseCache($config, 'players', 300, function () use ($db): void {
        $service = new PlayerService($db);

        $filters = [];
        if (isset($_GET['position'])) {
            $filters['position'] = (int) $_GET['position'];
        }
        if (isset($_GET['team'])) {
            $filters['team'] = (int) $_GET['team'];
        }

        $players = $service->getAll($filters);
        $teamService = new TeamService($db);
        $teams = $teamService->getAll();

        echo json_encode([
            'players' => $players,
            'teams' => $teams,
        ]);
    });
}

function handlePlayersEnhanced(Database $db, array $config): void
{
    withResponseCache($config, 'players-enhanced', 300, function () use ($db): void {
        $service = new \SuperFPL\Api\Services\PlayerMetricsService($db);

        $filters = [];
        if (isset($_GET['position'])) {
            $filters['position'] = (int) $_GET['position'];
        }
        if (isset($_GET['team'])) {
            $filters['team'] = (int) $_GET['team'];
        }

        $players = $service->getAllWithMetrics($filters);
        $teamService = new TeamService($db);
        $teams = $teamService->getAll();

        echo json_encode([
            'players' => $players,
            'teams' => $teams,
        ]);
    });
}

function handlePlayer(Database $db, int $id): void
{
    $service = new PlayerService($db);
    $player = $service->getById($id);

    if ($player === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Player not found']);
        return;
    }

    echo json_encode($player);
}

function handleFixtures(Database $db, array $config): void
{
    withResponseCache($config, 'fixtures', 300, function () use ($db): void {
        $service = new FixtureService($db);
        $gameweek = isset($_GET['gameweek']) ? (int) $_GET['gameweek'] : null;

        $fixtures = $service->getAll($gameweek);
        echo json_encode(['fixtures' => $fixtures]);
    });
}

function handleTeams(Database $db, array $config): void
{
    withResponseCache($config, 'teams', 600, function () use ($db): void {
        $service = new TeamService($db);
        $teams = $service->getAll();
        echo json_encode(['teams' => $teams]);
    });
}

function handleSyncPlayers(Database $db, FplClient $fplClient): void
{
    $sync = new PlayerSync($db, $fplClient);
    $result = $sync->sync();

    echo json_encode([
        'success' => true,
        'players_synced' => $result['players'],
        'teams_synced' => $result['teams'],
    ]);
}

function handleSyncFixtures(Database $db, FplClient $fplClient): void
{
    $sync = new FixtureSync($db, $fplClient);
    $count = $sync->sync();

    echo json_encode([
        'success' => true,
        'fixtures_synced' => $count,
    ]);
}

function handleSyncOdds(Database $db, array $config): void
{
    $oddsConfig = $config['odds_api'] ?? [];
    $apiKey = $oddsConfig['api_key'] ?? '';

    if (empty($apiKey)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'ODDS_API_KEY not configured',
            'note' => 'Set ODDS_API_KEY env var for odds data',
        ]);
        return;
    }

    $client = new OddsApiClient($apiKey, $config['cache']['path'] . '/odds');
    $sync = new OddsSync($db, $client);

    // Sync match odds (h2h, totals)
    $matchResult = $sync->syncMatchOdds();

    // Sync goalscorer odds (anytime scorer)
    $goalscorerResult = $sync->syncAllGoalscorerOdds();

    // Sync assist odds (anytime assist)
    $assistResult = $sync->syncAllAssistOdds();

    echo json_encode([
        'success' => true,
        'match_odds' => [
            'fixtures_found' => $matchResult['fixtures'],
            'fixtures_matched' => $matchResult['matched'],
        ],
        'goalscorer_odds' => [
            'fixtures_matched' => $goalscorerResult['fixtures'],
            'players_synced' => $goalscorerResult['players'],
        ],
        'assist_odds' => [
            'fixtures_matched' => $assistResult['fixtures'],
            'players_synced' => $assistResult['players'],
        ],
        'api_quota' => $client->getQuota(),
    ]);
}

function handleSyncUnderstat(Database $db, array $config): void
{
    $cacheDir = ($config['cache']['path'] ?? '/tmp') . '/understat';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $season = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;
    $client = new UnderstatClient($cacheDir);
    $sync = new UnderstatSync($db, $client);
    $result = $sync->sync($season);

    echo json_encode([
        'success' => true,
        'season' => $season,
        'total_players' => $result['total'],
        'matched' => $result['matched'],
        'unmatched' => $result['unmatched'],
        'unmatched_players' => $result['unmatched_players'],
    ]);
}

function handleSyncSeasonHistory(Database $db, FplClient $fplClient): void
{
    $sync = new PlayerSync($db, $fplClient);
    $count = $sync->syncSeasonHistory();

    echo json_encode([
        'success' => true,
        'season_history_records' => $count,
    ]);
}

function handleManager(Database $db, FplClient $fplClient, array $config, int $id): void
{
    withResponseCache($config, 'manager', 120, function () use ($db, $fplClient, $id): void {
        $service = new ManagerService($db, $fplClient);
        $manager = $service->getById($id);

        if ($manager === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Manager not found']);
            return;
        }

        echo json_encode($manager);
    });
}

function handleManagerPicks(Database $db, FplClient $fplClient, array $config, int $managerId, int $gameweek): void
{
    withResponseCache($config, 'manager-picks', 120, function () use ($db, $fplClient, $managerId, $gameweek): void {
        $service = new ManagerService($db, $fplClient);
        $picks = $service->getPicks($managerId, $gameweek);

        if ($picks === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Picks not found']);
            return;
        }

        echo json_encode($picks);
    });
}

function handleManagerHistory(Database $db, FplClient $fplClient, array $config, int $managerId): void
{
    withResponseCache($config, 'manager-history', 120, function () use ($db, $fplClient, $managerId): void {
        $service = new ManagerService($db, $fplClient);
        $history = $service->getHistory($managerId);

        if ($history === null) {
            http_response_code(404);
            echo json_encode(['error' => 'History not found']);
            return;
        }

        echo json_encode($history);
    });
}

function handleManagerSeasonAnalysis(Database $db, FplClient $fplClient, array $config, int $managerId): void
{
    withResponseCache($config, 'manager-season-analysis', 120, function () use ($db, $fplClient, $managerId): void {
        $service = new ManagerSeasonAnalysisService($db, $fplClient);
        $analysis = $service->analyze($managerId);

        if ($analysis === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Season analysis not found']);
            return;
        }

        echo json_encode($analysis);
    });
}

function handleSyncManagers(Database $db, FplClient $fplClient): void
{
    $sync = new ManagerSync($db, $fplClient);
    $result = $sync->syncAll();

    echo json_encode([
        'success' => true,
        'synced' => $result['synced'],
        'failed' => $result['failed'],
    ]);
}

function handlePredictions(Database $db, int $gameweek, array $config): void
{
    withResponseCache($config, 'predictions', 120, function () use ($db, $gameweek): void {
        $gwService = new \SuperFPL\Api\Services\GameweekService($db);
        $currentGw = $gwService->getCurrentGameweek();
        $service = new PredictionService($db);

        if ($gameweek < $currentGw) {
            // Serve from snapshots for past gameweeks
            $predictions = $service->getSnapshotPredictions($gameweek);

            if (empty($predictions)) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'No prediction snapshot found for this gameweek',
                    'requested_gameweek' => $gameweek,
                    'current_gameweek' => $currentGw,
                ]);
                return;
            }

            echo json_encode([
                'gameweek' => $gameweek,
                'current_gameweek' => $currentGw,
                'source' => 'snapshot',
                'predictions' => $predictions,
                'generated_at' => date('c'),
            ]);
            return;
        }

        $predictions = $service->getPredictions($gameweek);

        $response = [
            'gameweek' => $gameweek,
            'current_gameweek' => $currentGw,
            'predictions' => $predictions,
            'generated_at' => date('c'),
        ];

        if (isset($_GET['include_methodology'])) {
            $response['methodology'] = PredictionService::getMethodology();
        }

        echo json_encode($response);
    });
}

function handlePredictionAccuracy(Database $db, int $gameweek, array $config): void
{
    withResponseCache($config, 'prediction-accuracy', 600, function () use ($db, $gameweek): void {
        $service = new PredictionService($db);
        $accuracy = $service->getAccuracy($gameweek);

        if ($accuracy['summary']['count'] === 0) {
            http_response_code(404);
            echo json_encode([
                'error' => 'No accuracy data available for this gameweek',
                'gameweek' => $gameweek,
            ]);
            return;
        }

        echo json_encode([
            'gameweek' => $gameweek,
            'accuracy' => $accuracy,
        ]);
    });
}

function handlePredictionsRange(Database $db, array $config): void
{
    withResponseCache($config, 'predictions-range', 120, function () use ($db): void {
        $gwService = new \SuperFPL\Api\Services\GameweekService($db);
        $actionableGw = $gwService->getNextActionableGameweek();
        $xMinsOverrides = parseXMinsOverridesFromQuery();

        // Get gameweek range from query params (default: next 6 gameweeks from actionable)
        $startGw = isset($_GET['start']) ? (int) $_GET['start'] : $actionableGw;
        $endGw = isset($_GET['end']) ? (int) $_GET['end'] : min($startGw + 5, 38);

        // Validate range
        $startGw = max($actionableGw, min(38, $startGw));
        $endGw = max($startGw, min(38, $endGw));
        $gameweeks = range($startGw, $endGw);
        $placeholders = implode(',', array_fill(0, count($gameweeks), '?'));

        // Build fixtures map and counts: club_id -> gameweek -> [{opponent, is_home}]
        $fixtureRows = $db->fetchAll(
            "SELECT f.gameweek, f.home_club_id, f.away_club_id,
                    h.short_name as home_short, a.short_name as away_short
             FROM fixtures f
             JOIN clubs h ON f.home_club_id = h.id
             JOIN clubs a ON f.away_club_id = a.id
             WHERE f.gameweek IN ($placeholders)",
            $gameweeks
        );

        $fixturesMap = [];
        $fixtureCounts = [];
        foreach ($fixtureRows as $row) {
            $gw = (int) $row['gameweek'];
            $homeId = (int) $row['home_club_id'];
            $awayId = (int) $row['away_club_id'];

            $fixturesMap[$homeId][$gw][] = ['opponent' => $row['away_short'], 'is_home' => true];
            $fixturesMap[$awayId][$gw][] = ['opponent' => $row['home_short'], 'is_home' => false];

            $fixtureCounts[$gw][$homeId] = ($fixtureCounts[$gw][$homeId] ?? 0) + 1;
            $fixtureCounts[$gw][$awayId] = ($fixtureCounts[$gw][$awayId] ?? 0) + 1;
        }

        // Fetch all predictions for the range in a single query
        $predictions = $db->fetchAll(
            "SELECT
                pp.player_id,
                pp.gameweek,
                pp.predicted_points,
                pp.predicted_if_fit,
                pp.expected_mins,
                pp.expected_mins_if_fit,
                pp.if_fit_breakdown_json,
                pp.confidence,
                p.web_name,
                p.club_id as team,
                p.position,
                p.now_cost,
                p.form,
                p.total_points
            FROM player_predictions pp
            JOIN players p ON pp.player_id = p.id
            WHERE pp.gameweek IN ($placeholders)
            ORDER BY pp.player_id, pp.gameweek",
            $gameweeks
        );

        // Group by player with predictions keyed by gameweek
        $playerMap = [];
        foreach ($predictions as $pred) {
            $playerId = (int) $pred['player_id'];
            $teamId = (int) $pred['team'];
            $position = (int) $pred['position'];
            $gw = (int) $pred['gameweek'];

            if (!isset($playerMap[$playerId])) {
                $playerMap[$playerId] = [
                    'player_id' => $playerId,
                    'web_name' => $pred['web_name'],
                    'team' => $teamId,
                    'position' => $position,
                    'now_cost' => (int) $pred['now_cost'],
                    'form' => (float) $pred['form'],
                    'total_points' => (int) $pred['total_points'],
                    'expected_mins' => [],
                    'expected_mins_if_fit' => (int) round((float) ($pred['expected_mins_if_fit'] ?? 90)),
                    'predictions' => [],
                    'if_fit_predictions' => [],
                    'if_fit_breakdowns' => [],
                    'total_predicted' => 0,
                ];
            }

            $ifFitBreakdown = json_decode((string) ($pred['if_fit_breakdown_json'] ?? '{}'), true);
            if (!is_array($ifFitBreakdown)) {
                $ifFitBreakdown = [];
            }
            $normalizedBreakdown = [];
            foreach ($ifFitBreakdown as $key => $value) {
                if (is_string($key) && is_numeric($value)) {
                    $normalizedBreakdown[$key] = round((float) $value, 2);
                }
            }

            $predictedPoints = (float) $pred['predicted_points'];
            $overrideMins = resolveXMinsOverrideForGameweek($xMinsOverrides, $playerId, $gw);
            if ($overrideMins !== null) {
                $ifFitPoints = (float) ($pred['predicted_if_fit'] ?? $predictedPoints);
                $ifFitMins = (float) ($pred['expected_mins_if_fit'] ?? $pred['expected_mins'] ?? 90);
                $fixtureCount = max(1, (int) ($fixtureCounts[$gw][$teamId] ?? 1));
                $predictedPoints = PredictionScaler::scaleFromIfFitBreakdown(
                    $ifFitPoints,
                    $ifFitMins,
                    $overrideMins,
                    $ifFitBreakdown,
                    $fixtureCount,
                    $position
                );
            }

            $playerMap[$playerId]['expected_mins'][$gw] = (int) round((float) ($pred['expected_mins'] ?? 90));
            $playerMap[$playerId]['predictions'][$gw] = round($predictedPoints, 1);
            $playerMap[$playerId]['if_fit_predictions'][$gw] = round((float) ($pred['predicted_if_fit'] ?? 0), 2);
            $playerMap[$playerId]['if_fit_breakdowns'][$gw] = $normalizedBreakdown;
            $playerMap[$playerId]['total_predicted'] += $predictedPoints;
        }

        // Round totals and convert to array
        $players = array_values(array_map(function ($player) {
            $player['total_predicted'] = round($player['total_predicted'], 1);
            return $player;
        }, $playerMap));

        // Sort by total predicted points descending
        usort($players, fn($a, $b) => $b['total_predicted'] <=> $a['total_predicted']);

        echo json_encode([
            'gameweeks' => $gameweeks,
            'current_gameweek' => $actionableGw,
            'players' => $players,
            'fixtures' => $fixturesMap,
            'generated_at' => date('c'),
        ]);
    });
}

function handlePredictionMethodology(): void
{
    echo json_encode(PredictionService::getMethodology());
}

function handleCurrentGameweek(Database $db): void
{
    $service = new \SuperFPL\Api\Services\GameweekService($db);
    $current = $service->getCurrentGameweek();
    $upcoming = $service->getUpcomingGameweeks(6);
    $fixtureCounts = $service->getFixtureCounts($upcoming);

    // Find DGW and BGW teams (batch queries, reuse fixture counts)
    $dgwTeams = $service->getMultipleDoubleGameweekTeams($upcoming, $fixtureCounts);
    $bgwTeams = $service->getMultipleBlankGameweekTeams($upcoming, $fixtureCounts);

    echo json_encode([
        'current_gameweek' => $current,
        'upcoming_gameweeks' => $upcoming,
        'fixture_counts' => $fixtureCounts,
        'double_gameweek_teams' => $dgwTeams,
        'blank_gameweek_teams' => $bgwTeams,
    ]);
}

function handlePlayerPrediction(Database $db, int $gameweek, int $playerId): void
{
    $service = new PredictionService($db);
    $prediction = $service->getPlayerPrediction($playerId, $gameweek);

    if ($prediction === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Player not found']);
        return;
    }

    echo json_encode($prediction);
}

function handleLeague(Database $db, FplClient $fplClient, int $leagueId): void
{
    $service = new LeagueService($db, $fplClient);
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $league = $service->getLeague($leagueId, $page);

    if ($league === null) {
        http_response_code(404);
        echo json_encode(['error' => 'League not found']);
        return;
    }

    echo json_encode($league);
}

function handleLeagueStandings(Database $db, FplClient $fplClient, int $leagueId): void
{
    $service = new LeagueService($db, $fplClient);
    $standings = $service->getAllStandings($leagueId);

    echo json_encode([
        'league_id' => $leagueId,
        'standings' => $standings,
    ]);
}

function handleLeagueAnalysis(Database $db, FplClient $fplClient, int $leagueId): void
{
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;

    // Get current gameweek if not specified
    if ($gameweek === null) {
        $gwService = new \SuperFPL\Api\Services\GameweekService($db);
        $gameweek = $gwService->getCurrentGameweek();
    }

    // Get league standings
    $leagueService = new LeagueService($db, $fplClient);
    $league = $leagueService->getLeague($leagueId);

    if ($league === null) {
        http_response_code(404);
        echo json_encode(['error' => 'League not found']);
        return;
    }

    // Get top 20 manager IDs from league
    $standings = $league['standings']['results'] ?? [];
    $managerIds = array_slice(array_column($standings, 'entry'), 0, 20);

    if (count($managerIds) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'League needs at least 2 managers']);
        return;
    }

    // Run comparison on league members
    $comparisonService = new ComparisonService($db, $fplClient);
    $comparison = $comparisonService->compare($managerIds, $gameweek);

    echo json_encode([
        'league' => [
            'id' => $leagueId,
            'name' => $league['league']['name'] ?? 'Unknown',
        ],
        'gameweek' => $gameweek,
        'managers' => array_map(function($s) {
            return [
                'id' => $s['entry'],
                'name' => $s['player_name'],
                'team_name' => $s['entry_name'],
                'rank' => $s['rank'],
                'total' => $s['total'],
            ];
        }, array_slice($standings, 0, 20)),
        'comparison' => $comparison,
    ]);
}

function handleLeagueSeasonAnalysis(Database $db, FplClient $fplClient, int $leagueId): void
{
    $gwFrom = isset($_GET['gw_from']) ? (int) $_GET['gw_from'] : null;
    $gwTo = isset($_GET['gw_to']) ? (int) $_GET['gw_to'] : null;
    $topN = isset($_GET['top_n']) ? (int) $_GET['top_n'] : 20;
    $topN = max(2, min($topN, 50));

    $leagueService = new LeagueService($db, $fplClient);
    $managerSeasonAnalysisService = new ManagerSeasonAnalysisService($db, $fplClient);
    $service = new LeagueSeasonAnalysisService($leagueService, $managerSeasonAnalysisService);
    $analysis = $service->analyze($leagueId, $gwFrom, $gwTo, $topN);

    if (isset($analysis['error'])) {
        http_response_code((int) ($analysis['status'] ?? 400));
        echo json_encode(['error' => $analysis['error']]);
        return;
    }

    echo json_encode($analysis);
}

function handleCompare(Database $db, FplClient $fplClient): void
{
    // Parse manager IDs from query string (e.g., ?ids=123,456,789&gw=25)
    $idsParam = $_GET['ids'] ?? '';
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;

    if (empty($idsParam)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing ids parameter (e.g., ?ids=123,456,789)']);
        return;
    }

    $managerIds = array_map('intval', explode(',', $idsParam));
    $managerIds = array_filter($managerIds, fn($id) => $id > 0);
    $managerIds = array_values(array_unique($managerIds));

    if (count($managerIds) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Need at least 2 manager IDs to compare']);
        return;
    }

    if (count($managerIds) > 50) {
        http_response_code(400);
        echo json_encode(['error' => 'Too many manager IDs (max 50)']);
        return;
    }

    // If no gameweek specified, try to detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new ComparisonService($db, $fplClient);
    $comparison = $service->compare($managerIds, $gameweek);

    echo json_encode($comparison);
}

function handleLive(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    withResponseCache($config, 'live', 30, function () use ($db, $fplClient, $config, $gameweek): void {
        $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
        $data = $service->getLiveData($gameweek);

        echo json_encode($data);
    });
}

function handleLiveManager(Database $db, FplClient $fplClient, array $config, int $gameweek, int $managerId): void
{
    withResponseCache($config, 'live-manager', 15, function () use ($db, $fplClient, $config, $gameweek, $managerId): void {
        $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
        $data = $service->getManagerLivePoints($managerId, $gameweek);

        echo json_encode($data);
    });
}

function handleLiveBonus(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    withResponseCache($config, 'live-bonus', 30, function () use ($db, $fplClient, $config, $gameweek): void {
        $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
        $predictions = $service->getBonusPredictions($gameweek);

        echo json_encode([
            'gameweek' => $gameweek,
            'bonus_predictions' => $predictions,
        ]);
    });
}

function handleLiveCurrentGameweek(Database $db, FplClient $fplClient, array $config): void
{
    $gwService = new \SuperFPL\Api\Services\GameweekService($db);
    $currentGw = $gwService->getCurrentGameweek();

    $service = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $data = $service->getLiveData($currentGw);

    $data['current_gameweek'] = $currentGw;

    echo json_encode($data);
}

function handleLiveManagerEnhanced(Database $db, FplClient $fplClient, array $config, int $gameweek, int $managerId): void
{
    $liveService = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
    $ownershipService = new \SuperFPL\Api\Services\OwnershipService(
        $db,
        $fplClient,
        $config['cache']['path'] . '/ownership'
    );

    $data = $liveService->getManagerLivePointsEnhanced($managerId, $gameweek, $ownershipService);

    if (isset($data['error'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Manager or gameweek data not found']);
        return;
    }

    echo json_encode($data);
}

function handleLiveSamples(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    withResponseCache($config, 'live-samples', 30, function () use ($db, $fplClient, $config, $gameweek): void {
        $liveService = new LiveService($db, $fplClient, $config['cache']['path'] . '/live');
        $sampleService = new SampleService($db, $fplClient, $config['cache']['path'] . '/samples');

        // Get live data for points
        $liveData = $liveService->getLiveData($gameweek);
        $elements = $liveData['elements'] ?? [];

        // Get sample data with calculated averages
        $data = $sampleService->getSampleData($gameweek, $elements);

        echo json_encode($data);
    });
}

function handleAdminSample(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    // Simple sampling - just sample a small number for quick results
    $sampleService = new SampleService($db, $fplClient, $config['cache']['path'] . '/samples');

    // Check if we should do a full sync (will take a long time)
    $full = ($_GET['full'] ?? '') === '1';

    if ($full) {
        // Full sync - this will take several minutes due to API rate limits
        $results = $sampleService->sampleManagersForGameweek($gameweek);
    } else {
        // Quick sync - just verify samples exist
        $hasSamples = $sampleService->hasSamplesForGameweek($gameweek);
        $results = [
            'has_samples' => $hasSamples,
            'message' => $hasSamples
                ? 'Samples already exist for this gameweek'
                : 'No samples found. Use ?full=1 to run full sample sync (takes several minutes)',
        ];
    }

    echo json_encode([
        'gameweek' => $gameweek,
        'results' => $results,
    ]);
}

function handleFixturesStatus(Database $db, FplClient $fplClient, array $config): void
{
    withResponseCache($config, 'fixtures-status', 30, function () use ($db, $fplClient, $config): void {
    // Keep this endpoint fast for Live tab boot. Full fixture sync can be slow
    // and should run via cron (or explicitly with ?refresh=1), not inline by default.
    $shouldRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    if ($shouldRefresh) {
        try {
            $fixtureSync = new \SuperFPL\Api\Sync\FixtureSync($db, $fplClient);
            $fixtureSync->sync();
        } catch (\Throwable) {
            // Serve current DB state even if refresh fails.
        }
    }

    // Get fixture status for current/active gameweek detection
    $fixtures = $db->fetchAll(
        'SELECT id, gameweek, kickoff_time, finished, home_score, away_score,
                home_club_id, away_club_id
         FROM fixtures
         WHERE kickoff_time IS NOT NULL
         ORDER BY kickoff_time ASC'
    );

    // Add started/finished status based on kickoff time
    // Use 120 minutes as a heuristic for match completion if not already marked finished
    $now = time();
    foreach ($fixtures as &$fixture) {
        $kickoff = strtotime($fixture['kickoff_time']);
        if ($kickoff === false) {
            $fixture['started'] = false;
            $fixture['finished'] = (bool) $fixture['finished'];
            $fixture['minutes'] = 0;
            continue;
        }
        $fixture['started'] = $now >= $kickoff;

        // If kickoff was more than 120 minutes ago, consider it finished
        // This handles cases where the database sync hasn't run recently
        $minutesSinceKickoff = ($now - $kickoff) / 60;
        if (!$fixture['finished'] && $minutesSinceKickoff >= 120) {
            $fixture['finished'] = true;
        }

        $fixture['minutes'] = $fixture['started'] && !$fixture['finished']
            ? min(90, (int)$minutesSinceKickoff)
            : ($fixture['finished'] ? 90 : 0);
    }
    unset($fixture);

    // Group by gameweek
    $byGameweek = [];
    foreach ($fixtures as $f) {
        $gw = $f['gameweek'];
        if (!isset($byGameweek[$gw])) {
            $byGameweek[$gw] = [
                'gameweek' => $gw,
                'fixtures' => [],
                'total' => 0,
                'started' => 0,
                'finished' => 0,
                'first_kickoff' => null,
                'last_kickoff' => null,
            ];
        }
        $byGameweek[$gw]['fixtures'][] = $f;
        $byGameweek[$gw]['total']++;
        if ($f['started']) $byGameweek[$gw]['started']++;
        if ($f['finished']) $byGameweek[$gw]['finished']++;

        if ($byGameweek[$gw]['first_kickoff'] === null || $f['kickoff_time'] < $byGameweek[$gw]['first_kickoff']) {
            $byGameweek[$gw]['first_kickoff'] = $f['kickoff_time'];
        }
        if ($byGameweek[$gw]['last_kickoff'] === null || $f['kickoff_time'] > $byGameweek[$gw]['last_kickoff']) {
            $byGameweek[$gw]['last_kickoff'] = $f['kickoff_time'];
        }
    }

    // Determine current/active gameweek
    // Active if: now >= first_kickoff - 90min AND now <= last_kickoff + 12hrs
    $activeGw = null;
    $latestFinished = null;

    foreach ($byGameweek as $gw => $data) {
        $firstKickoff = strtotime($data['first_kickoff']);
        $lastKickoff = strtotime($data['last_kickoff']);

        if ($firstKickoff === false || $lastKickoff === false) {
            if ($data['finished'] === $data['total'] && $data['total'] > 0) {
                $latestFinished = $gw;
            }
            continue;
        }

        $gwStart = $firstKickoff - (90 * 60); // 90 min before first kickoff
        $gwEnd = $lastKickoff + (12 * 60 * 60); // 12 hours after last kickoff

        if ($now >= $gwStart && $now <= $gwEnd) {
            $activeGw = $gw;
            break;
        }

        if ($data['finished'] === $data['total'] && $data['total'] > 0) {
            $latestFinished = $gw;
        }
    }

    // If no active GW, use latest finished
    $currentGw = $activeGw ?? $latestFinished ?? 1;

    echo json_encode([
        'current_gameweek' => $currentGw,
        'is_live' => $activeGw !== null,
        'gameweeks' => array_values($byGameweek),
    ]);
    });
}

function handleOwnership(Database $db, FplClient $fplClient, array $config, int $gameweek): void
{
    $sampleSize = isset($_GET['sample']) ? (int) $_GET['sample'] : 100;
    $sampleSize = min(max($sampleSize, 50), 500); // Clamp between 50-500

    $service = new \SuperFPL\Api\Services\OwnershipService(
        $db,
        $fplClient,
        $config['cache']['path'] . '/ownership'
    );

    $data = $service->getEffectiveOwnership($gameweek, $sampleSize);

    echo json_encode($data);
}

function handleTransferSuggest(Database $db, FplClient $fplClient): void
{
    $managerId = isset($_GET['manager']) ? (int) $_GET['manager'] : null;
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    $transfers = isset($_GET['transfers']) ? (int) $_GET['transfers'] : 1;

    if ($managerId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing manager parameter']);
        return;
    }

    // If no gameweek specified, detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new TransferService($db, $fplClient);
    $suggestions = $service->getSuggestions($managerId, $gameweek, $transfers);

    echo json_encode($suggestions);
}

function handleTransferSimulate(Database $db, FplClient $fplClient): void
{
    $managerId = isset($_GET['manager']) ? (int) $_GET['manager'] : null;
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    $outPlayerId = isset($_GET['out']) ? (int) $_GET['out'] : null;
    $inPlayerId = isset($_GET['in']) ? (int) $_GET['in'] : null;

    if ($managerId === null || $outPlayerId === null || $inPlayerId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing parameters: manager, out, in required']);
        return;
    }

    // If no gameweek specified, detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new TransferService($db, $fplClient);
    $simulation = $service->simulateTransfer($managerId, $gameweek, $outPlayerId, $inPlayerId);

    echo json_encode($simulation);
}

function handleTransferTargets(Database $db, FplClient $fplClient): void
{
    $gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    $position = isset($_GET['position']) ? (int) $_GET['position'] : null;
    $maxPrice = isset($_GET['max_price']) ? (float) $_GET['max_price'] : null;

    // If no gameweek specified, detect current
    if ($gameweek === null) {
        $fixture = $db->fetchOne(
            "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1"
        );
        $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
    }

    $service = new TransferService($db, $fplClient);
    $targets = $service->getTopTargets($gameweek, $position, $maxPrice !== null ? $maxPrice * 10 : null);

    echo json_encode([
        'gameweek' => $gameweek,
        'targets' => $targets,
    ]);
}

/**
 * @return array<int, string>
 */
function getPlannerValidChips(): array
{
    return ['wildcard', 'bench_boost', 'free_hit', 'triple_captain'];
}

/**
 * @return mixed
 */
function decodeJsonQueryParam(string $param)
{
    if (!array_key_exists($param, $_GET)) {
        return null;
    }

    try {
        return json_decode((string) $_GET[$param], true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new \InvalidArgumentException("Invalid JSON for {$param}");
    }
}

/**
 * @param mixed $value
 */
function parsePlannerGameweekValue(string $label, $value): int
{
    if (!is_numeric($value)) {
        throw new \InvalidArgumentException("Invalid {$label}: expected integer gameweek");
    }

    $gw = (int) $value;
    if ($gw < 1 || $gw > 38) {
        throw new \InvalidArgumentException("Invalid {$label}: expected gameweek between 1 and 38");
    }

    return $gw;
}

/**
 * @return array<string, int>
 */
function parsePlannerChipPlanFromQuery(): array
{
    $chipPlan = [];
    $decodedPlan = decodeJsonQueryParam('chip_plan');
    if ($decodedPlan !== null) {
        if (!is_array($decodedPlan) || array_is_list($decodedPlan)) {
            throw new \InvalidArgumentException('Invalid chip_plan: expected JSON object keyed by chip name');
        }

        foreach ($decodedPlan as $chip => $week) {
            if (!is_string($chip) || !in_array($chip, getPlannerValidChips(), true)) {
                throw new \InvalidArgumentException("Invalid chip_plan chip: {$chip}");
            }
            $chipPlan[$chip] = parsePlannerGameweekValue("chip_plan.{$chip}", $week);
        }
    }

    $legacyChipParams = [
        'wildcard_gw' => 'wildcard',
        'bench_boost_gw' => 'bench_boost',
        'free_hit_gw' => 'free_hit',
        'triple_captain_gw' => 'triple_captain',
    ];

    foreach ($legacyChipParams as $param => $chipName) {
        if (!array_key_exists($param, $_GET)) {
            continue;
        }

        $chipPlan[$chipName] = parsePlannerGameweekValue($param, $_GET[$param]);
    }

    return $chipPlan;
}

/**
 * @return array<int, string>
 */
function parsePlannerChipAllowFromQuery(): array
{
    $decodedAllow = decodeJsonQueryParam('chip_allow');
    if ($decodedAllow === null) {
        return [];
    }

    if (!is_array($decodedAllow) || !array_is_list($decodedAllow)) {
        throw new \InvalidArgumentException('Invalid chip_allow: expected JSON array of chip names');
    }

    $chips = [];
    foreach ($decodedAllow as $chip) {
        if (!is_string($chip) || !in_array($chip, getPlannerValidChips(), true)) {
            throw new \InvalidArgumentException('Invalid chip_allow: contains unknown chip');
        }
        $chips[] = $chip;
    }

    return array_values(array_unique($chips));
}

/**
 * @return array<string, array<int, int>>
 */
function parsePlannerChipForbidFromQuery(): array
{
    $decodedForbid = decodeJsonQueryParam('chip_forbid');
    if ($decodedForbid === null) {
        return [];
    }

    if (!is_array($decodedForbid) || array_is_list($decodedForbid)) {
        throw new \InvalidArgumentException(
            'Invalid chip_forbid: expected JSON object mapping chip names to gameweek arrays'
        );
    }

    $chipForbid = [];
    foreach ($decodedForbid as $chip => $weeks) {
        if (!is_string($chip) || !in_array($chip, getPlannerValidChips(), true)) {
            throw new \InvalidArgumentException("Invalid chip_forbid chip: {$chip}");
        }
        if (!is_array($weeks) || !array_is_list($weeks)) {
            throw new \InvalidArgumentException("Invalid chip_forbid.{$chip}: expected array of gameweeks");
        }

        $normalizedWeeks = [];
        foreach ($weeks as $index => $week) {
            $normalizedWeeks[] = parsePlannerGameweekValue("chip_forbid.{$chip}[{$index}]", $week);
        }
        $chipForbid[$chip] = array_values(array_unique($normalizedWeeks));
    }

    return $chipForbid;
}

/**
 * @return array<int, array{gameweek: int, out: int, in: int}>
 */
function parsePlannerFixedTransfersFromQuery(): array
{
    $decoded = decodeJsonQueryParam('fixed_transfers');
    if ($decoded === null) {
        return [];
    }

    if (!is_array($decoded) || !array_is_list($decoded)) {
        throw new \InvalidArgumentException('Invalid fixed_transfers: expected JSON array');
    }

    $fixedTransfers = [];
    foreach ($decoded as $index => $transfer) {
        if (!is_array($transfer)) {
            throw new \InvalidArgumentException("Invalid fixed_transfers[{$index}]: expected object");
        }

        if (!array_key_exists('gameweek', $transfer)) {
            throw new \InvalidArgumentException("Invalid fixed_transfers[{$index}]: missing gameweek");
        }
        if (!array_key_exists('out', $transfer)) {
            throw new \InvalidArgumentException("Invalid fixed_transfers[{$index}]: missing out");
        }
        if (!array_key_exists('in', $transfer)) {
            throw new \InvalidArgumentException("Invalid fixed_transfers[{$index}]: missing in");
        }

        $gameweek = parsePlannerGameweekValue("fixed_transfers[{$index}].gameweek", $transfer['gameweek']);
        if (!is_numeric($transfer['out']) || (int) $transfer['out'] <= 0) {
            throw new \InvalidArgumentException("Invalid fixed_transfers[{$index}].out: expected positive integer");
        }
        if (!is_numeric($transfer['in']) || (int) $transfer['in'] <= 0) {
            throw new \InvalidArgumentException("Invalid fixed_transfers[{$index}].in: expected positive integer");
        }

        $fixedTransfers[] = [
            'gameweek' => $gameweek,
            'out' => (int) $transfer['out'],
            'in' => (int) $transfer['in'],
        ];
    }

    return $fixedTransfers;
}

/**
 * @return array<string, mixed>
 */
function parsePlannerConstraintsFromQuery(): array
{
    $decoded = decodeJsonQueryParam('constraints');
    if ($decoded === null) {
        return [];
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new \InvalidArgumentException('Invalid constraints: expected JSON object');
    }

    $allowedKeys = ['lock_ids', 'avoid_ids', 'max_hits', 'chip_windows'];
    $unknownKeys = array_diff(array_keys($decoded), $allowedKeys);
    if (!empty($unknownKeys)) {
        throw new \InvalidArgumentException('Invalid constraints keys: ' . implode(', ', $unknownKeys));
    }

    $constraints = [];

    if (array_key_exists('lock_ids', $decoded)) {
        if (!is_array($decoded['lock_ids']) || !array_is_list($decoded['lock_ids'])) {
            throw new \InvalidArgumentException('Invalid constraints.lock_ids: expected array of player IDs');
        }
        $constraints['lock_ids'] = array_map(
            static function ($id): int {
                if (!is_numeric($id) || (int) $id <= 0) {
                    throw new \InvalidArgumentException('Invalid constraints.lock_ids: expected positive integers');
                }
                return (int) $id;
            },
            $decoded['lock_ids']
        );
    }

    if (array_key_exists('avoid_ids', $decoded)) {
        if (!is_array($decoded['avoid_ids']) || !array_is_list($decoded['avoid_ids'])) {
            throw new \InvalidArgumentException('Invalid constraints.avoid_ids: expected array of player IDs');
        }
        $constraints['avoid_ids'] = array_map(
            static function ($id): int {
                if (!is_numeric($id) || (int) $id <= 0) {
                    throw new \InvalidArgumentException('Invalid constraints.avoid_ids: expected positive integers');
                }
                return (int) $id;
            },
            $decoded['avoid_ids']
        );
    }

    if (array_key_exists('max_hits', $decoded)) {
        if (!is_numeric($decoded['max_hits']) || (int) $decoded['max_hits'] < 0) {
            throw new \InvalidArgumentException('Invalid constraints.max_hits: expected non-negative integer');
        }
        $constraints['max_hits'] = (int) $decoded['max_hits'];
    }

    if (array_key_exists('chip_windows', $decoded)) {
        if (!is_array($decoded['chip_windows']) || array_is_list($decoded['chip_windows'])) {
            throw new \InvalidArgumentException(
                'Invalid constraints.chip_windows: expected object keyed by chip name'
            );
        }

        $windows = [];
        foreach ($decoded['chip_windows'] as $chip => $window) {
            if (!is_string($chip) || !in_array($chip, getPlannerValidChips(), true)) {
                throw new \InvalidArgumentException("Invalid constraints.chip_windows chip: {$chip}");
            }
            if (!is_array($window)) {
                throw new \InvalidArgumentException("Invalid constraints.chip_windows.{$chip}: expected object");
            }

            $normalizedWindow = [];
            if (array_key_exists('from', $window)) {
                $normalizedWindow['from'] = parsePlannerGameweekValue(
                    "constraints.chip_windows.{$chip}.from",
                    $window['from']
                );
            }
            if (array_key_exists('to', $window)) {
                $normalizedWindow['to'] = parsePlannerGameweekValue(
                    "constraints.chip_windows.{$chip}.to",
                    $window['to']
                );
            }
            if (
                isset($normalizedWindow['from'], $normalizedWindow['to'])
                && $normalizedWindow['from'] > $normalizedWindow['to']
            ) {
                throw new \InvalidArgumentException(
                    "Invalid constraints.chip_windows.{$chip}: from must be <= to"
                );
            }

            $windows[$chip] = $normalizedWindow;
        }
        $constraints['chip_windows'] = $windows;
    }

    return $constraints;
}

function handlePlannerOptimize(Database $db, FplClient $fplClient): void
{
    $managerId = isset($_GET['manager']) ? (int) $_GET['manager'] : null;
    // ft=0 means "auto-detect from FPL API"; omitting ft also auto-detects
    $freeTransfers = isset($_GET['ft']) ? (int) $_GET['ft'] : 0;

    $chipMode = $_GET['chip_mode'] ?? 'locked';
    $chipPlan = [];
    $chipAllow = [];
    $chipForbid = [];
    $fixedTransfers = [];
    $constraints = [];
    try {
        $chipPlan = parsePlannerChipPlanFromQuery();
        $chipAllow = parsePlannerChipAllowFromQuery();
        $chipForbid = parsePlannerChipForbidFromQuery();
        $fixedTransfers = parsePlannerFixedTransfersFromQuery();
        $constraints = parsePlannerConstraintsFromQuery();
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        return;
    }

    $xMinsOverrides = parseXMinsOverridesFromQuery();

    // Parse FT value (float, default 1.5)
    $ftValue = isset($_GET['ft_value']) ? (float) $_GET['ft_value'] : 1.5;
    $ftValue = max(0.0, min(5.0, $ftValue));

    // Parse depth mode (quick, standard, deep)
    $depth = $_GET['depth'] ?? 'standard';
    if (!in_array($depth, ['quick', 'standard', 'deep'])) {
        $depth = 'standard';
    }

    $planningHorizon = isset($_GET['horizon']) ? (int) $_GET['horizon'] : 6;
    $planningHorizon = max(1, min(12, $planningHorizon));

    $objectiveMode = $_GET['objective'] ?? 'expected';
    if (!in_array($objectiveMode, ['expected', 'floor', 'ceiling'], true)) {
        $objectiveMode = 'expected';
    }

    // Parse skip_solve flag (return squad data without running PathSolver)
    $skipSolve = isset($_GET['skip_solve']) && $_GET['skip_solve'] === '1';
    $chipCompare = isset($_GET['chip_compare']) && $_GET['chip_compare'] === '1';

    if ($managerId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing manager parameter']);
        return;
    }

    $predictionService = new PredictionService($db);
    $gameweekService = new \SuperFPL\Api\Services\GameweekService($db);

    $optimizer = new \SuperFPL\Api\Services\TransferOptimizerService(
        $db,
        $fplClient,
        $predictionService,
        $gameweekService
    );

    try {
        $plan = $optimizer->getOptimalPlan(
            $managerId,
            $chipPlan,
            $freeTransfers,
            $xMinsOverrides,
            $fixedTransfers,
            $ftValue,
            $depth,
            $skipSolve,
            $chipMode,
            $chipAllow,
            $chipForbid,
            $chipCompare,
            $objectiveMode,
            $constraints,
            $planningHorizon,
        );
        echo json_encode($plan);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode([
            'error' => $e->getMessage(),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
        ]);
    }
}

function handlePlannerChipSuggest(Database $db, FplClient $fplClient): void
{
    $managerId = isset($_GET['manager']) ? (int) $_GET['manager'] : null;
    $freeTransfers = isset($_GET['ft']) ? (int) $_GET['ft'] : 0;
    $planningHorizon = isset($_GET['horizon']) ? (int) $_GET['horizon'] : 6;
    $planningHorizon = max(1, min(12, $planningHorizon));
    if ($managerId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing manager parameter']);
        return;
    }

    try {
        $chipPlan = parsePlannerChipPlanFromQuery();
        $chipAllow = parsePlannerChipAllowFromQuery();
        $chipForbid = parsePlannerChipForbidFromQuery();
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        return;
    }

    $predictionService = new PredictionService($db);
    $gameweekService = new \SuperFPL\Api\Services\GameweekService($db);
    $optimizer = new \SuperFPL\Api\Services\TransferOptimizerService(
        $db,
        $fplClient,
        $predictionService,
        $gameweekService
    );

    try {
        $result = $optimizer->suggestChipPlan(
            $managerId,
            $freeTransfers,
            $chipPlan,
            $chipAllow,
            $chipForbid,
            $planningHorizon
        );
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
        ]);
    }
}

function handleSetXMins(Database $db, int $playerId): void
{
    $body = decodeJsonRequestBody();
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        return;
    }
    $expectedMins = $body['expected_mins'] ?? null;

    if ($expectedMins === null || !is_numeric($expectedMins)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid expected_mins']);
        return;
    }

    $expectedMins = max(0, min(95, (int) $expectedMins));

    $db->query('UPDATE players SET xmins_override = ? WHERE id = ?', [$expectedMins, $playerId]);

    echo json_encode(['success' => true, 'player_id' => $playerId, 'expected_mins' => $expectedMins]);
}

function handleClearXMins(Database $db, int $playerId): void
{
    $db->query('UPDATE players SET xmins_override = NULL WHERE id = ?', [$playerId]);

    echo json_encode(['success' => true, 'player_id' => $playerId, 'expected_mins' => 90]);
}

function handleSetPenaltyOrder(Database $db, int $playerId): void
{
    $body = decodeJsonRequestBody();
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        return;
    }
    $order = $body['penalty_order'] ?? null;

    if ($order === null || !is_numeric($order) || (int) $order < 1 || (int) $order > 5) {
        http_response_code(400);
        echo json_encode(['error' => 'penalty_order must be 1-5 (1=primary taker, 2=backup, etc)']);
        return;
    }

    $order = (int) $order;
    $db->query('UPDATE players SET penalty_order = ? WHERE id = ?', [$order, $playerId]);

    echo json_encode(['success' => true, 'player_id' => $playerId, 'penalty_order' => $order]);
}

function handleClearPenaltyOrder(Database $db, int $playerId): void
{
    $db->query('UPDATE players SET penalty_order = NULL WHERE id = ?', [$playerId]);

    echo json_encode(['success' => true, 'player_id' => $playerId, 'penalty_order' => null]);
}

function handleSetTeamPenaltyTakers(Database $db, int $teamId): void
{
    $body = decodeJsonRequestBody();
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        return;
    }
    $takers = $body['takers'] ?? [];

    if (!is_array($takers) || empty($takers)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Body must contain "takers" array of {player_id, order}',
            'example' => ['takers' => [['player_id' => 123, 'order' => 1], ['player_id' => 456, 'order' => 2]]],
        ]);
        return;
    }

    // Clear existing penalty orders for this team
    $db->query('UPDATE players SET penalty_order = NULL WHERE club_id = ?', [$teamId]);

    $updated = [];
    foreach ($takers as $taker) {
        $playerId = (int) ($taker['player_id'] ?? 0);
        $order = (int) ($taker['order'] ?? 0);

        if ($playerId <= 0 || $order < 1 || $order > 5) {
            continue;
        }

        $db->query(
            'UPDATE players SET penalty_order = ? WHERE id = ? AND club_id = ?',
            [$order, $playerId, $teamId]
        );
        $updated[] = ['player_id' => $playerId, 'order' => $order];
    }

    echo json_encode(['success' => true, 'team_id' => $teamId, 'updated' => $updated]);
}

function handleGetPenaltyTakers(Database $db): void
{
    $takers = $db->fetchAll(
        'SELECT p.id, p.web_name, p.club_id as team, p.position, p.penalty_order,
                c.name as team_name, c.short_name as team_short
         FROM players p
         JOIN clubs c ON p.club_id = c.id
         WHERE p.penalty_order IS NOT NULL
         ORDER BY p.club_id, p.penalty_order'
    );

    echo json_encode(['penalty_takers' => $takers]);
}

function handleNotFound(): void
{
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
