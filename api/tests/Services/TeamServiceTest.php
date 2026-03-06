<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use Maia\Orm\Connection;
use Maia\Orm\Model;
use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Services\TeamService;

class TeamServiceTest extends TestCase
{
    private Connection $connection;
    private TeamService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Connection::sqlite();
        Model::setConnection($this->connection);
        $this->service = new TeamService($this->connection);

        $this->connection->execute('CREATE TABLE clubs (
            id INTEGER PRIMARY KEY,
            name TEXT,
            short_name TEXT,
            strength_attack_home INTEGER,
            strength_attack_away INTEGER,
            strength_defence_home INTEGER,
            strength_defence_away INTEGER
        )');

        $this->connection->execute(
            "INSERT INTO clubs (
                id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away
            ) VALUES (1, 'Arsenal', 'ARS', 1300, 1250, 1350, 1300)"
        );
        $this->connection->execute(
            "INSERT INTO clubs (
                id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away
            ) VALUES (2, 'Chelsea', 'CHE', 1200, 1180, 1220, 1210)"
        );
    }

    public function testGetAllReturnsTeamsOrderedById(): void
    {
        $teams = $this->service->getAll();

        $this->assertCount(2, $teams);
        $this->assertSame(1, $teams[0]['id']);
        $this->assertSame('Arsenal', $teams[0]['name']);
        $this->assertSame('CHE', $teams[1]['short_name']);
    }
}
