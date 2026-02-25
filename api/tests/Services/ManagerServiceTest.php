<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\ManagerService;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;
use SuperFPL\FplClient\FplClient;

class ManagerServiceTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Database(':memory:');
        $this->db->init();
    }

    public function testGetByIdReturnsFreshDataAndCachesManager(): void
    {
        $entry = $this->createMock(EntryEndpoint::class);
        $entry->method('getRaw')->willReturn([
            'id' => 1001,
            'player_first_name' => 'Jane',
            'player_last_name' => 'Doe',
            'name' => 'Doe FC',
            'summary_overall_rank' => 12345,
            'summary_overall_points' => 1550,
        ]);
        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('entry')->with(1001)->willReturn($entry);

        $service = new ManagerService($this->db, $fplClient);
        $result = $service->getById(1001);

        $this->assertSame(1001, (int) ($result['id'] ?? 0));
        $cached = $this->db->fetchOne('SELECT * FROM managers WHERE id = ?', [1001]);
        $this->assertNotNull($cached);
        $this->assertSame('Jane Doe', (string) ($cached['name'] ?? ''));
        $this->assertSame('Doe FC', (string) ($cached['team_name'] ?? ''));
    }

    public function testGetByIdFallsBackToCachedManagerWhenApiFails(): void
    {
        $this->db->query(
            'INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced)
             VALUES (2002, ?, ?, ?, ?, datetime("now"))',
            ['Cached User', 'Cached XI', 2222, 1444]
        );

        $entry = $this->createMock(EntryEndpoint::class);
        $entry->method('getRaw')->willThrowException(new \RuntimeException('fpl down'));
        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('entry')->with(2002)->willReturn($entry);

        $service = new ManagerService($this->db, $fplClient);
        $result = $service->getById(2002);

        $this->assertNotNull($result);
        $this->assertSame(true, (bool) ($result['cached'] ?? false));
        $this->assertSame('Cached User', (string) ($result['name'] ?? ''));
        $this->assertSame('Cached XI', (string) ($result['team_name'] ?? ''));
    }

    public function testGetByIdReturnsNullWhenApiAndCacheAreBothUnavailable(): void
    {
        $entry = $this->createMock(EntryEndpoint::class);
        $entry->method('getRaw')->willThrowException(new \RuntimeException('fpl down'));
        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('entry')->with(3003)->willReturn($entry);

        $service = new ManagerService($this->db, $fplClient);
        $result = $service->getById(3003);

        $this->assertNull($result);
    }

    public function testGetPicksFallsBackToCachedPicksOnApiFailure(): void
    {
        $this->db->query(
            'INSERT INTO clubs (id, name, short_name) VALUES (1, ?, ?)',
            ['Arsenal', 'ARS']
        );
        $this->db->query(
            "INSERT INTO players (
                id, code, web_name, first_name, second_name, club_id, position, now_cost, total_points, form,
                selected_by_percent, minutes, goals_scored, assists, clean_sheets, expected_goals, expected_assists,
                expected_goals_conceded, ict_index, bps, bonus, starts, chance_of_playing, news, updated_at
            ) VALUES (
                99, 99, 'FallbackPlayer', '', '', 1, 3, 50, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 100, '', datetime('now')
            )"
        );
        $this->db->query(
            'INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced)
             VALUES (4004, ?, ?, ?, ?, datetime("now"))',
            ['Picks User', 'Picks FC', 1, 10]
        );
        $this->db->query(
            'INSERT INTO manager_picks (manager_id, gameweek, player_id, position, multiplier, is_captain, is_vice_captain)
             VALUES (4004, 30, 99, 1, 1, 0, 0)'
        );

        $entry = $this->createMock(EntryEndpoint::class);
        $entry->method('picks')->with(30)->willThrowException(new \RuntimeException('fpl down'));
        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('entry')->with(4004)->willReturn($entry);

        $service = new ManagerService($this->db, $fplClient);
        $result = $service->getPicks(4004, 30);

        $this->assertNotNull($result);
        $this->assertSame(true, (bool) ($result['cached'] ?? false));
        $this->assertSame(99, (int) ($result['picks'][0]['element'] ?? 0));
    }
}
