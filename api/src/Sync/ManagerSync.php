<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class ManagerSync
{
    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {}

    /**
     * Sync a single manager's data including current GW picks.
     *
     * @return array{manager: bool, picks: int, history: int}
     */
    public function syncManager(int $managerId): array
    {
        $result = [
            'manager' => false,
            'picks' => 0,
            'history' => 0,
        ];

        // Sync basic manager info
        try {
            $entryData = $this->fplClient->entry($managerId)->getRaw();
            $this->upsertManager($entryData);
            $result['manager'] = true;

            $currentGw = $entryData['current_event'] ?? null;

            // Sync picks for current gameweek
            if ($currentGw !== null) {
                $picksData = $this->fplClient->entry($managerId)->picks($currentGw);
                $this->syncPicks($managerId, $currentGw, $picksData);
                $result['picks'] = count($picksData['picks'] ?? []);
            }

            // Sync history
            $historyData = $this->fplClient->entry($managerId)->history();
            $result['history'] = $this->syncHistory($managerId, $historyData);
        } catch (\Throwable $e) {
            // Log error but don't fail completely
            error_log("Failed to sync manager {$managerId}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Sync all tracked managers.
     *
     * @return array{synced: int, failed: int}
     */
    public function syncAll(): array
    {
        $managers = $this->db->fetchAll('SELECT id FROM managers');

        $synced = 0;
        $failed = 0;

        foreach ($managers as $manager) {
            $result = $this->syncManager((int) $manager['id']);
            if ($result['manager']) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Sync picks for a specific gameweek.
     *
     * @param array<string, mixed> $picksData
     */
    private function syncPicks(int $managerId, int $gameweek, array $picksData): void
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

        // Also cache entry history
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
     * Sync manager history.
     *
     * @param array<string, mixed> $historyData
     */
    private function syncHistory(int $managerId, array $historyData): int
    {
        $count = 0;

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
            $count++;
        }

        return $count;
    }

    /**
     * Insert or update manager in database.
     *
     * @param array<string, mixed> $data
     */
    private function upsertManager(array $data): void
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
}
