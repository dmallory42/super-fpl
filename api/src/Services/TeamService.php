<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;

class TeamService
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Get all teams/clubs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        return $this->db->fetchAll(
            'SELECT
                id,
                name,
                short_name,
                strength_attack_home,
                strength_attack_away,
                strength_defence_home,
                strength_defence_away
            FROM clubs
            ORDER BY id'
        );
    }
}
