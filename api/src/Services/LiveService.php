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

    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient,
        private readonly string $cacheDir
    ) {}

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
            return $cached;
        }

        // Fetch fresh data
        $liveData = $this->fplClient->live($gameweek)->get();

        // Enrich with player info
        $enriched = $this->enrichLiveData($liveData);

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

        return [
            'manager_id' => $managerId,
            'gameweek' => $gameweek,
            'total_points' => $totalPoints,
            'bench_points' => $benchPoints,
            'players' => $playerPoints,
            'updated_at' => date('c'),
        ];
    }

    /**
     * Get bonus point predictions based on BPS.
     *
     * @return array<int, array{player_id: int, bps: int, predicted_bonus: int}>
     */
    public function getBonusPredictions(int $gameweek): array
    {
        $liveData = $this->getLiveData($gameweek);
        $elements = $liveData['elements'] ?? [];

        // Get current fixtures
        $fixtures = $this->db->fetchAll(
            'SELECT * FROM fixtures WHERE gameweek = ?',
            [$gameweek]
        );

        $predictions = [];

        foreach ($fixtures as $fixture) {
            // Get players from both teams
            $homeClubId = $fixture['home_club_id'];
            $awayClubId = $fixture['away_club_id'];

            $fixturePlayers = [];

            foreach ($elements as $element) {
                $playerId = $element['id'] ?? 0;
                $stats = $element['stats'] ?? [];

                // Get player's club
                $player = $this->db->fetchOne(
                    'SELECT club_id FROM players WHERE id = ?',
                    [$playerId]
                );

                if ($player && in_array($player['club_id'], [$homeClubId, $awayClubId])) {
                    $bps = $stats['bps'] ?? 0;
                    if ($bps > 0) {
                        $fixturePlayers[] = [
                            'player_id' => $playerId,
                            'bps' => $bps,
                            'fixture_id' => $fixture['id'],
                        ];
                    }
                }
            }

            // Sort by BPS descending
            usort($fixturePlayers, fn($a, $b) => $b['bps'] <=> $a['bps']);

            // Assign bonus predictions (3, 2, 1 for top 3)
            foreach (array_slice($fixturePlayers, 0, 3) as $i => $player) {
                $predictions[] = [
                    'player_id' => $player['player_id'],
                    'bps' => $player['bps'],
                    'predicted_bonus' => 3 - $i,
                    'fixture_id' => $player['fixture_id'],
                ];
            }
        }

        return $predictions;
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
