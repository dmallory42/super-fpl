<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Testing\TestCase;
use Maia\Core\Testing\TestResponse;
use Maia\Orm\Connection;
use SuperFPL\Api\Controllers\PlayerController;

class PlayerControllerTest extends TestCase
{
    private string $configDir;

    protected function controllers(): array
    {
        return [PlayerController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->container()->instance(Connection::class, $this->db());

        $this->configDir = sys_get_temp_dir() . '/superfpl-player-config-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0777, true);
        file_put_contents(
            $this->configDir . '/config.php',
            "<?php return ['security' => ['admin_token' => 'test-admin-token']];"
        );

        $this->app->container()->instance(Config::class, new Config($this->configDir));

        $this->db()->execute('CREATE TABLE clubs (
            id INTEGER PRIMARY KEY,
            name TEXT,
            short_name TEXT,
            strength_attack_home INTEGER,
            strength_attack_away INTEGER,
            strength_defence_home INTEGER,
            strength_defence_away INTEGER
        )');

        $this->db()->execute('CREATE TABLE players (
            id INTEGER PRIMARY KEY,
            code INTEGER,
            web_name TEXT,
            first_name TEXT,
            second_name TEXT,
            club_id INTEGER,
            position INTEGER,
            now_cost INTEGER,
            total_points INTEGER,
            form REAL,
            selected_by_percent REAL,
            minutes INTEGER,
            goals_scored INTEGER,
            assists INTEGER,
            clean_sheets INTEGER,
            saves INTEGER,
            expected_goals REAL,
            expected_assists REAL,
            ict_index REAL,
            bps INTEGER,
            bonus INTEGER,
            starts INTEGER,
            appearances INTEGER,
            chance_of_playing INTEGER,
            news TEXT,
            xmins_override INTEGER,
            penalty_order INTEGER
        )');

        $this->db()->execute(
            "INSERT INTO clubs (id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away)
             VALUES (1, 'Arsenal', 'ARS', 1300, 1250, 1350, 1300)"
        );
        $this->db()->execute(
            "INSERT INTO clubs (id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away)
             VALUES (2, 'Chelsea', 'CHE', 1200, 1180, 1220, 1210)"
        );
        $this->db()->execute(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, saves, expected_goals,
                expected_assists, ict_index, bps, bonus, starts, appearances, chance_of_playing, news, xmins_override, penalty_order
            ) VALUES (
                10, 10010, 'Saka', 'Bukayo', 'Saka', 1, 3, 95, 180, 6.5,
                25.1, 2100, 12, 10, 8, 0, 9.3,
                8.1, 145.6, 410, 18, 24, 25, 100, '', NULL, 1
            )"
        );
        $this->db()->execute(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, saves, expected_goals,
                expected_assists, ict_index, bps, bonus, starts, appearances, chance_of_playing, news, xmins_override, penalty_order
            ) VALUES (
                20, 10020, 'Palmer', 'Cole', 'Palmer', 2, 3, 108, 192, 7.2,
                38.5, 2050, 15, 9, 5, 0, 11.5,
                7.8, 160.5, 430, 20, 23, 24, 100, '', 90, 1
            )"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->configDir . '/config.php');
        @rmdir($this->configDir);

        parent::tearDown();
    }

    public function testPlayersEndpointReturnsPlayersAndTeams(): void
    {
        $response = $this->get('/players');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'players',
            'teams',
        ]);

        $json = $response->json();
        $this->assertCount(2, $json['players']);
        $this->assertCount(2, $json['teams']);
        $this->assertSame('Palmer', $json['players'][0]['web_name']);
    }

    public function testPlayersEndpointSupportsFilters(): void
    {
        $response = $this->send(
            new Request('GET', '/players', ['team' => '1'])
        );

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(1, $json['players']);
        $this->assertSame('Saka', $json['players'][0]['web_name']);
    }

    public function testPlayerEndpointReturnsSinglePlayer(): void
    {
        $response = $this->get('/players/10');

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertSame('Saka', $json['web_name'] ?? null);
        $this->assertSame(1, $json['team'] ?? null);
    }

    public function testPlayerEndpointReturns404ForMissingPlayer(): void
    {
        $response = $this->get('/players/999');

        $response->assertStatus(404);
        $this->assertSame('Player not found', $response->json()['error'] ?? null);
    }

    public function testSetXMinsUpdatesPlayerWhenAdminSessionIsValid(): void
    {
        $sessionHash = hash('sha256', 'test-admin-token');
        $xsrf = 'csrf-token-value';

        $response = $this->send(
            new Request(
                'PUT',
                '/players/10/xmins',
                [],
                [
                    'Content-Type' => 'application/json',
                    'Cookie' => "superfpl_admin={$sessionHash}; XSRF-TOKEN={$xsrf}",
                    'X-XSRF-Token' => $xsrf,
                ],
                json_encode(['expected_mins' => 75]) ?: '{}'
            )
        );

        $response->assertStatus(200);
        $this->assertSame(75, $response->json()['expected_mins'] ?? null);

        $row = $this->db()->query('SELECT xmins_override FROM players WHERE id = 10');
        $this->assertSame(75, (int) ($row[0]['xmins_override'] ?? 0));
    }

    private function send(Request $request): TestResponse
    {
        return new TestResponse($this->app->handle($request), $this);
    }
}
