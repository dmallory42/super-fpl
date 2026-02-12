<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

/**
 * Service for live gameweek data with short-lived caching.
 */
class LiveService
{
    private const CACHE_TTL = 60; // 60 seconds cache for live data
    private const RECENTLY_FINISHED_WINDOW_SECONDS = 21600; // 6 hours

    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient,
        private readonly string $cacheDir
    ) {
    }

    /**
     * Get live data for a gameweek.
     *
     * @return array<string, mixed>
     */
    public function getLiveData(int $gameweek): array
    {
        // Check cache first
        $cached = $this->getFromCache($gameweek);
        if ($cached !== null) {
            // Re-apply on cached data so provisional values stay accurate between allocations.
            $cached = $this->applyProvisionalBonusToLiveData($cached, $gameweek);
            $this->saveToCache($gameweek, $cached);
            return $cached;
        }

        // Fetch fresh data
        $liveData = $this->fplClient->live($gameweek)->get();

        // Enrich with player info
        $enriched = $this->enrichLiveData($liveData);
        $enriched = $this->applyProvisionalBonusToLiveData($enriched, $gameweek);

        // Cache the result
        $this->saveToCache($gameweek, $enriched);

        return $enriched;
    }

    /**
     * Get live points for a specific manager.
     *
     * @return array<string, mixed>
     */
    public function getManagerLivePoints(int $managerId, int $gameweek): array
    {
        // Get manager's picks
        $picks = $this->getManagerPicks($managerId, $gameweek);
        if (empty($picks)) {
            return ['error' => 'No picks found'];
        }

        // Get live data
        $liveData = $this->getLiveData($gameweek);
        $elements = $liveData['elements'] ?? [];

        // Calculate live points
        $totalPoints = 0;
        $benchPoints = 0;
        $playerPoints = [];

        foreach ($picks as $pick) {
            $playerId = $pick['element'] ?? $pick['player_id'];
            $multiplier = $pick['multiplier'] ?? 1;
            $position = $pick['position'] ?? 0;

            // Find player's live stats
            $liveStats = null;
            foreach ($elements as $element) {
                if (($element['id'] ?? 0) === $playerId) {
                    $liveStats = $element['stats'] ?? null;
                    break;
                }
            }

            $points = $liveStats['total_points'] ?? 0;
            $effectivePoints = $points * $multiplier;

            $playerPoints[] = [
                'player_id' => $playerId,
                'position' => $position,
                'multiplier' => $multiplier,
                'points' => $points,
                'effective_points' => $effectivePoints,
                'stats' => $liveStats,
                'is_playing' => $position <= 11,
                'is_captain' => $multiplier >= 2,
            ];

            if ($position <= 11) {
                $totalPoints += $effectivePoints;
            } else {
                $benchPoints += $points;
            }
        }

        // Sort by position
        usort($playerPoints, fn($a, $b) => $a['position'] <=> $b['position']);

        // Get manager's current overall rank and pre-GW rank
        $overallRank = null;
        $preGwRank = null;
        try {
            $entry = $this->fplClient->entry($managerId)->getRaw();
            $overallRank = $entry['summary_overall_rank'] ?? null;

            // Get history to find pre-GW rank (rank after previous GW)
            $history = $this->fplClient->entry($managerId)->history();
            $currentHistory = $history['current'] ?? [];
            foreach ($currentHistory as $gw) {
                if (($gw['event'] ?? 0) === $gameweek - 1) {
                    $preGwRank = $gw['overall_rank'] ?? null;
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log("LiveService: Failed to fetch rank data for manager {$managerId}: " . $e->getMessage());
        }

        return [
            'manager_id' => $managerId,
            'gameweek' => $gameweek,
            'total_points' => $totalPoints,
            'bench_points' => $benchPoints,
            'players' => $playerPoints,
            'overall_rank' => $overallRank,
            'pre_gw_rank' => $preGwRank,
            'updated_at' => date('c'),
        ];
    }

    /**
     * Get manager live data with real effective ownership from sampled data.
     *
     * @return array<string, mixed>
     */
    public function getManagerLivePointsEnhanced(
        int $managerId,
        int $gameweek,
        OwnershipService $ownershipService
    ): array {
        $baseData = $this->getManagerLivePoints($managerId, $gameweek);

        if (isset($baseData['error'])) {
            return $baseData;
        }

        // Get real EO data
        $eoData = $ownershipService->getEffectiveOwnership($gameweek, 100);
        $effectiveOwnership = $eoData['effective_ownership'] ?? [];

        // Get player info for enrichment
        $players = $this->db->fetchAll("SELECT id, web_name, club_id as team, position as element_type FROM players");
        $playerMap = [];
        foreach ($players as $p) {
            $playerMap[(int)$p['id']] = $p;
        }

        // Enrich each player with EO data
        foreach ($baseData['players'] as &$player) {
            $playerId = (int) $player['player_id'];
            $playerInfo = $playerMap[$playerId] ?? null;

            // Add player info
            if ($playerInfo) {
                $player['web_name'] = $playerInfo['web_name'];
                $player['team'] = (int) $playerInfo['team'];
                $player['element_type'] = (int) $playerInfo['element_type'];
            }

            // Add EO data
            $eo = $effectiveOwnership[$playerId] ?? null;
            if ($eo) {
                $player['effective_ownership'] = [
                    'ownership_percent' => $eo['ownership_percent'],
                    'captain_percent' => $eo['captain_percent'],
                    'effective_ownership' => $eo['effective_ownership'],
                ];

                // Calculate points swing (positive = hurts your rank, negative = helps)
                $points = $player['effective_points'] ?? 0;
                $player['effective_ownership']['points_swing'] = round(
                    $points * ($eo['effective_ownership'] / 100),
                    2
                );
            } else {
                $player['effective_ownership'] = [
                    'ownership_percent' => 0,
                    'captain_percent' => 0,
                    'effective_ownership' => 0,
                    'points_swing' => 0,
                ];
            }
        }

        // Calculate rank impact summary
        $totalSwing = 0;
        $differentialPoints = 0;

        foreach ($baseData['players'] as $player) {
            if ($player['is_playing'] ?? false) {
                $eo = $player['effective_ownership'];
                $totalSwing += $eo['points_swing'] ?? 0;

                // Differential = points from players with <10% EO
                if (($eo['effective_ownership'] ?? 0) < 10) {
                    $differentialPoints += $player['effective_points'] ?? 0;
                }
            }
        }

        $baseData['rank_impact'] = [
            'total_points_swing' => round($totalSwing, 1),
            'differential_points' => $differentialPoints,
            'template_score' => $baseData['total_points'] - $differentialPoints,
        ];

        $baseData['eo_sample_size'] = $eoData['sample_size'] ?? 0;

        return $baseData;
    }

    /**
     * Get bonus point predictions based on BPS.
     *
     * @return array<int, array{player_id: int, bps: int, predicted_bonus: int}>
     */
    public function getBonusPredictions(int $gameweek): array
    {
        // Get current fixtures
        $fixtures = $this->db->fetchAll(
            'SELECT * FROM fixtures WHERE gameweek = ?',
            [$gameweek]
        );

        return $this->calculateProvisionalBonusPredictions($gameweek, $fixtures);
    }

    /**
     * Add provisional bonus to live total points for relevant fixtures.
     *
     * @param array<string, mixed> $liveData
     * @return array<string, mixed>
     */
    private function applyProvisionalBonusToLiveData(array $liveData, int $gameweek): array
    {
        $elements = $liveData['elements'] ?? [];
        $fixtures = $this->db->fetchAll(
            'SELECT * FROM fixtures WHERE gameweek = ?',
            [$gameweek]
        );

        $predictions = $this->calculateProvisionalBonusPredictions($gameweek, $fixtures);
        $provisionalByPlayer = [];
        foreach ($predictions as $prediction) {
            $playerId = (int) ($prediction['player_id'] ?? 0);
            if ($playerId <= 0) {
                continue;
            }
            $provisionalByPlayer[$playerId] = ($provisionalByPlayer[$playerId] ?? 0)
                + (int) ($prediction['predicted_bonus'] ?? 0);
        }

        foreach ($elements as &$element) {
            $playerId = (int) ($element['id'] ?? 0);
            $stats = is_array($element['stats'] ?? null) ? $element['stats'] : [];

            $provisionalBonus = $provisionalByPlayer[$playerId] ?? 0;
            $existingProvisional = (int) ($stats['provisional_bonus'] ?? 0);
            $officialPointsFromExplain = $this->extractOfficialPointsFromExplain($element);
            $baseTotalPoints = $officialPointsFromExplain
                ?? ((int) ($stats['total_points'] ?? 0) - $existingProvisional);
            $stats['provisional_bonus'] = $provisionalBonus;
            $stats['total_points'] = $baseTotalPoints + $provisionalBonus;

            $element['provisional_bonus'] = $provisionalBonus;
            $element['stats'] = $stats;
        }
        unset($element);

        $liveData['elements'] = $elements;
        return $liveData;
    }

    /**
     * Derive official points (without provisional bonus) from explain breakdown.
     *
     * @param array<string, mixed> $element
     */
    private function extractOfficialPointsFromExplain(array $element): ?int
    {
        $explainEntries = $element['explain'] ?? null;
        if (!is_array($explainEntries) || empty($explainEntries)) {
            return null;
        }

        $total = 0;
        $foundStat = false;

        foreach ($explainEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $stats = $entry['stats'] ?? null;
            if (!is_array($stats)) {
                continue;
            }

            foreach ($stats as $stat) {
                if (!is_array($stat)) {
                    continue;
                }
                $total += (int) ($stat['points'] ?? 0);
                $foundStat = true;
            }
        }

        return $foundStat ? $total : null;
    }

    /**
     * Calculate provisional bonus predictions per fixture from fixture-level BPS.
     *
     * @param array<int, array<string, mixed>> $fixtures
     * @return array<int, array{player_id: int, bps: int, predicted_bonus: int, fixture_id: int}>
     */
    private function calculateProvisionalBonusPredictions(int $gameweek, array $fixtures): array
    {
        return $this->calculateProvisionalBonusFromFixtureStats($gameweek, $fixtures);
    }

    /**
     * Derive provisional bonus from fixture endpoint stats (fixture-level BPS).
     *
     * @param array<int, array<string, mixed>> $fixtures
     * @return array<int, array{player_id: int, bps: int, predicted_bonus: int, fixture_id: int}>
     */
    private function calculateProvisionalBonusFromFixtureStats(int $gameweek, array $fixtures): array
    {
        try {
            $rawFixtures = $this->fplClient->fixtures()->getRaw($gameweek);
        } catch (\Throwable $e) {
            error_log("LiveService: Failed to fetch fixture stats for provisional bonus GW{$gameweek}: " . $e->getMessage());
            return [];
        }

        $eligibleFixtureIds = [];
        foreach ($fixtures as $fixture) {
            if ($this->isFixtureEligibleForProvisionalBonus($fixture)) {
                $eligibleFixtureIds[(int) ($fixture['id'] ?? 0)] = true;
            }
        }

        $predictions = [];
        foreach ($rawFixtures as $rawFixture) {
            $fixtureId = (int) ($rawFixture['id'] ?? 0);
            if ($fixtureId <= 0 || !isset($eligibleFixtureIds[$fixtureId])) {
                continue;
            }

            $stats = $rawFixture['stats'] ?? null;
            if (!is_array($stats)) {
                continue;
            }

            $bpsByPlayer = $this->extractFixtureStatByPlayer($stats, 'bps');
            if (empty($bpsByPlayer)) {
                continue;
            }

            // Once official bonus appears for this fixture, provisional should be removed.
            $bonusByPlayer = $this->extractFixtureStatByPlayer($stats, 'bonus');
            if (!empty($bonusByPlayer)) {
                continue;
            }

            $fixturePlayers = [];
            foreach ($bpsByPlayer as $playerId => $bps) {
                if ($bps <= 0) {
                    continue;
                }
                $fixturePlayers[] = [
                    'player_id' => $playerId,
                    'bps' => $bps,
                    'fixture_id' => $fixtureId,
                ];
            }

            if (empty($fixturePlayers)) {
                continue;
            }

            foreach ($this->assignBonusFromBps($fixturePlayers) as $prediction) {
                $predictions[] = $prediction;
            }
        }

        return $predictions;
    }

    /**
     * Extract per-player values for a fixture stat from fixture endpoint payload.
     *
     * @param array<int, array<string, mixed>> $fixtureStats
     * @return array<int, int>
     */
    private function extractFixtureStatByPlayer(array $fixtureStats, string $identifier): array
    {
        foreach ($fixtureStats as $statRow) {
            if (!is_array($statRow) || ($statRow['identifier'] ?? '') !== $identifier) {
                continue;
            }

            $map = [];
            foreach (['h', 'a'] as $side) {
                $entries = $statRow[$side] ?? null;
                if (!is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $playerId = (int) ($entry['element'] ?? 0);
                    $value = (int) ($entry['value'] ?? 0);
                    if ($playerId <= 0 || $value <= 0) {
                        continue;
                    }
                    $map[$playerId] = $value;
                }
            }

            return $map;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function isFixtureEligibleForProvisionalBonus(array $fixture): bool
    {
        $kickoffRaw = $fixture['kickoff_time'] ?? null;
        $kickoffTs = is_string($kickoffRaw) ? strtotime($kickoffRaw) : false;
        if ($kickoffTs === false || $kickoffTs > time()) {
            return false;
        }

        $finished = !empty($fixture['finished']);
        if (!$finished) {
            return true; // In-progress fixture
        }

        // Recently finished fixture where official bonus may not be allocated yet.
        return (time() - $kickoffTs) <= self::RECENTLY_FINISHED_WINDOW_SECONDS;
    }

    /**
     * Enrich live data with player names.
     *
     * @param array<string, mixed> $liveData
     * @return array<string, mixed>
     */
    private function enrichLiveData(array $liveData): array
    {
        $elements = $liveData['elements'] ?? [];
        $enrichedElements = [];

        foreach ($elements as $element) {
            $playerId = $element['id'] ?? 0;

            // Get player info
            $player = $this->db->fetchOne(
                'SELECT web_name, club_id, position FROM players WHERE id = ?',
                [$playerId]
            );

            if ($player) {
                $element['web_name'] = $player['web_name'];
                $element['team'] = $player['club_id'];
                $element['position'] = $player['position'];
            }

            $enrichedElements[] = $element;
        }

        $liveData['elements'] = $enrichedElements;
        return $liveData;
    }

    /**
     * Assign bonus using FPL tie rules from fixture-level BPS.
     *
     * @param array<int, array{player_id: int, bps: int, fixture_id: int}> $fixturePlayers
     * @return array<int, array{player_id: int, bps: int, predicted_bonus: int, fixture_id: int}>
     */
    private function assignBonusFromBps(array $fixturePlayers): array
    {
        usort($fixturePlayers, fn($a, $b) => $b['bps'] <=> $a['bps']);

        $bonusByRank = [1 => 3, 2 => 2, 3 => 1];
        $predictions = [];
        $rank = 1;
        $seen = 0;
        $previousBps = null;

        foreach ($fixturePlayers as $player) {
            $bps = (int) ($player['bps'] ?? 0);
            if ($previousBps !== null && $bps < $previousBps) {
                $rank = $seen + 1;
            }

            $seen++;
            $previousBps = $bps;

            $bonus = $bonusByRank[$rank] ?? 0;
            if ($bonus <= 0) {
                continue;
            }

            $predictions[] = [
                'player_id' => (int) ($player['player_id'] ?? 0),
                'bps' => $bps,
                'predicted_bonus' => $bonus,
                'fixture_id' => (int) ($player['fixture_id'] ?? 0),
            ];
        }

        return $predictions;
    }

    /**
     * Get manager's picks for a gameweek.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getManagerPicks(int $managerId, int $gameweek): array
    {
        // Try cache first
        $cached = $this->db->fetchAll(
            'SELECT player_id as element, position, multiplier FROM manager_picks
            WHERE manager_id = ? AND gameweek = ?
            ORDER BY position',
            [$managerId, $gameweek]
        );

        if (!empty($cached)) {
            return $cached;
        }

        // Fetch from API
        try {
            $picksData = $this->fplClient->entry($managerId)->picks($gameweek);
            return $picksData['picks'] ?? [];
        } catch (\Throwable $e) {
            error_log("LiveService: Failed to fetch picks for manager {$managerId} GW{$gameweek}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get cached live data.
     *
     * @return array<string, mixed>|null
     */
    private function getFromCache(int $gameweek): ?array
    {
        $cacheFile = $this->getCacheFile($gameweek);

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check TTL
        if (time() - filemtime($cacheFile) > self::CACHE_TTL) {
            return null;
        }

        $data = file_get_contents($cacheFile);
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Save to cache.
     *
     * @param array<string, mixed> $data
     */
    private function saveToCache(int $gameweek, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $cacheFile = $this->getCacheFile($gameweek);
        file_put_contents($cacheFile, json_encode($data));
    }

    private function getCacheFile(int $gameweek): string
    {
        return "{$this->cacheDir}/live_gw{$gameweek}.json";
    }
}
