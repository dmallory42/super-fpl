<?php

declare(strict_types=1);

namespace SuperFPL\Api\Clients;

/**
 * Client for Understat (https://understat.com/)
 *
 * Fetches player-level xG stats including non-penalty xG (npxG),
 * non-penalty goals (npg), shots, key passes, xGChain, xGBuildup.
 */
class UnderstatClient
{
    private const BASE_URL = 'https://understat.com';

    private string $cacheDir;
    private int $cacheTtl;

    public function __construct(string $cacheDir = '/tmp', int $cacheTtlSeconds = 86400)
    {
        $this->cacheDir = $cacheDir;
        $this->cacheTtl = $cacheTtlSeconds;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get all player stats for a given EPL season.
     *
     * @param int $season Season start year (e.g. 2025 for 2025-26)
     * @return array<int, array{
     *     id: string,
     *     player_name: string,
     *     games: string,
     *     time: string,
     *     goals: string,
     *     xG: string,
     *     assists: string,
     *     xA: string,
     *     shots: string,
     *     key_passes: string,
     *     yellow_cards: string,
     *     red_cards: string,
     *     position: string,
     *     team_title: string,
     *     npg: string,
     *     npxG: string,
     *     xGChain: string,
     *     xGBuildup: string
     * }>
     */
    public function getPlayerStats(int $season): array
    {
        $cacheKey = "understat_epl_{$season}";
        $cacheFile = $this->cacheDir . "/{$cacheKey}.json";

        // Check cache
        if (file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $this->cacheTtl) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if ($cached !== null) {
                    error_log("UnderstatClient: Using cached data ({$cacheAge}s old)");
                    return $cached;
                }
            }
        }

        $url = self::BASE_URL . '/main/getPlayersStats/';
        $postData = http_build_query(['league' => 'EPL', 'season' => $season]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/x-www-form-urlencoded',
                    'X-Requested-With: XMLHttpRequest',
                    'Accept-Encoding: gzip',
                ]),
                'content' => $postData,
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            error_log("UnderstatClient: Failed to fetch from API");
            return [];
        }

        // Handle gzip encoding
        if (substr($response, 0, 2) === "\x1f\x8b") {
            $response = gzdecode($response);
            if ($response === false) {
                error_log("UnderstatClient: Failed to decode gzip response");
                return [];
            }
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("UnderstatClient: Invalid JSON response");
            return [];
        }

        $players = $data['players'] ?? [];

        // Cache successful response
        file_put_contents($cacheFile, json_encode($players));
        error_log("UnderstatClient: Fetched " . count($players) . " players, cached for {$this->cacheTtl}s");

        return $players;
    }
}
