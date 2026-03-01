<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Middleware;

use Maia\Core\Config\Config;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\MiddlewareAttribute;
use Maia\Core\Routing\Route;
use Maia\Core\Testing\TestCase;
use SuperFPL\Api\Middleware\AdminAuthMiddleware;

#[Controller('/test')]
class AdminTestController
{
    #[Route('/public', method: 'GET')]
    public function publicRoute(): array
    {
        return ['ok' => true];
    }

    #[Route('/admin', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function adminRoute(): array
    {
        return ['admin' => true];
    }
}

class AdminAuthMiddlewareTest extends TestCase
{
    private string $configDir;

    protected function controllers(): array
    {
        return [AdminTestController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir() . '/superfpl-admin-config-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0777, true);
        file_put_contents(
            $this->configDir . '/config.php',
            "<?php return ['security' => ['admin_token' => 'test-admin-token']];"
        );

        $this->app->container()->instance(Config::class, new Config($this->configDir));
    }

    protected function tearDown(): void
    {
        @unlink($this->configDir . '/config.php');
        @rmdir($this->configDir);

        parent::tearDown();
    }

    public function testPublicRouteAccessible(): void
    {
        $response = $this->get('/test/public');

        $response->assertStatus(200);
    }

    public function testAdminRouteRejectsWithoutAuth(): void
    {
        $response = $this->get('/test/admin');

        $response->assertStatus(401);
        $this->assertSame('Unauthorized', $response->json()['error'] ?? null);
    }

    public function testAdminRouteAcceptsValidCookiesAndXsrfHeader(): void
    {
        $sessionHash = hash('sha256', 'test-admin-token');
        $xsrf = 'csrf-token-value';

        $response = $this->withHeader('Cookie', "superfpl_admin={$sessionHash}; XSRF-TOKEN={$xsrf}")
            ->withHeader('X-XSRF-Token', $xsrf)
            ->get('/test/admin');

        $response->assertStatus(200);
        $this->assertSame(true, $response->json()['admin'] ?? null);
    }

    public function testAdminRouteRejectsInvalidXsrfHeader(): void
    {
        $sessionHash = hash('sha256', 'test-admin-token');

        $response = $this->withHeader('Cookie', "superfpl_admin={$sessionHash}; XSRF-TOKEN=good")
            ->withHeader('X-XSRF-Token', 'bad')
            ->get('/test/admin');

        $response->assertStatus(403);
        $this->assertSame('Invalid CSRF token', $response->json()['error'] ?? null);
    }
}
