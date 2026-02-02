<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

/**
 * Service for calculating effective ownership and captain percentages
 * by sampling top managers from FPL.
 */
class OwnershipService
{
    private const OVERALL_LEAGUE_ID = 314; // Main FPL overall league
    private const DEFAULT_SAMPLE_SIZE = 50; // Managers to sample per page
    private const CACHE_TTL = 3600; // 1 hour cache

    public function __construct(
        private Database $db,
        private FplClient $fplClient,
        private string $cacheDir
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get captain percentages for a gameweek by sampling top managers.
     *
     * @param int $gameweek The gameweek to analyze
     * @param int $sampleSize Number of managers to sample (from top of overall standings)
     * @return array{
     *   gameweek: int,
     *   sample_size: int,
     *   captains: array<int, array{player_id: int, captain_count: int, captain_percent: float, vice_count: int}>,
     *   ownership: array<int, array{player_id: int, owned_count: int, owned_percent: float}>
     * }
     */
    public function getCaptainPercentages(int $gameweek, int $sampleSize = 100): array
    {
        $cacheKey = "captain_percentages_{$gameweek}_{$sampleSize}";
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Get top managers from overall standings
        $topManagers = $this->getTopManagers($sampleSize);

        $captainCounts = [];
        $viceCaptainCounts = [];
        $ownershipCounts = [];
        $successfulSamples = 0;

        foreach ($topManagers as $manager) {
            $picks = $this->getManagerPicks($manager['entry'], $gameweek);
            if ($picks === null) {
                continue;
            }

            $successfulSamples++;

            foreach ($picks as $pick) {
                $playerId = (int) $pick['element'];

                // Count ownership
                if (!isset($ownershipCounts[$playerId])) {
                    $ownershipCounts[$playerId] = 0;
                }
                $ownershipCounts[$playerId]++;

                // Count captains (multiplier = 2 or 3 for TC)
                if ($pick['is_captain'] ?? false) {
                    if (!isset($captainCounts[$playerId])) {
                        $captainCounts[$playerId] = 0;
                    }
                    $captainCounts[$playerId]++;
                }

                // Count vice captains
                if ($pick['is_vice_captain'] ?? false) {
                    if (!isset($viceCaptainCounts[$playerId])) {
                        $viceCaptainCounts[$playerId] = 0;
                    }
                    $viceCaptainCounts[$playerId]++;
                }
            }
        }

        if ($successfulSamples === 0) {
            return [
                'gameweek' => $gameweek,
                'sample_size' => 0,
                'captains' => [],
                'ownership' => [],
            ];
        }

        // Calculate percentages
        $captains = [];
        foreach ($captainCounts as $playerId => $count) {
            $captains[$playerId] = [
                'player_id' => $playerId,
                'captain_count' => $count,
                'captain_percent' => round(($count / $successfulSamples) * 100, 2),
                'vice_count' => $viceCaptainCounts[$playerId] ?? 0,
            ];
        }

        // Sort by captain count descending
        uasort($captains, fn($a, $b) => $b['captain_count'] <=> $a['captain_count']);

        $ownership = [];
        foreach ($ownershipCounts as $playerId => $count) {
            $ownership[$playerId] = [
                'player_id' => $playerId,
                'owned_count' => $count,
                'owned_percent' => round(($count / $successfulSamples) * 100, 2),
            ];
        }

        $result = [
            'gameweek' => $gameweek,
            'sample_size' => $successfulSamples,
            'captains' => $captains,
            'ownership' => $ownership,
        ];

        $this->saveToCache($cacheKey, $result);

        return $result;
    }

    /**
     * Calculate effective ownership for all players.
     * EO = ownership% + captain% (since captain doubles points)
     *
     * For more accurate EO:
     * EO = ownership% * 1 + (captain% * 1) = ownership% + captain%
     * Because captains get 2x, owning + captaining = base ownership + extra from captain
     */
    public function getEffectiveOwnership(int $gameweek, int $sampleSize = 100): array
    {
        $data = $this->getCaptainPercentages($gameweek, $sampleSize);

        $effectiveOwnership = [];

        foreach ($data['ownership'] as $playerId => $ownershipData) {
            $captainData = $data['captains'][$playerId] ?? null;
            $captainPercent = $captainData ? $captainData['captain_percent'] : 0;

            // EO = ownership + captain (since captain gives 2x, extra = captain%)
            // More precisely: EO = (owners who didn't captain * 1) + (captainers * 2)
            // = ownership - captain + captain*2 = ownership + captain
            $eo = $ownershipData['owned_percent'] + $captainPercent;

            $effectiveOwnership[$playerId] = [
                'player_id' => $playerId,
                'ownership_percent' => $ownershipData['owned_percent'],
                'captain_percent' => $captainPercent,
                'effective_ownership' => round($eo, 2),
            ];
        }

        // Sort by EO descending
        uasort($effectiveOwnership, fn($a, $b) => $b['effective_ownership'] <=> $a['effective_ownership']);

        return [
            'gameweek' => $gameweek,
            'sample_size' => $data['sample_size'],
            'sample_type' => 'top_overall',
            'effective_ownership' => $effectiveOwnership,
        ];
    }

    /**
     * Get top managers from overall standings.
     */
    private function getTopManagers(int $count): array
    {
        $managers = [];
        $page = 1;
        $perPage = 50; // FPL returns 50 per page

        while (count($managers) < $count) {
            $standings = $this->fplClient->league(self::OVERALL_LEAGUE_ID)->standings($page);

            if (!isset($standings['standings']['results']) || empty($standings['standings']['results'])) {
                break;
            }

            foreach ($standings['standings']['results'] as $manager) {
                $managers[] = $manager;
                if (count($managers) >= $count) {
                    break;
                }
            }

            $page++;

            // Safety limit
            if ($page > 10) {
                break;
            }
        }

        return $managers;
    }

    /**
     * Get a manager's picks for a gameweek.
     */
    private function getManagerPicks(int $managerId, int $gameweek): ?array
    {
        try {
            $picks = $this->fplClient->entry($managerId)->picks($gameweek);
            return $picks['picks'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getFromCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        if (!file_exists($file)) {
            return null;
        }

        $mtime = filemtime($file);
        if (time() - $mtime > self::CACHE_TTL) {
            unlink($file);
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function saveToCache(string $key, array $data): void
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        file_put_contents($file, json_encode($data));
    }
}
