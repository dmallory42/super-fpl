<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Testing\TestCase;
use Maia\Orm\Connection;
use SuperFPL\Api\Controllers\HealthController;

class HealthControllerTest extends TestCase
{
    protected function controllers(): array
    {
        return [HealthController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->container()->instance(Connection::class, $this->db());
    }

    public function testHealthEndpoint(): void
    {
        $response = $this->get('/health');

        $response->assertStatus(200);

        $json = $response->json();
        $this->assertSame('ok', $json['status'] ?? null);
        $this->assertArrayHasKey('timestamp', $json);
        $this->assertSame('ok', $json['checks']['database']['status'] ?? null);
    }
}
