<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class ManagerSeasonAnalysisService
{
    /** @var array<int, array<int, float>> */
    private array $expectedPointCache = [];

    /** @var array<int, array<int, float>> */
    private array $actualPointCache = [];

    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function analyze(int $managerId): ?array
    {
        // Reset caches so consecutive calls for different managers don't leak
        $this->expectedPointCache = [];
        $this->actualPointCache = [];

        $managerService = new ManagerService($this->db, $this->fplClient);
        $history = $managerService->getHistory($managerId);

        if ($history === null || empty($history['current']) || !is_array($history['current'])) {
            return null;
        }

        $chipsByGw = $this->groupChipsByGameweek($history['chips'] ?? []);
        $transfersByGw = $this->groupTransfersByGameweek($managerId);

        // Collect all player IDs from picks and transfers for bulk preload
        $allPlayerIds = [];

        foreach ($history['current'] as $gwEntry) {
            $gw = (int) ($gwEntry['event'] ?? 0);
            if ($gw <= 0) {
                continue;
            }
            $picks = $this->getManagerPicks($managerId, $gw);
            foreach ($picks as $pick) {
                $pid = (int) ($pick['element'] ?? 0);
                if ($pid > 0) {
                    $allPlayerIds[$pid] = true;
                }
            }
        }

        foreach ($transfersByGw as $transfers) {
            foreach ($transfers as $t) {
                $in = (int) ($t['element_in'] ?? 0);
                $out = (int) ($t['element_out'] ?? 0);
                if ($in > 0) {
                    $allPlayerIds[$in] = true;
                }
                if ($out > 0) {
                    $allPlayerIds[$out] = true;
                }
            }
        }

        $this->preloadPredictions(array_keys($allPlayerIds));
        $this->preloadActuals(array_keys($allPlayerIds));

        $gameweeks = [];
        $transferAnalytics = [];
        $captainTotals = [
            'actual_gain' => 0.0,
            'expected_gain' => 0.0,
            'luck_delta' => 0.0,
        ];

        foreach ($history['current'] as $gwEntry) {
            $gw = (int) ($gwEntry['event'] ?? 0);
            if ($gw <= 0) {
                continue;
            }

            $actualPoints = round((float) ($gwEntry['points'] ?? 0), 2);
            $expectedPoints = $this->calculateExpectedPoints($managerId, $gw);
            $luckDelta = round($actualPoints - $expectedPoints, 2);

            $captain = $this->calculateCaptainImpact($managerId, $gw);
            $captainTotals['actual_gain'] += $captain['actual_gain'];
            $captainTotals['expected_gain'] += $captain['expected_gain'];
            $captainTotals['luck_delta'] += $captain['luck_delta'];

            $eventTransfers = (int) ($gwEntry['event_transfers'] ?? count($transfersByGw[$gw] ?? []));
            $eventTransferCost = (int) ($gwEntry['event_transfers_cost'] ?? 0);

            if ($eventTransfers > 0) {
                $transferMetrics = $this->calculateTransferMetricsForGameweek($gw, $transfersByGw[$gw] ?? []);
                $transferAnalytics[] = [
                    'gameweek' => $gw,
                    'transfer_count' => $eventTransfers,
                    'transfer_cost' => $eventTransferCost,
                    'foresight_gain' => $transferMetrics['foresight_gain'],
                    'hindsight_gain' => $transferMetrics['hindsight_gain'],
                    'net_gain' => round($transferMetrics['hindsight_gain'] - $eventTransferCost, 2),
                ];
            }

            $chips = $chipsByGw[$gw] ?? [];
            $gameweeks[] = [
                'gameweek' => $gw,
                'actual_points' => $actualPoints,
                'expected_points' => $expectedPoints,
                'luck_delta' => $luckDelta,
                'overall_rank' => isset($gwEntry['overall_rank']) ? (int) $gwEntry['overall_rank'] : null,
                'event_transfers' => $eventTransfers,
                'event_transfers_cost' => $eventTransferCost,
                'captain_impact' => $captain,
                'chip_impact' => [
                    'chips' => $chips,
                    'active' => $chips[0] ?? null,
                ],
            ];
        }

        usort($gameweeks, static fn(array $a, array $b) => $a['gameweek'] <=> $b['gameweek']);
        usort($transferAnalytics, static fn(array $a, array $b) => $a['gameweek'] <=> $b['gameweek']);

        $summary = [
            'actual_points' => round(array_sum(array_column($gameweeks, 'actual_points')), 2),
            'expected_points' => round(array_sum(array_column($gameweeks, 'expected_points')), 2),
            'luck_delta' => round(array_sum(array_column($gameweeks, 'luck_delta')), 2),
            'captain_actual_gain' => round($captainTotals['actual_gain'], 2),
            'captain_expected_gain' => round($captainTotals['expected_gain'], 2),
            'captain_luck_delta' => round($captainTotals['luck_delta'], 2),
            'transfer_foresight_gain' => round(array_sum(array_column($transferAnalytics, 'foresight_gain')), 2),
            'transfer_hindsight_gain' => round(array_sum(array_column($transferAnalytics, 'hindsight_gain')), 2),
            'transfer_net_gain' => round(array_sum(array_column($transferAnalytics, 'net_gain')), 2),
        ];
        $benchmarkSeries = $this->buildBenchmarkSeries($gameweeks);

        return [
            'manager_id' => $managerId,
            'generated_at' => date('c'),
            'gameweeks' => $gameweeks,
            'transfer_analytics' => $transferAnalytics,
            'summary' => $summary,
            'benchmarks' => $benchmarkSeries,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $transfers
     * @return array<string, float>
     */
    private function calculateTransferMetricsForGameweek(int $gw, array $transfers): array
    {
        $foresightGain = 0.0;
        $hindsightGain = 0.0;

        foreach ($transfers as $transfer) {
            $inPlayerId = (int) ($transfer['element_in'] ?? 0);
            $outPlayerId = (int) ($transfer['element_out'] ?? 0);

            if ($inPlayerId <= 0 || $outPlayerId <= 0) {
                continue;
            }

            $foresightGain += $this->getPredictedPoints($inPlayerId, $gw) - $this->getPredictedPoints($outPlayerId, $gw);
            $hindsightGain += $this->getActualPlayerPoints($inPlayerId, $gw) - $this->getActualPlayerPoints($outPlayerId, $gw);
        }

        return [
            'foresight_gain' => round($foresightGain, 2),
            'hindsight_gain' => round($hindsightGain, 2),
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    private function calculateCaptainImpact(int $managerId, int $gw): array
    {
        $picks = $this->getManagerPicks($managerId, $gw);
        $captain = null;

        foreach ($picks as $pick) {
            if ((bool) ($pick['is_captain'] ?? false)) {
                $captain = $pick;
                break;
            }
        }

        if ($captain === null) {
            return [
                'captain_id' => null,
                'multiplier' => 1,
                'actual_gain' => 0.0,
                'expected_gain' => 0.0,
                'luck_delta' => 0.0,
            ];
        }

        $playerId = (int) ($captain['element'] ?? 0);
        $multiplier = max(1, (int) ($captain['multiplier'] ?? 1));
        $bonusMultiplier = $multiplier - 1;

        $actualGain = $this->getActualPlayerPoints($playerId, $gw) * $bonusMultiplier;
        $expectedGain = $this->getPredictedPoints($playerId, $gw) * $bonusMultiplier;

        return [
            'captain_id' => $playerId,
            'multiplier' => $multiplier,
            'actual_gain' => round($actualGain, 2),
            'expected_gain' => round($expectedGain, 2),
            'luck_delta' => round($actualGain - $expectedGain, 2),
        ];
    }

    private function calculateExpectedPoints(int $managerId, int $gw): float
    {
        $picks = $this->getManagerPicks($managerId, $gw);
        if (empty($picks)) {
            return 0.0;
        }

        $expected = 0.0;

        foreach ($picks as $pick) {
            $multiplier = (int) ($pick['multiplier'] ?? 0);
            if ($multiplier <= 0) {
                continue;
            }

            $playerId = (int) ($pick['element'] ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            $expected += $this->getPredictedPoints($playerId, $gw) * $multiplier;
        }

        return round($expected, 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getManagerPicks(int $managerId, int $gw): array
    {
        $cached = $this->db->fetchAll(
            'SELECT
                player_id as element,
                multiplier,
                is_captain,
                is_vice_captain
            FROM manager_picks
            WHERE manager_id = ? AND gameweek = ?',
            [$managerId, $gw]
        );

        if (!empty($cached)) {
            return $cached;
        }

        try {
            $picksData = $this->fplClient->entry($managerId)->picks($gw);
            if (isset($picksData['picks']) && is_array($picksData['picks'])) {
                $this->cacheManagerPicks($managerId, $gw, $picksData['picks']);
                return $picksData['picks'];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $picks
     */
    private function cacheManagerPicks(int $managerId, int $gw, array $picks): void
    {
        $this->db->query(
            'DELETE FROM manager_picks WHERE manager_id = ? AND gameweek = ?',
            [$managerId, $gw]
        );

        foreach ($picks as $pick) {
            $playerId = (int) ($pick['element'] ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            $this->db->insert('manager_picks', [
                'manager_id' => $managerId,
                'gameweek' => $gw,
                'player_id' => $playerId,
                'position' => (int) ($pick['position'] ?? 0),
                'multiplier' => (int) ($pick['multiplier'] ?? 0),
                'is_captain' => !empty($pick['is_captain']) ? 1 : 0,
                'is_vice_captain' => !empty($pick['is_vice_captain']) ? 1 : 0,
            ]);
        }
    }

    private function getPredictedPoints(int $playerId, int $gw): float
    {
        if (isset($this->expectedPointCache[$gw][$playerId])) {
            return $this->expectedPointCache[$gw][$playerId];
        }

        $row = $this->db->fetchOne(
            'SELECT predicted_points FROM prediction_snapshots WHERE player_id = ? AND gameweek = ?',
            [$playerId, $gw]
        );

        if ($row === null) {
            $fallback = $this->db->fetchOne(
                'SELECT
                    predicted_points,
                    predicted_if_fit,
                    expected_mins,
                    expected_mins_if_fit
                 FROM player_predictions
                 WHERE player_id = ? AND gameweek = ?',
                [$playerId, $gw]
            );

            $value = (float) ($fallback['predicted_points'] ?? 0);
            $predictedIfFit = isset($fallback['predicted_if_fit']) ? (float) $fallback['predicted_if_fit'] : null;
            $expectedMins = isset($fallback['expected_mins']) ? (float) $fallback['expected_mins'] : null;
            $expectedMinsIfFit = isset($fallback['expected_mins_if_fit']) ? (float) $fallback['expected_mins_if_fit'] : null;

            // Historical fallback: if a player is effectively hard-zeroed by current availability
            // (but has meaningful if-fit projection), use if-fit to avoid post-hoc injury distortion.
            if (
                $predictedIfFit !== null
                && (
                    $value <= 0.05
                    || (
                        $expectedMins !== null
                        && $expectedMinsIfFit !== null
                        && $expectedMins < 15.0
                        && $expectedMinsIfFit >= 45.0
                    )
                )
            ) {
                $value = $predictedIfFit;
            }

            $this->expectedPointCache[$gw][$playerId] = $value;
            return $value;
        }

        $value = (float) ($row['predicted_points'] ?? 0);
        $this->expectedPointCache[$gw][$playerId] = $value;

        return $value;
    }

    private function getActualPlayerPoints(int $playerId, int $gw): float
    {
        if (isset($this->actualPointCache[$gw][$playerId])) {
            return $this->actualPointCache[$gw][$playerId];
        }

        $row = $this->db->fetchOne(
            'SELECT total_points FROM player_gameweek_history WHERE player_id = ? AND gameweek = ?',
            [$playerId, $gw]
        );

        $value = (float) ($row['total_points'] ?? 0);
        $this->actualPointCache[$gw][$playerId] = $value;

        return $value;
    }

    /**
     * Preload all predicted points for a set of player IDs across all gameweeks.
     * Populates $this->expectedPointCache to avoid per-player queries.
     *
     * @param array<int, int> $playerIds
     */
    private function preloadPredictions(array $playerIds): void
    {
        if (empty($playerIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));

        // Load from snapshots first (preferred source)
        $snapshots = $this->db->fetchAll(
            "SELECT player_id, gameweek, predicted_points FROM prediction_snapshots WHERE player_id IN ($placeholders)",
            $playerIds
        );
        foreach ($snapshots as $row) {
            $this->expectedPointCache[(int) $row['gameweek']][(int) $row['player_id']] = (float) $row['predicted_points'];
        }

        // Load fallback predictions for any gaps
        $predictions = $this->db->fetchAll(
            "SELECT player_id, gameweek, predicted_points, predicted_if_fit, expected_mins, expected_mins_if_fit
             FROM player_predictions WHERE player_id IN ($placeholders)",
            $playerIds
        );
        foreach ($predictions as $row) {
            $gw = (int) $row['gameweek'];
            $pid = (int) $row['player_id'];
            if (isset($this->expectedPointCache[$gw][$pid])) {
                continue; // Snapshot takes priority
            }

            $value = (float) ($row['predicted_points'] ?? 0);
            $predictedIfFit = isset($row['predicted_if_fit']) ? (float) $row['predicted_if_fit'] : null;
            $expectedMins = isset($row['expected_mins']) ? (float) $row['expected_mins'] : null;
            $expectedMinsIfFit = isset($row['expected_mins_if_fit']) ? (float) $row['expected_mins_if_fit'] : null;

            // Apply the same if-fit fallback logic as getPredictedPoints()
            if (
                $predictedIfFit !== null
                && (
                    $value <= 0.05
                    || (
                        $expectedMins !== null
                        && $expectedMinsIfFit !== null
                        && $expectedMins < 15.0
                        && $expectedMinsIfFit >= 45.0
                    )
                )
            ) {
                $value = $predictedIfFit;
            }

            $this->expectedPointCache[$gw][$pid] = $value;
        }
    }

    /**
     * Preload all actual points for a set of player IDs across all gameweeks.
     * Populates $this->actualPointCache to avoid per-player queries.
     *
     * @param array<int, int> $playerIds
     */
    private function preloadActuals(array $playerIds): void
    {
        if (empty($playerIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT player_id, gameweek, total_points FROM player_gameweek_history WHERE player_id IN ($placeholders)",
            $playerIds
        );
        foreach ($rows as $row) {
            $this->actualPointCache[(int) $row['gameweek']][(int) $row['player_id']] = (float) $row['total_points'];
        }
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function groupTransfersByGameweek(int $managerId): array
    {
        try {
            $transfers = $this->fplClient->entry($managerId)->transfers();
        } catch (\Throwable) {
            return [];
        }

        $grouped = [];
        foreach ($transfers as $transfer) {
            $gw = (int) ($transfer['event'] ?? 0);
            if ($gw <= 0) {
                continue;
            }
            $grouped[$gw][] = $transfer;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<string, mixed>> $chips
     * @return array<int, array<int, string>>
     */
    private function groupChipsByGameweek(array $chips): array
    {
        $chipsByGw = [];
        foreach ($chips as $chip) {
            $gw = (int) ($chip['event'] ?? 0);
            if ($gw <= 0) {
                continue;
            }
            $chipsByGw[$gw][] = (string) ($chip['name'] ?? 'unknown');
        }
        return $chipsByGw;
    }

    /**
     * @param array<int, array<string, mixed>> $gameweeks
     * @return array<string, array<int, array<string, float|int|null>>>
     */
    private function buildBenchmarkSeries(array $gameweeks): array
    {
        $gws = array_values(array_unique(array_map(
            static fn(array $row): int => (int) ($row['gameweek'] ?? 0),
            $gameweeks
        )));
        $gws = array_values(array_filter($gws, static fn(int $gw): bool => $gw > 0));

        if (empty($gws)) {
            return [
                'overall' => [],
                'top_10k' => [],
            ];
        }

        $placeholders = implode(',', array_fill(0, count($gws), '?'));
        $rows = $this->db->fetchAll(
            "SELECT
                gameweek,
                AVG(points) AS overall_avg,
                AVG(CASE WHEN overall_rank <= 10000 THEN points END) AS top_10k_avg
             FROM manager_history
             WHERE gameweek IN ($placeholders)
             GROUP BY gameweek",
            $gws
        );

        $byGw = [];
        foreach ($rows as $row) {
            $byGw[(int) ($row['gameweek'] ?? 0)] = [
                'overall_avg' => isset($row['overall_avg']) ? (float) $row['overall_avg'] : null,
                'top_10k_avg' => isset($row['top_10k_avg']) ? (float) $row['top_10k_avg'] : null,
            ];
        }

        $overall = [];
        $top10k = [];
        sort($gws);
        foreach ($gws as $gw) {
            $series = $byGw[$gw] ?? ['overall_avg' => null, 'top_10k_avg' => null];
            $overall[] = [
                'gameweek' => $gw,
                'points' => $series['overall_avg'] === null ? null : round($series['overall_avg'], 2),
            ];
            $top10k[] = [
                'gameweek' => $gw,
                'points' => $series['top_10k_avg'] === null ? null : round($series['top_10k_avg'], 2),
            ];
        }

        return [
            'overall' => $overall,
            'top_10k' => $top10k,
        ];
    }
}
