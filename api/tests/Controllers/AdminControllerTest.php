<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Testing\TestCase;
use Maia\Core\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use SuperFPL\Api\Controllers\AdminController;
use SuperFPL\Api\Database;
use SuperFPL\Api\Tests\Support\FakeFplClient;
use SuperFPL\FplClient\FplClient;

require_once __DIR__ . '/../Support/FakeFplClient.php';

class AdminControllerTest extends TestCase
{
    private Database $database;
    private string $configDir;

    protected function controllers(): array
    {
        return [AdminController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Database(':memory:');
        $this->app->container()->instance(Database::class, $this->database);
        $this->app->container()->instance(FplClient::class, new FakeFplClient());

        $this->configDir = sys_get_temp_dir() . '/superfpl-admin-controller-config-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0777, true);
        file_put_contents(
            $this->configDir . '/config.php',
            "<?php return [
                'security' => ['admin_token' => 'test-admin-token'],
                'odds_api' => ['api_key' => ''],
                'cache' => ['path' => '/tmp/superfpl-test-cache']
            ];"
        );
        $this->app->container()->instance(Config::class, new Config($this->configDir));

        $this->database->query('CREATE TABLE clubs (
            id INTEGER PRIMARY KEY,
            name TEXT,
            short_name TEXT
        )');
        $this->database->query('CREATE TABLE players (
            id INTEGER PRIMARY KEY,
            web_name TEXT,
            club_id INTEGER,
            position INTEGER,
            penalty_order INTEGER
        )');

        $this->database->query("INSERT INTO clubs (id, name, short_name) VALUES (1, 'Arsenal', 'ARS')");
        $this->database->query("INSERT INTO players (id, web_name, club_id, position, penalty_order) VALUES (10, 'Saka', 1, 3, 1)");
        $this->database->query("INSERT INTO players (id, web_name, club_id, position, penalty_order) VALUES (11, 'Odegaard', 1, 3, NULL)");
    }

    protected function tearDown(): void
    {
        @unlink($this->configDir . '/config.php');
        @rmdir($this->configDir);

        parent::tearDown();
    }

    public function testLoginAcceptsValidToken(): void
    {
        $response = $this->post('/admin/login', ['token' => 'test-admin-token']);

        $response->assertStatus(200);
        $this->assertSame(true, $response->json()['success'] ?? null);
    }

    public function testLoginRejectsInvalidToken(): void
    {
        $response = $this->post('/admin/login', ['token' => 'wrong-token']);

        $response->assertStatus(401);
        $this->assertSame('Unauthorized', $response->json()['error'] ?? null);
    }

    public function testSessionRejectsWithoutCookie(): void
    {
        $response = $this->get('/admin/session');

        $response->assertStatus(401);
        $this->assertSame('Unauthorized', $response->json()['error'] ?? null);
    }

    public function testPenaltyTakersReturnsList(): void
    {
        $response = $this->get('/penalty-takers');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayHasKey('penalty_takers', $json);
        $this->assertCount(1, $json['penalty_takers']);
        $this->assertSame(10, $json['penalty_takers'][0]['id'] ?? null);
    }

    #[DataProvider('protectedEndpointProvider')]
    public function testProtectedEndpointsRequireAdminAuth(string $method, string $path, ?array $body): void
    {
        $requestBody = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $headers = $body !== null ? ['content-type' => 'application/json'] : [];
        $request = new Request($method, $path, [], $headers, $requestBody, []);
        $response = $this->send($request);

        $response->assertStatus(401);
        $this->assertSame('Unauthorized', $response->json()['error'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    public static function protectedEndpointProvider(): array
    {
        return [
            'sync-players-get' => ['GET', '/sync/players', null],
            'sync-players-post' => ['POST', '/sync/players', []],
            'sync-fixtures-get' => ['GET', '/sync/fixtures', null],
            'sync-fixtures-post' => ['POST', '/sync/fixtures', []],
            'sync-managers-get' => ['GET', '/sync/managers', null],
            'sync-managers-post' => ['POST', '/sync/managers', []],
            'sync-odds-get' => ['GET', '/sync/odds', null],
            'sync-odds-post' => ['POST', '/sync/odds', []],
            'sync-understat-get' => ['GET', '/sync/understat', null],
            'sync-understat-post' => ['POST', '/sync/understat', []],
            'sync-season-history-get' => ['GET', '/sync/season-history', null],
            'sync-season-history-post' => ['POST', '/sync/season-history', []],
            'admin-sample-get' => ['GET', '/admin/sample/27', null],
            'team-penalty-takers-put' => [
                'PUT',
                '/teams/1/penalty-takers',
                ['takers' => [['player_id' => 10, 'order' => 1]]],
            ],
        ];
    }

    private function send(Request $request): TestResponse
    {
        return new TestResponse($this->app->handle($request), $this);
    }
}
