<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;

class FixtureService
{
    public function __construct(
        private readonly Database $db
    ) {}

    /**
     * Get all fixtures, optionally filtered by gameweek.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?int $gameweek = null): array
    {
        $sql = 'SELECT
            id,
            gameweek,
            home_club_id,
            away_club_id,
            kickoff_time,
            home_score,
            away_score,
            home_difficulty,
            away_difficulty,
            finished
        FROM fixtures';

        $params = [];

        if ($gameweek !== null) {
            $sql .= ' WHERE gameweek = ?';
            $params[] = $gameweek;
        }

        $sql .= ' ORDER BY kickoff_time ASC';

        return $this->db->fetchAll($sql, $params);
    }
}
