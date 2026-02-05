<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class LeagueService
{
    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {
    }

    /**
     * Get league info and standings, auto-caching members.
     *
     * @return array<string, mixed>|null
     */
    public function getLeague(int $leagueId, int $page = 1): ?array
    {
        try {
            $data = $this->fplClient->league($leagueId)->standings($page);

            // Cache the league
            $this->cacheLeague($leagueId, $data['league'] ?? []);

            // Cache members (managers) from standings
            $this->cacheLeagueMembers($leagueId, $data['standings']['results'] ?? []);

            return $data;
        } catch (\Throwable $e) {
            // Fall back to cached data
            return $this->getCachedLeague($leagueId);
        }
    }

    /**
     * Get all standings for a league (all pages).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllStandings(int $leagueId): array
    {
        try {
            $allResults = $this->fplClient->league($leagueId)->getAllResults();

            // Cache all members
            foreach (array_chunk($allResults, 50) as $chunk) {
                $this->cacheLeagueMembers($leagueId, $chunk);
            }

            return $allResults;
        } catch (\Throwable $e) {
            // Fall back to cached data
            return $this->getCachedStandings($leagueId);
        }
    }

    /**
     * Get cached league info.
     *
     * @return array<string, mixed>|null
     */
    public function getCachedLeague(int $leagueId): ?array
    {
        $league = $this->db->fetchOne(
            'SELECT * FROM leagues WHERE id = ?',
            [$leagueId]
        );

        if ($league === null) {
            return null;
        }

        // Get standings from cached members
        $standings = $this->getCachedStandings($leagueId);

        return [
            'league' => [
                'id' => $league['id'],
                'name' => $league['name'],
                'type' => $league['type'],
            ],
            'standings' => [
                'results' => $standings,
            ],
            'cached' => true,
        ];
    }

    /**
     * Get cached standings for a league.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCachedStandings(int $leagueId): array
    {
        return $this->db->fetchAll(
            "SELECT
                lm.manager_id as entry,
                lm.rank,
                m.name as player_name,
                m.team_name as entry_name,
                m.overall_points as total,
                m.overall_rank
            FROM league_members lm
            JOIN managers m ON lm.manager_id = m.id
            WHERE lm.league_id = ?
            ORDER BY lm.rank",
            [$leagueId]
        );
    }

    /**
     * Get all managers in a league (for comparison).
     *
     * @return array<int, int> Manager IDs
     */
    public function getLeagueMemberIds(int $leagueId): array
    {
        $members = $this->db->fetchAll(
            'SELECT manager_id FROM league_members WHERE league_id = ?',
            [$leagueId]
        );

        return array_column($members, 'manager_id');
    }

    /**
     * Cache league info.
     *
     * @param array<string, mixed> $leagueData
     */
    private function cacheLeague(int $leagueId, array $leagueData): void
    {
        $this->db->upsert('leagues', [
            'id' => $leagueId,
            'name' => $leagueData['name'] ?? '',
            'type' => $leagueData['league_type'] ?? 'classic',
            'last_synced' => date('Y-m-d H:i:s'),
        ], ['id']);
    }

    /**
     * Cache league members and their manager data.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private function cacheLeagueMembers(int $leagueId, array $results): void
    {
        foreach ($results as $result) {
            $managerId = $result['entry'] ?? 0;
            if ($managerId === 0) {
                continue;
            }

            // Cache manager
            $this->db->upsert('managers', [
                'id' => $managerId,
                'name' => $result['player_name'] ?? '',
                'team_name' => $result['entry_name'] ?? '',
                'overall_rank' => $result['rank'] ?? null,
                'overall_points' => $result['total'] ?? 0,
                'last_synced' => date('Y-m-d H:i:s'),
            ], ['id']);

            // Cache league membership
            $this->db->upsert('league_members', [
                'league_id' => $leagueId,
                'manager_id' => $managerId,
                'rank' => $result['rank'] ?? 0,
            ], ['league_id', 'manager_id']);
        }
    }
}
