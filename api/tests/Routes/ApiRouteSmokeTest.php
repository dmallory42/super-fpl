<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Routes;

use PHPUnit\Framework\TestCase;

class ApiRouteSmokeTest extends TestCase
{
    private string $tmpRoot;
    private string $dbPath;
    private string $cachePath;
    private string $rateLimitDir;
    private string $errorLogPath;
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);
        $this->tmpRoot = sys_get_temp_dir() . '/superfpl-routes-' . bin2hex(random_bytes(6));
        $this->dbPath = $this->tmpRoot . '/test.db';
        $this->cachePath = $this->tmpRoot . '/cache';
        $this->rateLimitDir = $this->tmpRoot . '/rate-limit';
        $this->errorLogPath = $this->tmpRoot . '/logs/api-error.log';

        mkdir($this->tmpRoot, 0777, true);
        mkdir($this->cachePath, 0777, true);
        mkdir($this->rateLimitDir, 0777, true);
        mkdir(dirname($this->errorLogPath), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tmpRoot);
        parent::tearDown();
    }

    public function testHealthRouteReturnsStatusPayload(): void
    {
        $response = $this->callApi('/api/health');

        self::assertSame(200, $response['status']);
        self::assertSame('ok', $response['json']['status'] ?? null);
        self::assertIsString($response['json']['timestamp'] ?? null);
        self::assertSame('ok', $response['json']['checks']['database']['status'] ?? null);
        self::assertIsString($response['json']['checks']['database']['checked_at'] ?? null);
    }

    public function testFixturesStatusRouteReturnsEnvelope(): void
    {
        $response = $this->callApi('/api/fixtures/status');

        self::assertSame(200, $response['status']);
        self::assertArrayHasKey('current_gameweek', $response['json']);
        self::assertArrayHasKey('is_live', $response['json']);
        self::assertArrayHasKey('gameweeks', $response['json']);
    }

    public function testPlayersRouteReturnsPlayersArray(): void
    {
        $response = $this->callApi('/api/players');

        self::assertSame(200, $response['status']);
        self::assertArrayHasKey('players', $response['json']);
        self::assertIsArray($response['json']['players']);
        self::assertArrayHasKey('teams', $response['json']);
        self::assertIsArray($response['json']['teams']);
    }

    public function testPlayerRouteReturns404ForMissingPlayer(): void
    {
        $response = $this->callApi('/api/players/999999');

        self::assertSame(404, $response['status']);
        self::assertSame('Player not found', $response['json']['error'] ?? null);
    }

    public function testUnknownRouteReturns404Json(): void
    {
        $response = $this->callApi('/api/does-not-exist');

        self::assertSame(404, $response['status']);
        self::assertSame(true, $response['json']['error'] ?? null);
        self::assertSame('Route not found', $response['json']['message'] ?? null);
    }

    public function testCorsAllowsWhitelistedOrigin(): void
    {
        $response = $this->callApi(
            '/api/health',
            'GET',
            [
                'REQ_ORIGIN' => 'https://superfpl.com',
                'SUPERFPL_CORS_ALLOWED_ORIGINS' => 'https://superfpl.com,https://www.superfpl.com',
            ]
        );

        self::assertSame(200, $response['status']);
    }

    public function testCorsRejectsDisallowedOrigin(): void
    {
        $response = $this->callApi(
            '/api/health',
            'GET',
            [
                'REQ_ORIGIN' => 'https://evil.example',
                'SUPERFPL_CORS_ALLOWED_ORIGINS' => 'https://superfpl.com',
            ]
        );

        self::assertSame(403, $response['status']);
        self::assertSame(true, $response['json']['error'] ?? null);
        self::assertSame('CORS origin denied', $response['json']['message'] ?? null);
    }

    public function testCorsDefaultAllowsViteDevOrigin(): void
    {
        $response = $this->callApi(
            '/api/health',
            'GET',
            ['REQ_ORIGIN' => 'http://localhost:5173']
        );

        self::assertSame(200, $response['status']);
    }

    public function testCorsDefaultRejectsWildcardBehavior(): void
    {
        $response = $this->callApi(
            '/api/health',
            'GET',
            ['REQ_ORIGIN' => 'https://evil.example']
        );

        self::assertSame(403, $response['status']);
        self::assertSame(true, $response['json']['error'] ?? null);
        self::assertSame('CORS origin denied', $response['json']['message'] ?? null);
    }

    public function testAdminRoutesRequireTokenWhenConfigured(): void
    {
        $response = $this->callApi(
            '/api/sync/players',
            'GET',
            ['SUPERFPL_ADMIN_TOKEN' => 'abc123']
        );

        self::assertSame(401, $response['status']);
        self::assertSame('Unauthorized', $response['json']['error'] ?? null);
    }

    public function testAdminRoutesReturn503WhenTokenNotConfigured(): void
    {
        $response = $this->callApi('/api/sync/players');

        self::assertSame(503, $response['status']);
        self::assertSame('Admin auth not configured', $response['json']['error'] ?? null);
    }

    public function testAdminLoginSetsSessionAndXsrfCookies(): void
    {
        $response = $this->callApi(
            '/api/admin/login',
            'POST',
            ['SUPERFPL_ADMIN_TOKEN' => 'abc123'],
            json_encode(['token' => 'abc123'])
        );

        self::assertSame(200, $response['status']);
        self::assertSame(true, $response['json']['success'] ?? false);

        $cookies = $this->extractSetCookies($response['set_cookies']);
        self::assertArrayHasKey('superfpl_admin', $cookies);
        self::assertArrayHasKey('XSRF-TOKEN', $cookies);
    }

    public function testAdminLoginRejectsMalformedJsonBody(): void
    {
        $response = $this->callApi(
            '/api/admin/login',
            'POST',
            ['SUPERFPL_ADMIN_TOKEN' => 'abc123'],
            '{"token":'
        );

        self::assertSame(400, $response['status']);
        self::assertSame('Invalid JSON body', $response['json']['error'] ?? null);
    }

    public function testAdminMutationRequiresCsrfTokenEvenWithSessionCookie(): void
    {
        $login = $this->callApi(
            '/api/admin/login',
            'POST',
            ['SUPERFPL_ADMIN_TOKEN' => 'abc123'],
            json_encode(['token' => 'abc123'])
        );
        $cookies = $this->extractSetCookies($login['set_cookies']);
        $cookieHeader = sprintf(
            'superfpl_admin=%s; XSRF-TOKEN=%s',
            $cookies['superfpl_admin'] ?? '',
            $cookies['XSRF-TOKEN'] ?? ''
        );

        $response = $this->callApi(
            '/api/players/1/xmins',
            'PUT',
            [
                'SUPERFPL_ADMIN_TOKEN' => 'abc123',
                'REQ_COOKIE' => $cookieHeader,
            ],
            json_encode(['expected_mins' => 75])
        );

        self::assertSame(403, $response['status']);
        self::assertSame('Invalid CSRF token', $response['json']['error'] ?? null);
    }

    public function testAdminMutationSucceedsWithSessionAndCsrfToken(): void
    {
        $login = $this->callApi(
            '/api/admin/login',
            'POST',
            ['SUPERFPL_ADMIN_TOKEN' => 'abc123'],
            json_encode(['token' => 'abc123'])
        );
        $cookies = $this->extractSetCookies($login['set_cookies']);
        $xsrf = $cookies['XSRF-TOKEN'] ?? '';
        $cookieHeader = sprintf(
            'superfpl_admin=%s; XSRF-TOKEN=%s',
            $cookies['superfpl_admin'] ?? '',
            $xsrf
        );

        $response = $this->callApi(
            '/api/players/1/xmins',
            'PUT',
            [
                'SUPERFPL_ADMIN_TOKEN' => 'abc123',
                'REQ_COOKIE' => $cookieHeader,
                'REQ_X_XSRF_TOKEN' => $xsrf,
            ],
            json_encode(['expected_mins' => 75])
        );

        self::assertSame(200, $response['status']);
        self::assertSame(true, $response['json']['success'] ?? false);
        self::assertSame(75, $response['json']['expected_mins'] ?? null);
    }

    public function testAdminMutationRejectsMalformedJsonBody(): void
    {
        $login = $this->callApi(
            '/api/admin/login',
            'POST',
            ['SUPERFPL_ADMIN_TOKEN' => 'abc123'],
            json_encode(['token' => 'abc123'])
        );
        $cookies = $this->extractSetCookies($login['set_cookies']);
        $xsrf = $cookies['XSRF-TOKEN'] ?? '';
        $cookieHeader = sprintf(
            'superfpl_admin=%s; XSRF-TOKEN=%s',
            $cookies['superfpl_admin'] ?? '',
            $xsrf
        );

        $response = $this->callApi(
            '/api/players/1/xmins',
            'PUT',
            [
                'SUPERFPL_ADMIN_TOKEN' => 'abc123',
                'REQ_COOKIE' => $cookieHeader,
                'REQ_X_XSRF_TOKEN' => $xsrf,
            ],
            '{"expected_mins":'
        );

        self::assertSame(400, $response['status']);
        self::assertSame('Invalid JSON body', $response['json']['error'] ?? null);
    }

    public function testPlannerOptimizeRejectsInvalidChipPlanShape(): void
    {
        $chipPlan = rawurlencode(json_encode([1, 2, 3]));
        $response = $this->callApi("/api/planner/optimize?manager=1&chip_plan={$chipPlan}");

        self::assertSame(400, $response['status']);
        self::assertStringContainsString('chip_plan', (string) ($response['json']['error'] ?? ''));
    }

    public function testPlannerOptimizeRejectsInvalidConstraints(): void
    {
        $constraints = rawurlencode(json_encode(['unknown_key' => true]));
        $response = $this->callApi("/api/planner/optimize?manager=1&constraints={$constraints}");

        self::assertSame(400, $response['status']);
        self::assertStringContainsString('constraints', (string) ($response['json']['error'] ?? ''));
    }

    public function testPlannerOptimizeRejectsInvalidFixedTransfers(): void
    {
        $fixedTransfers = rawurlencode(json_encode([['gameweek' => 30, 'in' => 99]]));
        $response = $this->callApi("/api/planner/optimize?manager=1&fixed_transfers={$fixedTransfers}");

        self::assertSame(400, $response['status']);
        self::assertStringContainsString('fixed_transfers', (string) ($response['json']['error'] ?? ''));
    }

    public function testPlannerOptimizeRejectsInvalidChipAllowValues(): void
    {
        $chipAllow = rawurlencode(json_encode(['wildcard', 'not_a_chip']));
        $response = $this->callApi("/api/planner/optimize?manager=1&chip_allow={$chipAllow}");

        self::assertSame(400, $response['status']);
        self::assertStringContainsString('chip_allow', (string) ($response['json']['error'] ?? ''));
    }

    public function testPlannerOptimizeRejectsInvalidChipForbidStructure(): void
    {
        $chipForbid = rawurlencode(json_encode(['wildcard']));
        $response = $this->callApi("/api/planner/optimize?manager=1&chip_forbid={$chipForbid}");

        self::assertSame(400, $response['status']);
        self::assertStringContainsString('chip_forbid', (string) ($response['json']['error'] ?? ''));
    }

    public function testCompareRouteNotYetMigratedReturnsNotFound(): void
    {
        $ids = implode(',', range(1, 51));
        $response = $this->callApi("/api/compare?ids={$ids}");

        self::assertSame(404, $response['status']);
        self::assertSame(true, $response['json']['error'] ?? null);
        self::assertSame('Route not found', $response['json']['message'] ?? null);
    }

    public function testProductionUnhandledErrorsAreSanitized(): void
    {
        $response = $this->callApi(
            '/api/health',
            'GET',
            [
                'SUPERFPL_APP_ENV' => 'production',
                'SUPERFPL_DEBUG' => '0',
                'SUPERFPL_DB_PATH' => '/proc/superfpl.db',
            ]
        );

        self::assertSame(500, $response['status']);
        self::assertSame('Internal server error', $response['json']['error'] ?? null);
        self::assertArrayHasKey('request_id', $response['json']);
        self::assertArrayNotHasKey('trace', $response['json']);
    }

    /**
     * @param array<string, string> $envOverrides
     * @return array{status: int, body: string, json: array<string, mixed>, headers: array<int, string>, set_cookies: array<int, string>}
     */
    private function callApi(string $uri, string $method = 'GET', array $envOverrides = [], ?string $body = null): array
    {
        $env = array_merge($_ENV, [
            'PATH' => getenv('PATH') ?: '',
            'REQ_METHOD' => $method,
            'REQ_URI' => $uri,
            'SUPERFPL_DB_PATH' => $this->dbPath,
            'SUPERFPL_CACHE_PATH' => $this->cachePath,
            'SUPERFPL_RATE_LIMIT_DIR' => $this->rateLimitDir,
            'SUPERFPL_ERROR_LOG' => $this->errorLogPath,
        ], $envOverrides);

        $command = PHP_BINARY . ' api/tests/Routes/route_harness.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot, $env);
        self::assertIsResource($process, 'Failed to start route harness process');

        if ($body !== null) {
            fwrite($pipes[0], $body);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, "Route harness failed for {$method} {$uri}: {$stderr}");

        $decoded = json_decode((string) $stdout, true);
        self::assertIsArray($decoded, "Invalid harness output for {$method} {$uri}: {$stdout}");

        $status = (int) ($decoded['status'] ?? 0);
        $body = (string) ($decoded['body'] ?? '');
        $headers = $decoded['headers'] ?? [];
        self::assertIsArray($headers, "Response headers are not an array for {$method} {$uri}");
        $setCookies = $decoded['set_cookies'] ?? [];
        self::assertIsArray($setCookies, "Set-Cookie data is not an array for {$method} {$uri}");

        $json = [];
        if (trim($body) !== '') {
            $json = json_decode($body, true);
            self::assertIsArray($json, "Response body is not JSON for {$method} {$uri}: {$body}");
        }

        return [
            'status' => $status,
            'body' => $body,
            'json' => $json,
            'headers' => $headers,
            'set_cookies' => $setCookies,
        ];
    }

    /**
     * @param array<int, string> $setCookies
     * @return array<string, string>
     */
    private function extractSetCookies(array $setCookies): array
    {
        $cookies = [];
        foreach ($setCookies as $header) {
            if (!is_string($header) || $header === '' || !str_contains($header, '=')) {
                continue;
            }

            $cookieDef = explode(';', trim($header), 2)[0];
            [$name, $value] = explode('=', $cookieDef, 2);
            $cookieName = trim($name);
            if ($cookieName === '') {
                continue;
            }
            $cookies[$cookieName] = trim($value);
        }

        return $cookies;
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->deleteTree($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}
