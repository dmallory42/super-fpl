<?php

declare(strict_types=1);

namespace SuperFPL\Api\Prediction;

use SuperFPL\Api\Database;

/**
 * Provides career historical baselines for regression-to-mean.
 *
 * Prefers Understat non-penalty xG (npxG/90) and xA/90 from
 * understat_season_history. Falls back to FPL player_season_history
 * (penalty-inclusive xG) for players without Understat data.
 */
class HistoricalBaselines
{
    /** @var array<int, array{xg_per_90: float, xa_per_90: float, total_minutes: int}> */
    private array $cache = [];

    public function __construct(Database $db)
    {
        $this->loadUnderstatBaselines($db);
        $this->loadFplFallback($db);
    }

    /**
     * Career npxG per 90 across all past seasons (non-penalty).
     */
    public function getXgPer90(int $playerCode): float
    {
        return $this->cache[$playerCode]['xg_per_90'] ?? 0.0;
    }

    /**
     * Career xA per 90 across all past seasons.
     */
    public function getXaPer90(int $playerCode): float
    {
        return $this->cache[$playerCode]['xa_per_90'] ?? 0.0;
    }

    /**
     * Regression weight: how much to trust current-season data vs career baseline.
     * Linear ramp from 0 to 1 over 1800 minutes (~20 full matches).
     */
    public function getRegressionWeight(int $currentMinutes): float
    {
        return min(1.0, $currentMinutes / 1800);
    }

    /**
     * Blend current-season rate with career baseline.
     */
    public function getEffectiveRate(float $currentRate, float $historicalRate, int $currentMinutes): float
    {
        $w = $this->getRegressionWeight($currentMinutes);
        return ($currentRate * $w) + ($historicalRate * (1 - $w));
    }

    /**
     * Load Understat npxG/xA baselines, keyed by player code.
     */
    private function loadUnderstatBaselines(Database $db): void
    {
        $rows = $db->fetchAll(
            'SELECT p.code, h.minutes, h.npxg, h.xa
             FROM understat_season_history h
             JOIN players p ON p.understat_id = h.understat_id
             WHERE h.minutes > 0'
        );

        $byPlayer = [];
        foreach ($rows as $row) {
            $code = (int) $row['code'];
            if (!isset($byPlayer[$code])) {
                $byPlayer[$code] = ['total_minutes' => 0, 'total_npxg' => 0.0, 'total_xa' => 0.0];
            }
            $mins = (int) $row['minutes'];
            $byPlayer[$code]['total_minutes'] += $mins;
            $byPlayer[$code]['total_npxg'] += (float) ($row['npxg'] ?? 0);
            $byPlayer[$code]['total_xa'] += (float) ($row['xa'] ?? 0);
        }

        foreach ($byPlayer as $code => $data) {
            $mins = $data['total_minutes'];
            $this->cache[$code] = [
                'xg_per_90' => $mins > 0 ? ($data['total_npxg'] / $mins) * 90 : 0.0,
                'xa_per_90' => $mins > 0 ? ($data['total_xa'] / $mins) * 90 : 0.0,
                'total_minutes' => $mins,
            ];
        }
    }

    /**
     * Load FPL player_season_history as fallback for players without Understat data.
     * Only fills in players not already loaded from Understat.
     */
    private function loadFplFallback(Database $db): void
    {
        $rows = $db->fetchAll(
            'SELECT player_code, minutes, expected_goals, expected_assists
             FROM player_season_history
             WHERE minutes > 0'
        );

        $byPlayer = [];
        foreach ($rows as $row) {
            $code = (int) $row['player_code'];
            // Skip players already loaded from Understat
            if (isset($this->cache[$code])) {
                continue;
            }
            if (!isset($byPlayer[$code])) {
                $byPlayer[$code] = ['total_minutes' => 0, 'total_xg' => 0.0, 'total_xa' => 0.0];
            }
            $mins = (int) $row['minutes'];
            $byPlayer[$code]['total_minutes'] += $mins;
            $byPlayer[$code]['total_xg'] += (float) ($row['expected_goals'] ?? 0);
            $byPlayer[$code]['total_xa'] += (float) ($row['expected_assists'] ?? 0);
        }

        foreach ($byPlayer as $code => $data) {
            $mins = $data['total_minutes'];
            $this->cache[$code] = [
                'xg_per_90' => $mins > 0 ? ($data['total_xg'] / $mins) * 90 : 0.0,
                'xa_per_90' => $mins > 0 ? ($data['total_xa'] / $mins) * 90 : 0.0,
                'total_minutes' => $mins,
            ];
        }
    }
}
