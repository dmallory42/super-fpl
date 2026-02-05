<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;

class PlayerService
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Get all players with optional filters.
     *
     * @param array{position?: int, team?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAll(array $filters = []): array
    {
        $sql = 'SELECT
            id,
            code,
            web_name,
            first_name,
            second_name,
            club_id as team,
            position as element_type,
            now_cost,
            total_points,
            form,
            selected_by_percent,
            minutes,
            goals_scored,
            assists,
            clean_sheets,
            expected_goals,
            expected_assists,
            ict_index,
            bps,
            bonus,
            starts,
            chance_of_playing as chance_of_playing_next_round,
            news
        FROM players';

        $conditions = [];
        $params = [];

        if (isset($filters['position'])) {
            $conditions[] = 'position = ?';
            $params[] = $filters['position'];
        }

        if (isset($filters['team'])) {
            $conditions[] = 'club_id = ?';
            $params[] = $filters['team'];
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY total_points DESC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a single player by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT
                id,
                code,
                web_name,
                first_name,
                second_name,
                club_id as team,
                position as element_type,
                now_cost,
                total_points,
                form,
                selected_by_percent,
                minutes,
                goals_scored,
                assists,
                clean_sheets,
                expected_goals,
                expected_assists,
                ict_index,
                bps,
                bonus,
                starts,
                chance_of_playing as chance_of_playing_next_round,
                news
            FROM players WHERE id = ?',
            [$id]
        );
    }

    /**
     * @deprecated Use getAll() instead
     * @return array<int, array<string, mixed>>
     */
    public function getAllPlayers(): array
    {
        return $this->getAll();
    }

    /**
     * @deprecated Use getById() instead
     * @return array<string, mixed>|null
     */
    public function getPlayer(int $id): ?array
    {
        return $this->getById($id);
    }

    /**
     * @deprecated Use TeamService::getAll() instead
     * @return array<int, array<string, mixed>>
     */
    public function getAllTeams(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, short_name FROM clubs ORDER BY id'
        );
    }
}
