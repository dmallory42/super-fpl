<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;

class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Database(':memory:');
        $this->db->init();
    }

    public function testInsertRejectsUnknownTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name');

        $this->db->insert('not_a_real_table', ['id' => 1]);
    }

    public function testUpsertRejectsUnknownTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name');

        $this->db->upsert('totally_fake', ['id' => 1], ['id']);
    }

    public function testInsertAndUpsertAllowKnownTables(): void
    {
        $this->db->insert('clubs', [
            'id' => 10,
            'name' => 'Arsenal',
            'short_name' => 'ARS',
        ]);

        $this->db->upsert('managers', [
            'id' => 42,
            'name' => 'Manager Name',
            'team_name' => 'My XI',
            'overall_rank' => 100,
            'overall_points' => 500,
            'last_synced' => date('Y-m-d H:i:s'),
        ], ['id']);

        $club = $this->db->fetchOne('SELECT * FROM clubs WHERE id = 10');
        $manager = $this->db->fetchOne('SELECT * FROM managers WHERE id = 42');

        $this->assertNotNull($club);
        $this->assertNotNull($manager);
        $this->assertSame('ARS', (string) $club['short_name']);
        $this->assertSame('My XI', (string) $manager['team_name']);
    }
}
