<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class ManagerService
{
    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {
    }

    /**
     * Get manager by ID. Fetches from FPL API and caches in DB.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        // Try to fetch fresh data from FPL API
        try {
            $entryData = $this->fplClient->entry($id)->getRaw();
            $this->cacheManager($entryData);
            return $this->formatManagerResponse($entryData);
        } catch (\Throwable $e) {
            error_log("ManagerService: Failed to fetch manager {$id} from API: " . $e->getMessage());
            // Fall back to cached data if API fails
            $cached = $this->db->fetchOne(
                'SELECT * FROM managers WHERE id = ?',
                [$id]
            );

            if ($cached === null) {
                return null;
            }

            return [
                'id' => $cached['id'],
                'name' => $cached['name'],
                'team_name' => $cached['team_name'],
                'summary_overall_points' => $cached['overall_points'],
                'summary_overall_rank' => $cached['overall_rank'],
                'cached' => true,
            ];
        }
    }

    /**
     * Get manager's picks for a specific gameweek.
     *
     * @return array<string, mixed>|null
     */
    public function getPicks(int $managerId, int $gameweek): ?array
    {
        try {
            $picksData = $this->fplClient->entry($managerId)->picks($gameweek);
            $this->cacheManagerPicks($managerId, $gameweek, $picksData);
            return $picksData;
        } catch (\Throwable $e) {
            // Fall back to cached picks
            $cached = $this->getCachedPicks($managerId, $gameweek);
            if (empty($cached)) {
                return null;
            }
            return ['picks' => $cached, 'cached' => true];
        }
    }

    /**
     * Get manager's season history.
     *
     * @return array<string, mixed>|null
     */
    public function getHistory(int $managerId): ?array
    {
        try {
            $historyData = $this->fplClient->entry($managerId)->history();
            $this->cacheManagerHistory($managerId, $historyData);
            return $historyData;
        } catch (\Throwable $e) {
            // Fall back to cached history
            $cached = $this->getCachedHistory($managerId);
            if (empty($cached)) {
                return null;
            }
            return ['current' => $cached, 'cached' => true];
        }
    }

    /**
     * Track a manager (save to DB for regular syncing).
     */
    public function track(int $id): bool
    {
        $manager = $this->getById($id);
        return $manager !== null;
    }

    /**
     * Cache manager basic info in the database.
     *
     * @param array<string, mixed> $data
     */
    private function cacheManager(array $data): void
    {
        $this->db->upsert('managers', [
            'id' => $data['id'],
            'name' => ($data['player_first_name'] ?? '') . ' ' . ($data['player_last_name'] ?? ''),
            'team_name' => $data['name'] ?? '',
            'overall_rank' => $data['summary_overall_rank'] ?? null,
            'overall_points' => $data['summary_overall_points'] ?? 0,
            'last_synced' => date('Y-m-d H:i:s'),
        ], ['id']);
    }

    /**
     * Cache manager picks for a gameweek.
     *
     * @param array<string, mixed> $picksData
     */
    private function cacheManagerPicks(int $managerId, int $gameweek, array $picksData): void
    {
        // Delete existing picks for this manager/gameweek
        $this->db->query(
            'DELETE FROM manager_picks WHERE manager_id = ? AND gameweek = ?',
            [$managerId, $gameweek]
        );

        foreach ($picksData['picks'] ?? [] as $pick) {
            $this->db->insert('manager_picks', [
                'manager_id' => $managerId,
                'gameweek' => $gameweek,
                'player_id' => $pick['element'],
                'position' => $pick['position'],
                'multiplier' => $pick['multiplier'],
                'is_captain' => $pick['is_captain'] ? 1 : 0,
                'is_vice_captain' => $pick['is_vice_captain'] ? 1 : 0,
            ]);
        }

        // Cache entry history too
        if (isset($picksData['entry_history'])) {
            $history = $picksData['entry_history'];
            $this->db->upsert('manager_history', [
                'manager_id' => $managerId,
                'gameweek' => $gameweek,
                'points' => $history['points'] ?? 0,
                'total_points' => $history['total_points'] ?? 0,
                'overall_rank' => $history['overall_rank'] ?? null,
                'bank' => $history['bank'] ?? 0,
                'team_value' => $history['value'] ?? 0,
                'transfers_cost' => $history['event_transfers_cost'] ?? 0,
                'points_on_bench' => $history['points_on_bench'] ?? 0,
            ], ['manager_id', 'gameweek']);
        }
    }

    /**
     * Cache manager season history.
     *
     * @param array<string, mixed> $historyData
     */
    private function cacheManagerHistory(int $managerId, array $historyData): void
    {
        foreach ($historyData['current'] ?? [] as $entry) {
            $this->db->upsert('manager_history', [
                'manager_id' => $managerId,
                'gameweek' => $entry['event'],
                'points' => $entry['points'],
                'total_points' => $entry['total_points'],
                'overall_rank' => $entry['overall_rank'],
                'bank' => $entry['bank'],
                'team_value' => $entry['value'],
                'transfers_cost' => $entry['event_transfers_cost'],
                'points_on_bench' => $entry['points_on_bench'],
            ], ['manager_id', 'gameweek']);
        }
    }

    /**
     * Get cached picks from database.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCachedPicks(int $managerId, int $gameweek): array
    {
        return $this->db->fetchAll(
            'SELECT
                player_id as element,
                position,
                multiplier,
                is_captain,
                is_vice_captain
            FROM manager_picks
            WHERE manager_id = ? AND gameweek = ?
            ORDER BY position',
            [$managerId, $gameweek]
        );
    }

    /**
     * Get cached history from database.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCachedHistory(int $managerId): array
    {
        return $this->db->fetchAll(
            'SELECT
                gameweek as event,
                points,
                total_points,
                overall_rank,
                bank,
                team_value as value,
                transfers_cost as event_transfers_cost,
                points_on_bench
            FROM manager_history
            WHERE manager_id = ?
            ORDER BY gameweek',
            [$managerId]
        );
    }

    /**
     * Format the manager response to match FPL API structure.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function formatManagerResponse(array $data): array
    {
        return $data;
    }
}
