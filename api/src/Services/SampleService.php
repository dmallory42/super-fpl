<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

/**
 * Service for sampling managers and calculating effective ownership across tiers.
 */
class SampleService
{
    private const TIERS = [
        'top_10k' => ['min_rank' => 1, 'max_rank' => 10000],
        'top_100k' => ['min_rank' => 1, 'max_rank' => 100000],
        'top_1m' => ['min_rank' => 1, 'max_rank' => 1000000],
        'overall' => ['min_rank' => 1, 'max_rank' => 10000000],
    ];

    private const SAMPLE_SIZE = 2000;
    private const CACHE_TTL = 300; // 5 minutes for computed data

    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient,
        private readonly string $cacheDir
    ) {}

    /**
     * Get sample data for a gameweek including EO and average points.
     *
     * @return array<string, mixed>
     */
    public function getSampleData(int $gameweek, array $liveElements): array
    {
        $result = [
            'gameweek' => $gameweek,
            'samples' => [],
            'updated_at' => date('c'),
        ];

        // Build live points lookup
        $livePoints = [];
        foreach ($liveElements as $element) {
            $livePoints[$element['id']] = $element['stats']['total_points'] ?? 0;
        }

        foreach (array_keys(self::TIERS) as $tier) {
            $sampleData = $this->getTierData($gameweek, $tier, $livePoints);
            if ($sampleData) {
                $result['samples'][$tier] = $sampleData;
            }
        }

        return $result;
    }

    /**
     * Get data for a specific tier.
     *
     * @return array<string, mixed>|null
     */
    private function getTierData(int $gameweek, string $tier, array $livePoints): ?array
    {
        // Get cached picks for this tier
        $picks = $this->db->fetchAll(
            'SELECT player_id, multiplier, manager_id FROM sample_picks
             WHERE gameweek = ? AND tier = ?',
            [$gameweek, $tier]
        );

        if (empty($picks)) {
            return null;
        }

        // Group by manager
        $managerPicks = [];
        foreach ($picks as $pick) {
            $managerId = $pick['manager_id'];
            if (!isset($managerPicks[$managerId])) {
                $managerPicks[$managerId] = [];
            }
            $managerPicks[$managerId][] = $pick;
        }

        $sampleSize = count($managerPicks);

        // Calculate effective ownership
        $playerOwnership = [];
        $playerCaptaincy = [];

        foreach ($picks as $pick) {
            $playerId = $pick['player_id'];
            $multiplier = (int) $pick['multiplier'];

            if (!isset($playerOwnership[$playerId])) {
                $playerOwnership[$playerId] = 0;
                $playerCaptaincy[$playerId] = 0;
            }

            $playerOwnership[$playerId]++;
            if ($multiplier >= 2) {
                $playerCaptaincy[$playerId] += ($multiplier - 1); // Captain = +1, TC = +2
            }
        }

        // Calculate EO percentages
        $effectiveOwnership = [];
        foreach ($playerOwnership as $playerId => $count) {
            $ownershipPct = ($count / $sampleSize) * 100;
            $captainPct = (($playerCaptaincy[$playerId] ?? 0) / $sampleSize) * 100;
            $effectiveOwnership[$playerId] = round($ownershipPct + $captainPct, 1);
        }

        // Calculate average points
        $totalPoints = 0;
        foreach ($managerPicks as $picks) {
            $managerPoints = 0;
            foreach ($picks as $pick) {
                $playerId = $pick['player_id'];
                $multiplier = (int) $pick['multiplier'];
                $points = $livePoints[$playerId] ?? 0;
                $managerPoints += $points * $multiplier;
            }
            $totalPoints += $managerPoints;
        }

        $avgPoints = $sampleSize > 0 ? round($totalPoints / $sampleSize, 1) : 0;

        return [
            'avg_points' => $avgPoints,
            'sample_size' => $sampleSize,
            'effective_ownership' => $effectiveOwnership,
        ];
    }

    /**
     * Sample managers for a gameweek and store their picks.
     * Should be called 75 minutes after GW deadline.
     */
    public function sampleManagersForGameweek(int $gameweek): array
    {
        $results = [];

        foreach (self::TIERS as $tier => $rankRange) {
            $count = $this->sampleTier($gameweek, $tier, $rankRange);
            $results[$tier] = $count;
        }

        return $results;
    }

    /**
     * Sample managers for a specific tier using the overall standings API.
     */
    private function sampleTier(int $gameweek, string $tier, array $rankRange): int
    {
        // Clear existing samples for this tier/gameweek
        $this->db->query(
            'DELETE FROM sample_picks WHERE gameweek = ? AND tier = ?',
            [$gameweek, $tier]
        );

        $minRank = $rankRange['min_rank'];
        $maxRank = $rankRange['max_rank'];

        // Get manager IDs from overall standings at random pages within rank range
        $managerIds = $this->getManagerIdsByRank($minRank, $maxRank, self::SAMPLE_SIZE);

        $sampled = 0;
        foreach ($managerIds as $entryId) {
            try {
                $picks = $this->fplClient->entry($entryId)->picks($gameweek);

                if (!empty($picks['picks'])) {
                    $this->storeManagerPicks($gameweek, $tier, $entryId, $picks['picks']);
                    $sampled++;
                }
            } catch (\Throwable $e) {
                // Entry doesn't exist or API error, continue
                continue;
            }

            // Rate limiting - be gentle with FPL API
            if ($sampled % 50 === 0) {
                usleep(200000); // 200ms pause every 50 requests
            }
        }

        return $sampled;
    }

    /**
     * Get manager IDs from overall standings within a rank range.
     * Uses the overall league (ID 314) standings API.
     *
     * @return int[]
     */
    private function getManagerIdsByRank(int $minRank, int $maxRank, int $count): array
    {
        $managerIds = [];
        $pageSize = 50; // FPL returns 50 results per page

        // Calculate which pages contain managers in our rank range
        $startPage = (int) ceil($minRank / $pageSize);
        $endPage = (int) ceil($maxRank / $pageSize);

        // Sample random pages within the range to get diverse managers
        $pagesToSample = min($count / 10, $endPage - $startPage + 1); // ~10 managers per page sample
        $sampledPages = [];

        for ($i = 0; $i < $pagesToSample && count($managerIds) < $count; $i++) {
            // Pick a random page in range
            $page = random_int($startPage, $endPage);

            // Avoid re-sampling same page
            if (in_array($page, $sampledPages)) {
                continue;
            }
            $sampledPages[] = $page;

            try {
                // Use overall league standings (league 314 is the overall FPL league)
                $standings = $this->fplClient->league(314)->standings($page);
                $results = $standings['standings']['results'] ?? [];

                foreach ($results as $result) {
                    $rank = $result['rank'] ?? 0;
                    if ($rank >= $minRank && $rank <= $maxRank) {
                        $managerIds[] = $result['entry'];
                    }

                    if (count($managerIds) >= $count) {
                        break 2;
                    }
                }
            } catch (\Throwable $e) {
                // API error, continue with other pages
                continue;
            }

            // Rate limit standings requests
            usleep(100000); // 100ms between page requests
        }

        // Shuffle to randomize order
        shuffle($managerIds);

        return array_slice($managerIds, 0, $count);
    }

    /**
     * Store manager picks for sampling.
     */
    private function storeManagerPicks(int $gameweek, string $tier, int $managerId, array $picks): void
    {
        foreach ($picks as $pick) {
            // Only store starting XI (position 1-11) for EO calculation
            if (($pick['position'] ?? 0) > 11) {
                continue;
            }

            $this->db->query(
                'INSERT OR IGNORE INTO sample_picks (gameweek, tier, manager_id, player_id, multiplier)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $gameweek,
                    $tier,
                    $managerId,
                    $pick['element'],
                    $pick['multiplier'],
                ]
            );
        }
    }

    /**
     * Check if samples exist for a gameweek.
     */
    public function hasSamplesForGameweek(int $gameweek): bool
    {
        $count = $this->db->fetchOne(
            'SELECT COUNT(DISTINCT manager_id) as cnt FROM sample_picks WHERE gameweek = ?',
            [$gameweek]
        );

        return ($count['cnt'] ?? 0) > 0;
    }

    /**
     * Get the tiers available.
     *
     * @return string[]
     */
    public static function getTiers(): array
    {
        return array_keys(self::TIERS);
    }
}
