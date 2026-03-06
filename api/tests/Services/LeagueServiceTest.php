<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Tests\Support\TestDatabase;
use SuperFPL\Api\Services\LeagueService;
use SuperFPL\FplClient\Endpoints\LeagueEndpoint;
use SuperFPL\FplClient\FplClient;

class LeagueServiceTest extends TestCase
{
    private TestDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new TestDatabase(':memory:');
        $this->db->init();
    }

    public function testGetLeagueFetchesStandingsAndCachesLeagueMembers(): void
    {
        $leagueEndpoint = $this->createMock(LeagueEndpoint::class);
        $leagueEndpoint->expects($this->once())
            ->method('standings')
            ->with(2)
            ->willReturn([
                'league' => ['id' => 314, 'name' => 'Mini League', 'league_type' => 'classic'],
                'standings' => [
                    'results' => [
                        [
                            'entry' => 101,
                            'player_name' => 'Alice',
                            'entry_name' => 'Alice XI',
                            'rank' => 1,
                            'total' => 1500,
                        ],
                        [
                            'entry' => 102,
                            'player_name' => 'Bob',
                            'entry_name' => 'Bob FC',
                            'rank' => 2,
                            'total' => 1470,
                        ],
                    ],
                ],
            ]);

        $fplClient = $this->createMock(FplClient::class);
        $fplClient->expects($this->once())->method('league')->with(314)->willReturn($leagueEndpoint);

        $service = new LeagueService($this->db, $fplClient);
        $result = $service->getLeague(314, 2);

        $this->assertNotNull($result);
        $this->assertSame('Mini League', (string) ($result['league']['name'] ?? ''));
        $this->assertCount(2, $result['standings']['results'] ?? []);

        $cachedLeague = $this->db->fetchOne('SELECT * FROM leagues WHERE id = ?', [314]);
        $this->assertNotNull($cachedLeague);

        $cachedMembers = $this->db->fetchAll('SELECT manager_id, rank FROM league_members WHERE league_id = ? ORDER BY rank', [314]);
        $this->assertCount(2, $cachedMembers);
        $this->assertSame(101, (int) $cachedMembers[0]['manager_id']);
        $this->assertSame(102, (int) $cachedMembers[1]['manager_id']);
    }

    public function testGetAllStandingsCachesPaginatedResults(): void
    {
        $leagueEndpoint = $this->createMock(LeagueEndpoint::class);
        $leagueEndpoint->expects($this->once())
            ->method('getAllResults')
            ->willReturn([
                ['entry' => 201, 'player_name' => 'One', 'entry_name' => 'One XI', 'rank' => 1, 'total' => 1600],
                ['entry' => 202, 'player_name' => 'Two', 'entry_name' => 'Two XI', 'rank' => 2, 'total' => 1590],
                ['entry' => 203, 'player_name' => 'Three', 'entry_name' => 'Three XI', 'rank' => 3, 'total' => 1580],
            ]);

        $fplClient = $this->createMock(FplClient::class);
        $fplClient->expects($this->once())->method('league')->with(99)->willReturn($leagueEndpoint);

        $service = new LeagueService($this->db, $fplClient);
        $all = $service->getAllStandings(99);

        $this->assertCount(3, $all);
        $this->assertSame([201, 202, 203], array_column($all, 'entry'));

        $cached = $service->getCachedStandings(99);
        $this->assertCount(3, $cached);
        $this->assertSame([201, 202, 203], array_column($cached, 'entry'));
    }

    public function testGetLeagueFallsBackToCachedDataOnApiFailure(): void
    {
        $this->db->query(
            'INSERT INTO leagues (id, name, type, last_synced) VALUES (555, ?, ?, datetime("now"))',
            ['Cached League', 'classic']
        );
        $this->db->query(
            'INSERT INTO managers (id, name, team_name, overall_rank, overall_points, last_synced) VALUES (901, ?, ?, 1, 2000, datetime("now"))',
            ['Cached User', 'Cached XI']
        );
        $this->db->query(
            'INSERT INTO league_members (league_id, manager_id, rank) VALUES (555, 901, 1)'
        );

        $leagueEndpoint = $this->createMock(LeagueEndpoint::class);
        $leagueEndpoint->method('standings')->willThrowException(new \RuntimeException('league endpoint down'));

        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('league')->with(555)->willReturn($leagueEndpoint);

        $service = new LeagueService($this->db, $fplClient);
        $result = $service->getLeague(555, 1);

        $this->assertNotNull($result);
        $this->assertSame(true, (bool) ($result['cached'] ?? false));
        $this->assertSame('Cached League', (string) ($result['league']['name'] ?? ''));
        $this->assertCount(1, $result['standings']['results'] ?? []);
    }

    public function testInvalidLeagueIdReturnsNullWhenApiAndCacheMissing(): void
    {
        $leagueEndpoint = $this->createMock(LeagueEndpoint::class);
        $leagueEndpoint->method('standings')->willThrowException(new \RuntimeException('not found'));

        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('league')->with(999999)->willReturn($leagueEndpoint);

        $service = new LeagueService($this->db, $fplClient);
        $result = $service->getLeague(999999, 1);

        $this->assertNull($result);
    }
}
