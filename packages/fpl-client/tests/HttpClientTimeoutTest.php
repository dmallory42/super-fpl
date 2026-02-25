<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Tests;

use PHPUnit\Framework\TestCase;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\HttpClient;
use SuperFPL\FplClient\RateLimiter;

class HttpClientTimeoutTest extends TestCase
{
    private string $tmpDir;
    private int $port;
    /** @var resource|null */
    private $serverProcess = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/fpl-client-timeout-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/rate-limit', 0777, true);

        file_put_contents(
            $this->tmpDir . '/fast.php',
            "<?php header('Content-Type: application/json'); echo json_encode(['ok' => true]);"
        );
        file_put_contents(
            $this->tmpDir . '/slow.php',
            "<?php usleep(800000); header('Content-Type: application/json'); echo json_encode(['ok' => true]);"
        );

        $this->port = $this->findFreePort();
        $command = sprintf(
            '%s -S 127.0.0.1:%d -t %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($this->tmpDir)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->serverProcess = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($this->serverProcess, 'Failed to start test HTTP server');
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $started = false;
        for ($i = 0; $i < 20; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errNo, $errStr, 0.05);
            if (is_resource($socket)) {
                fclose($socket);
                $started = true;
                break;
            }
            usleep(50000);
        }

        self::assertTrue($started, 'Timed out waiting for test HTTP server');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        $this->deleteTree($this->tmpDir);
        parent::tearDown();
    }

    public function testRequestTimeoutFailsFastOnSlowEndpoint(): void
    {
        $client = new HttpClient(
            baseUrl: "http://127.0.0.1:{$this->port}/",
            rateLimiter: new RateLimiter($this->tmpDir . '/rate-limit'),
            connectTimeout: 0.1,
            requestTimeout: 0.2
        );

        $startedAt = microtime(true);
        try {
            $client->get('slow.php', false);
            $this->fail('Expected slow request to time out');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timed out', strtolower($e->getMessage()));
        }
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(1.5, $elapsed, 'Slow request should fail within bounded timeout');
    }

    public function testRequestSucceedsWhenTimeoutBudgetIsLargeEnough(): void
    {
        $client = new HttpClient(
            baseUrl: "http://127.0.0.1:{$this->port}/",
            rateLimiter: new RateLimiter($this->tmpDir . '/rate-limit'),
            connectTimeout: 0.2,
            requestTimeout: 2.0
        );

        $data = $client->get('slow.php', false);
        $this->assertSame(true, $data['ok'] ?? null);
    }

    public function testFplClientAcceptsCustomTimeoutConfiguration(): void
    {
        $client = new FplClient(
            cache: null,
            cacheTtl: 300,
            rateLimitDir: $this->tmpDir . '/rate-limit',
            connectTimeout: 1.25,
            requestTimeout: 2.5
        );

        $reflection = new \ReflectionClass($client);
        $httpClientProp = $reflection->getProperty('httpClient');
        $httpClientProp->setAccessible(true);
        $httpClient = $httpClientProp->getValue($client);

        $httpReflection = new \ReflectionObject($httpClient);
        $connectProp = $httpReflection->getProperty('connectTimeout');
        $requestProp = $httpReflection->getProperty('requestTimeout');
        $connectProp->setAccessible(true);
        $requestProp->setAccessible(true);

        $this->assertSame(1.25, $connectProp->getValue($httpClient));
        $this->assertSame(2.5, $requestProp->getValue($httpClient));
    }

    private function findFreePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorNo, $errorString);
        self::assertIsResource($server, "Failed to allocate free port: {$errorString}");

        $name = stream_socket_get_name($server, false);
        fclose($server);
        self::assertIsString($name);

        $parts = explode(':', $name);
        return (int) end($parts);
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
