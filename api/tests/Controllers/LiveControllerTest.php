<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Testing\TestCase;
use SuperFPL\Api\Controllers\LiveController;
use SuperFPL\Api\Database;
use SuperFPL\Api\Tests\Support\FakeFplClient;
use SuperFPL\FplClient\FplClient;

require_once __DIR__ . '/../Support/FakeFplClient.php';

class LiveControllerTest extends TestCase
{
    private Database $database;
    private string $configDir;
    private string $cacheDir;

    protected function controllers(): array
    {
        return [LiveController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Database(':memory:');
        $this->app->container()->instance(Database::class, $this->database);

        $this->configDir = sys_get_temp_dir() . '/superfpl-live-config-' . bin2hex(random_bytes(6));
        $this->cacheDir = $this->configDir . '/cache';
        mkdir($this->cacheDir, 0777, true);
        file_put_contents(
            $this->configDir . '/config.php',
            sprintf(
                "<?php return ['cache' => ['path' => '%s']];",
                addslashes($this->cacheDir)
            )
        );
        $this->app->container()->instance(Config::class, new Config($this->configDir));

        $this->app->container()->instance(
            FplClient::class,
            new FakeFplClient(
                entries: [
                    100 => [
                        'raw' => ['summary_overall_rank' => 12345],
                        'history' => [
                            'current' => [
                                ['event' => 26, 'overall_rank' => 15000],
                            ],
                        ],
                        'picks' => [
                            27 => [
                                'picks' => [
                                    ['element' => 10, 'position' => 1, 'multiplier' => 2, 'is_captain' => true],
                                ],
                            ],
                        ],
                    ],
                ],
                leagues: [
                    314 => [
                        'standings' => [
                            1 => [
                                'standings' => [
                                    'results' => [
                                        ['entry' => 100, 'rank' => 1],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                liveData: [
                    27 => [
                        'elements' => [
                            [
                                'id' => 10,
                                'stats' => ['total_points' => 5],
                                'explain' => [],
                            ],
                        ],
                    ],
                ],
                fixturesData: [
                    [
                        'id' => 1,
                        'event' => 27,
                        'stats' => [
                            [
                                'identifier' => 'bps',
                                'h' => [['element' => 10, 'value' => 20]],
                                'a' => [],
                            ],
                        ],
                    ],
                ]
            )
        );

        $this->database->query('CREATE TABLE fixtures (
            id INTEGER PRIMARY KEY,
            gameweek INTEGER,
            home_club_id INTEGER,
            away_club_id INTEGER,
            kickoff_time TEXT,
            finished INTEGER,
            home_score INTEGER,
            away_score INTEGER
        )');
        $this->database->query('CREATE TABLE players (
            id INTEGER PRIMARY KEY,
            web_name TEXT,
            club_id INTEGER,
            position INTEGER
        )');
        $this->database->query('CREATE TABLE manager_picks (
            manager_id INTEGER,
            gameweek INTEGER,
            player_id INTEGER,
            position INTEGER,
            multiplier INTEGER
        )');
        $this->database->query('CREATE TABLE sample_picks (
            gameweek INTEGER,
            tier TEXT,
            manager_id INTEGER,
            player_id INTEGER,
            multiplier INTEGER
        )');

        $kickoff = gmdate('Y-m-d\\TH:i:s\\Z', time() - 3600);
        $this->database->query(
            'INSERT INTO fixtures (
                id, gameweek, home_club_id, away_club_id, kickoff_time, finished, home_score, away_score
            ) VALUES (1, 27, 1, 2, ?, 0, NULL, NULL)',
            [$kickoff]
        );
        $this->database->query(
            "INSERT INTO players (id, web_name, club_id, position) VALUES (10, 'Saka', 1, 3)"
        );
        $this->database->query(
            'INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier)
             VALUES (100, 27, 10, 1, 2)'
        );
        $this->database->query(
            "INSERT INTO sample_picks (gameweek, tier, manager_id, player_id, multiplier)
             VALUES (27, 'top_10k', 100, 10, 2)"
        );
    }

    protected function tearDown(): void
    {
        $configFile = $this->configDir . '/config.php';
        if (is_file($configFile)) {
            @unlink($configFile);
        }

        if (is_dir($this->cacheDir)) {
            $cacheFiles = glob($this->cacheDir . '/*');
            if ($cacheFiles !== false) {
                foreach ($cacheFiles as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($this->cacheDir);
        }

        @rmdir($this->configDir);

        parent::tearDown();
    }

    public function testCurrentEndpointReturnsCurrentGameweekPayload(): void
    {
        $response = $this->get('/live/current');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(27, $json['current_gameweek'] ?? null);
        $this->assertArrayHasKey('elements', $json);
    }

    public function testGameweekEndpointReturnsLiveData(): void
    {
        $response = $this->get('/live/27');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayHasKey('elements', $json);
        $this->assertSame(10, $json['elements'][0]['id'] ?? null);
    }

    public function testManagerEndpointReturnsLiveManagerData(): void
    {
        $response = $this->get('/live/27/manager/100');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(100, $json['manager_id'] ?? null);
        $this->assertArrayHasKey('players', $json);
    }

    public function testManagerEnhancedEndpointReturnsRankImpactPayload(): void
    {
        $response = $this->get('/live/27/manager/100/enhanced');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayHasKey('rank_impact', $json);
        $this->assertArrayHasKey('eo_sample_size', $json);
    }

    public function testBonusEndpointReturnsPredictionPayload(): void
    {
        $response = $this->get('/live/27/bonus');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(27, $json['gameweek'] ?? null);
        $this->assertArrayHasKey('bonus_predictions', $json);
    }

    public function testSamplesEndpointReturnsSamplePayload(): void
    {
        $response = $this->get('/live/27/samples');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(27, $json['gameweek'] ?? null);
        $this->assertArrayHasKey('samples', $json);
        $this->assertArrayHasKey('top_10k', $json['samples']);
    }
}
