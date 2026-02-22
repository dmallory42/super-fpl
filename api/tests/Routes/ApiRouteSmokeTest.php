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
    }

    public function testSyncStatusDefaultsToZeroForFreshCache(): void
    {
        $response = $this->callApi('/api/sync/status');

        self::assertSame(200, $response['status']);
        self::assertSame(0, $response['json']['last_sync'] ?? null);
    }

    public function testPlayersRouteReturnsPlayersArray(): void
    {
        $response = $this->callApi('/api/players');

        self::assertSame(200, $response['status']);
        self::assertArrayHasKey('players', $response['json']);
        self::assertIsArray($response['json']['players']);
    }

    public function testTeamsRouteReturnsTeamsArray(): void
    {
        $response = $this->callApi('/api/teams');

        self::assertSame(200, $response['status']);
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
        self::assertSame('Not found', $response['json']['error'] ?? null);
    }

    /**
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private function callApi(string $uri, string $method = 'GET'): array
    {
        $env = array_merge($_ENV, [
            'PATH' => getenv('PATH') ?: '',
            'REQ_METHOD' => $method,
            'REQ_URI' => $uri,
            'SUPERFPL_DB_PATH' => $this->dbPath,
            'SUPERFPL_CACHE_PATH' => $this->cachePath,
            'SUPERFPL_RATE_LIMIT_DIR' => $this->rateLimitDir,
            'SUPERFPL_ERROR_LOG' => $this->errorLogPath,
        ]);

        $command = PHP_BINARY . ' api/tests/Routes/route_harness.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot, $env);
        self::assertIsResource($process, 'Failed to start route harness process');

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

        $json = json_decode($body, true);
        self::assertIsArray($json, "Response body is not JSON for {$method} {$uri}: {$body}");

        return [
            'status' => $status,
            'body' => $body,
            'json' => $json,
        ];
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

