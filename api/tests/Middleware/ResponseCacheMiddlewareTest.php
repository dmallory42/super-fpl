<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Middleware;

use Maia\Core\Config\Config;
use Maia\Core\Cache\ResponseCacheStore;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Middleware\ResponseCacheMiddleware;

class ResponseCacheMiddlewareTest extends TestCase
{
    private string $configDir;
    private string $cacheDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir() . '/superfpl-cache-config-' . bin2hex(random_bytes(6));
        $this->cacheDir = $this->configDir . '/cache';
        $this->dbPath = $this->configDir . '/test.db';

        mkdir($this->configDir, 0777, true);
        mkdir($this->cacheDir, 0777, true);
        touch($this->dbPath);

        file_put_contents(
            $this->configDir . '/config.php',
            sprintf(
                "<?php return ['database' => ['path' => '%s'], 'cache' => ['path' => '%s']];",
                addslashes($this->dbPath),
                addslashes($this->cacheDir)
            )
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->configDir . '/config.php');
        @unlink($this->dbPath);
        @rmdir($this->cacheDir);
        @rmdir($this->configDir);

        unset($_SERVER['REQUEST_URI']);

        parent::tearDown();
    }

    public function testCachesSuccessfulJsonResponses(): void
    {
        $store = new InMemoryResponseCacheStore();
        $middleware = new ResponseCacheMiddleware($store, new Config($this->configDir), 'players', 300);
        $request = new Request('GET', '/players');
        $_SERVER['REQUEST_URI'] = '/players';
        $calls = 0;

        $first = $middleware->handle($request, function () use (&$calls): Response {
            $calls++;

            return Response::json(['players' => [['id' => 1]]]);
        });

        $second = $middleware->handle($request, function () use (&$calls): Response {
            $calls++;

            return Response::json(['players' => [['id' => 2]]]);
        });

        $this->assertSame(1, $calls);
        $this->assertSame('MISS', $first->header('X-Response-Cache'));
        $this->assertSame('HIT', $second->header('X-Response-Cache'));
        $this->assertSame(['players' => [['id' => 1]]], json_decode($second->body(), true));
    }

    public function testBypassesCacheWhenDisabledByQueryParam(): void
    {
        $store = new InMemoryResponseCacheStore();
        $middleware = new ResponseCacheMiddleware($store, new Config($this->configDir), 'players', 300);
        $request = new Request('GET', '/players', ['nocache' => '1']);
        $_SERVER['REQUEST_URI'] = '/players?nocache=1';

        $response = $middleware->handle($request, fn (): Response => Response::json(['players' => []]));

        $this->assertSame('BYPASS', $response->header('X-Response-Cache'));
        $this->assertSame([], $store->all());
    }

    public function testBypassesCacheWhenStoreUnavailable(): void
    {
        $store = new InMemoryResponseCacheStore(false);
        $middleware = new ResponseCacheMiddleware($store, new Config($this->configDir), 'players', 300);
        $request = new Request('GET', '/players');
        $_SERVER['REQUEST_URI'] = '/players';

        $response = $middleware->handle($request, fn (): Response => Response::json(['players' => []]));

        $this->assertSame('BYPASS', $response->header('X-Response-Cache'));
    }
}

class InMemoryResponseCacheStore implements ResponseCacheStore
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(
        private readonly bool $available = true
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, int $ttlSeconds, string $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
