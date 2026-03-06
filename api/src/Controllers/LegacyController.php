<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use SuperFPL\Api\Database;

abstract class LegacyController
{
    public function __construct(
        protected readonly Database $db,
        protected readonly Config $config
    ) {
    }

    protected function currentGameweek(): int
    {
        $fixture = $this->db->fetchOne(
            'SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time LIMIT 1'
        );

        return $fixture !== null ? (int) ($fixture['gameweek'] ?? 1) : 1;
    }

    /**
     * @return array<int, float|array<int, float>>
     */
    protected function parseXMinsOverridesFromRequest(Request $request): array
    {
        $raw = $request->query('xmins');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $overrides = [];
        foreach ($decoded as $playerId => $value) {
            $normalizedPlayerId = (int) $playerId;
            if ($normalizedPlayerId <= 0) {
                continue;
            }

            if (is_array($value)) {
                $perGameweek = [];
                foreach ($value as $gameweek => $minutes) {
                    if (!is_numeric($minutes)) {
                        continue;
                    }

                    $perGameweek[(int) $gameweek] = max(0.0, min(95.0, (float) $minutes));
                }

                if ($perGameweek !== []) {
                    $overrides[$normalizedPlayerId] = $perGameweek;
                }
                continue;
            }

            if (is_numeric($value)) {
                $overrides[$normalizedPlayerId] = max(0.0, min(95.0, (float) $value));
            }
        }

        return $overrides;
    }

    /**
     * @param array<int, float|array<int, float>> $overrides
     */
    protected function resolveXMinsOverrideForGameweek(array $overrides, int $playerId, int $gameweek): ?float
    {
        if (!array_key_exists($playerId, $overrides)) {
            return null;
        }

        $raw = $overrides[$playerId];
        if (is_array($raw)) {
            if (!array_key_exists($gameweek, $raw) || !is_numeric($raw[$gameweek])) {
                return null;
            }

            return max(0.0, min(95.0, (float) $raw[$gameweek]));
        }

        if (!is_numeric($raw)) {
            return null;
        }

        return max(0.0, min(95.0, (float) $raw));
    }
}
