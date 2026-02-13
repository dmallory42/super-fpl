<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

class LeagueSeasonAnalysisService
{
    public function __construct(
        private readonly LeagueService $leagueService,
        private readonly ManagerSeasonAnalysisService $managerSeasonAnalysisService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(int $leagueId, ?int $gwFrom = null, ?int $gwTo = null, int $topN = 20): array
    {
        $league = $this->leagueService->getLeague($leagueId);
        if ($league === null) {
            return ['status' => 404, 'error' => 'League not found'];
        }

        $standings = $league['standings']['results'] ?? [];
        if (!is_array($standings) || count($standings) < 2) {
            return ['status' => 400, 'error' => 'League needs at least 2 managers'];
        }

        usort($standings, static function (array $a, array $b): int {
            $rankCmp = ((int) ($a['rank'] ?? PHP_INT_MAX)) <=> ((int) ($b['rank'] ?? PHP_INT_MAX));
            if ($rankCmp !== 0) {
                return $rankCmp;
            }
            return ((int) ($a['entry'] ?? 0)) <=> ((int) ($b['entry'] ?? 0));
        });

        $selected = array_slice($standings, 0, $topN);

        $managerRows = [];
        $minGw = null;
        $maxGw = null;

        foreach ($selected as $standing) {
            $managerId = (int) ($standing['entry'] ?? 0);
            if ($managerId <= 0) {
                continue;
            }

            $analysis = $this->managerSeasonAnalysisService->analyze($managerId);
            if ($analysis === null) {
                continue;
            }

            $filtered = $this->filterGameweeks($analysis['gameweeks'] ?? [], $gwFrom, $gwTo);
            foreach ($filtered as $gwData) {
                $gw = (int) ($gwData['gameweek'] ?? 0);
                if ($gw <= 0) {
                    continue;
                }
                $minGw = $minGw === null ? $gw : min($minGw, $gw);
                $maxGw = $maxGw === null ? $gw : max($maxGw, $gw);
            }

            $managerRows[] = [
                'manager_id' => $managerId,
                'manager_name' => (string) ($standing['player_name'] ?? 'Unknown'),
                'team_name' => (string) ($standing['entry_name'] ?? 'Unknown'),
                'rank' => (int) ($standing['rank'] ?? 0),
                'total' => (int) ($standing['total'] ?? 0),
                'gameweeks_raw' => $filtered,
                'transfer_analytics_raw' => $this->filterGameweeks($analysis['transfer_analytics'] ?? [], $gwFrom, $gwTo),
            ];
        }

        if (count($managerRows) < 2) {
            return ['status' => 400, 'error' => 'League needs at least 2 managers with season data'];
        }

        if ($gwFrom !== null && $gwTo !== null) {
            $axisStart = min($gwFrom, $gwTo);
            $axisEnd = max($gwFrom, $gwTo);
        } elseif ($gwFrom !== null) {
            $axisStart = $gwFrom;
            $axisEnd = $maxGw ?? $gwFrom;
        } elseif ($gwTo !== null) {
            $axisStart = $minGw ?? $gwTo;
            $axisEnd = $gwTo;
        } else {
            $axisStart = $minGw ?? 1;
            $axisEnd = $maxGw ?? $axisStart;
        }

        $axisStart = max(1, $axisStart);
        $axisEnd = max($axisStart, $axisEnd);
        $gwAxis = range($axisStart, $axisEnd);

        $managers = [];
        foreach ($managerRows as $row) {
            $gwMap = [];
            foreach ($row['gameweeks_raw'] as $gwData) {
                $gwMap[(int) $gwData['gameweek']] = $gwData;
            }

            $normalizedGameweeks = [];
            $chipEvents = 0;
            foreach ($gwAxis as $gw) {
                $value = $gwMap[$gw] ?? null;
                if ($value === null) {
                    $normalizedGameweeks[] = [
                        'gameweek' => $gw,
                        'actual_points' => 0.0,
                        'expected_points' => 0.0,
                        'luck_delta' => 0.0,
                        'event_transfers' => 0,
                        'event_transfers_cost' => 0,
                        'captain_actual_gain' => 0.0,
                        'missing' => true,
                    ];
                    continue;
                }

                $chipEvents += count($value['chip_impact']['chips'] ?? []);
                $normalizedGameweeks[] = [
                    'gameweek' => $gw,
                    'actual_points' => round((float) ($value['actual_points'] ?? 0), 2),
                    'expected_points' => round((float) ($value['expected_points'] ?? 0), 2),
                    'luck_delta' => round((float) ($value['luck_delta'] ?? 0), 2),
                    'event_transfers' => (int) ($value['event_transfers'] ?? 0),
                    'event_transfers_cost' => (int) ($value['event_transfers_cost'] ?? 0),
                    'captain_actual_gain' => round((float) ($value['captain_impact']['actual_gain'] ?? 0), 2),
                    'missing' => false,
                ];
            }

            $transferTotalCost = array_sum(array_column($row['transfer_analytics_raw'], 'transfer_cost'));
            $transferNetGain = round(array_sum(array_column($row['transfer_analytics_raw'], 'net_gain')), 2);

            $managers[] = [
                'manager_id' => $row['manager_id'],
                'manager_name' => $row['manager_name'],
                'team_name' => $row['team_name'],
                'rank' => $row['rank'],
                'total' => $row['total'],
                'gameweeks' => $normalizedGameweeks,
                'decision_quality' => [
                    'captain_gains' => round(array_sum(array_column($normalizedGameweeks, 'captain_actual_gain')), 2),
                    'hit_cost' => $transferTotalCost,
                    'transfer_net_gain' => $transferNetGain,
                    'hit_roi' => $transferTotalCost > 0 ? round($transferNetGain / $transferTotalCost, 3) : null,
                    'chip_events' => $chipEvents,
                ],
            ];
        }

        usort($managers, static function (array $a, array $b): int {
            $rankCmp = ((int) $a['rank']) <=> ((int) $b['rank']);
            if ($rankCmp !== 0) {
                return $rankCmp;
            }
            return ((int) $a['manager_id']) <=> ((int) $b['manager_id']);
        });

        $benchmarks = $this->buildBenchmarks($gwAxis, $managers);

        return [
            'league' => [
                'id' => $leagueId,
                'name' => (string) ($league['league']['name'] ?? 'Unknown'),
            ],
            'gw_from' => $axisStart,
            'gw_to' => $axisEnd,
            'gameweek_axis' => $gwAxis,
            'managers' => $managers,
            'benchmarks' => $benchmarks,
            'manager_count' => count($managers),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterGameweeks(array $rows, ?int $gwFrom, ?int $gwTo): array
    {
        if ($gwFrom === null && $gwTo === null) {
            return $rows;
        }

        $from = $gwFrom ?? 1;
        $to = $gwTo ?? 38;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return array_values(array_filter($rows, static function (array $row) use ($from, $to): bool {
            $gw = (int) ($row['gameweek'] ?? 0);
            return $gw >= $from && $gw <= $to;
        }));
    }

    /**
     * @param array<int> $axis
     * @param array<int, array<string, mixed>> $managers
     * @return array<int, array<string, float|int>>
     */
    private function buildBenchmarks(array $axis, array $managers): array
    {
        $benchmarks = [];
        foreach ($axis as $gw) {
            $actualPoints = [];
            $expectedPoints = [];
            foreach ($managers as $manager) {
                foreach ($manager['gameweeks'] as $gwRow) {
                    if ((int) $gwRow['gameweek'] !== $gw) {
                        continue;
                    }
                    if (($gwRow['missing'] ?? false) === true) {
                        continue;
                    }
                    $actualPoints[] = (float) $gwRow['actual_points'];
                    $expectedPoints[] = (float) $gwRow['expected_points'];
                    break;
                }
            }

            $benchmarks[] = [
                'gameweek' => $gw,
                'mean_actual_points' => round($this->mean($actualPoints), 2),
                'median_actual_points' => round($this->median($actualPoints), 2),
                'mean_expected_points' => round($this->mean($expectedPoints), 2),
                'median_expected_points' => round($this->median($expectedPoints), 2),
            ];
        }

        return $benchmarks;
    }

    /**
     * @param array<int, float> $values
     */
    private function mean(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        return array_sum($values) / count($values);
    }

    /**
     * @param array<int, float> $values
     */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if (($count % 2) === 1) {
            return $values[$mid];
        }
        return ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
