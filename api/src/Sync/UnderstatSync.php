<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use SuperFPL\Api\Database;
use SuperFPL\Api\Clients\UnderstatClient;

/**
 * Syncs player xG data from Understat into the players table.
 *
 * Provides non-penalty xG (npxG), non-penalty goals (npg),
 * shots, key passes, xGChain, and xGBuildup.
 */
class UnderstatSync
{
    /**
     * Understat team name → FPL club name mapping.
     * Only entries where the names differ need to be listed.
     */
    private const TEAM_MAP = [
        'Manchester City'          => 'Man City',
        'Manchester United'        => 'Man Utd',
        'Newcastle United'         => 'Newcastle',
        'Nottingham Forest'        => "Nott'm Forest",
        'Tottenham'                => 'Spurs',
        'Wolverhampton Wanderers'  => 'Wolves',
        'West Ham'                 => 'West Ham',
    ];

    /**
     * Understat player name → FPL web_name for tricky matches.
     */
    private const PLAYER_ALIASES = [
        'Erling Haaland'           => 'Haaland',
        'Mohamed Salah'            => 'M.Salah',
        'Bruno Fernandes'          => 'B.Fernandes',
        'Enzo Fernández'           => 'Enzo',
        'Enzo Fernandez'           => 'Enzo',
        'Heung-Min Son'            => 'Son',
        'Diogo Jota'               => 'Jota',
        'Gabriel Magalhães'        => 'Gabriel',
        'Gabriel Martinelli'       => 'Martinelli',
        'Benjamin Šeško'           => 'Šeško',
        'Viktor Gyökeres'          => 'Gyökeres',
        'Viktor Gyokeres'          => 'Gyökeres',
        'Igor Thiago Nascimento Rodrigues' => 'Igor Thiago',
        'Norberto Bercique Gomes Betuncal' => 'Beto',
        'Francisco Evanilson de Lima Barbosa' => 'Evanilson',
        'Joao Pedro Junqueira de Jesus' => 'João Pedro',
        'Dominic Calvert-Lewin'    => 'Calvert-Lewin',
        'Trent Alexander-Arnold'   => 'Alexander-Arnold',
        'Emile Smith Rowe'         => 'Smith Rowe',
        'Emile Smith-Rowe'         => 'Smith Rowe',
        'Jean-Philippe Mateta'     => 'Mateta',
        'Odsonne Édouard'          => 'Édouard',
        'Leandro Trossard'         => 'Trossard',
        'Bruno Guimarães'          => 'Bruno G.',
        'Bruno Guimaraes'          => 'Bruno G.',
        'Hugo Ekitike'             => 'Ekitiké',
        'Marc Guehi'               => 'Guéhi',
        'Amad Diallo Traore'       => 'Amad',
        'Amad Diallo'              => 'Amad',
        'Virgil van Dijk'          => 'Virgil',
        'Martin Odegaard'          => 'Ødegaard',
        'Tomas Soucek'             => 'Souček',
        'Raúl Jiménez'             => 'Raúl',
        'Raul Jimenez'             => 'Raúl',
        'Hee-Chan Hwang'           => 'Hwang',
        'Jørgen Strand Larsen'     => 'Strand Larsen',
        'Brennan Johnson'          => 'Johnson',
        'Yeremi Pino'              => 'Yeremy',
        'Nico González'            => 'N.González',
        'Diego Gómez'              => 'Gómez',
        'Ibrahim Sangare'          => 'Sangaré',
        'Sasa Lukic'               => 'Lukić',
        'Rúben Dias'               => 'Rúben',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly UnderstatClient $client
    ) {
    }

    /**
     * Sync Understat player stats into the players table.
     *
     * @return array{total: int, matched: int, unmatched: int, unmatched_players: string[]}
     */
    public function sync(int $season): array
    {
        $understatPlayers = $this->client->getPlayerStats($season);

        if (empty($understatPlayers)) {
            return ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'unmatched_players' => []];
        }

        // Build FPL player lookup: club_id => [players]
        $fplPlayers = $this->db->fetchAll(
            'SELECT id, web_name, first_name, second_name, club_id FROM players'
        );
        $clubNames = $this->db->fetchAll('SELECT id, name FROM clubs');
        $clubNameMap = [];
        foreach ($clubNames as $c) {
            $clubNameMap[(int) $c['id']] = $c['name'];
        }

        $matched = 0;
        $unmatched = 0;
        $unmatchedPlayers = [];

        foreach ($understatPlayers as $up) {
            $understatName = $up['player_name'] ?? '';
            // Handle players who transferred — use last team listed
            $teamTitle = $up['team_title'] ?? '';
            $teams = explode(',', $teamTitle);
            $currentTeam = trim(end($teams));

            $fplClubId = $this->resolveClubId($currentTeam, $clubNameMap);
            if ($fplClubId === null) {
                $unmatched++;
                $unmatchedPlayers[] = "{$understatName} ({$currentTeam})";
                continue;
            }

            $fplPlayer = $this->matchPlayer($understatName, $fplClubId, $fplPlayers);

            // Fallback: try all clubs for transferred players
            if ($fplPlayer === null) {
                $fplPlayer = $this->matchPlayer($understatName, null, $fplPlayers);
            }

            if ($fplPlayer === null) {
                // Only log if the player has meaningful minutes
                if ((int) ($up['time'] ?? 0) > 90) {
                    $unmatched++;
                    $unmatchedPlayers[] = "{$understatName} ({$currentTeam})";
                }
                continue;
            }

            $this->updatePlayer($fplPlayer['id'], $up);
            $matched++;
        }

        return [
            'total' => count($understatPlayers),
            'matched' => $matched,
            'unmatched' => $unmatched,
            'unmatched_players' => array_slice($unmatchedPlayers, 0, 20),
        ];
    }

    /**
     * Resolve Understat team name to FPL club_id.
     */
    private function resolveClubId(string $understatTeam, array $clubNameMap): ?int
    {
        $fplName = self::TEAM_MAP[$understatTeam] ?? $understatTeam;

        foreach ($clubNameMap as $id => $name) {
            if (strcasecmp($name, $fplName) === 0) {
                return $id;
            }
        }

        // Fuzzy fallback: check if understat name contains FPL name or vice versa
        $lower = strtolower($understatTeam);
        foreach ($clubNameMap as $id => $name) {
            if (str_contains($lower, strtolower($name)) || str_contains(strtolower($name), $lower)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Match an Understat player to an FPL player by name within the same club.
     */
    private function matchPlayer(string $understatName, ?int $clubId, array $fplPlayers): ?array
    {
        // Filter to same club if specified
        $clubPlayers = $clubId !== null
            ? array_filter($fplPlayers, fn($p) => (int) $p['club_id'] === $clubId)
            : $fplPlayers;

        // Check aliases first
        if (isset(self::PLAYER_ALIASES[$understatName])) {
            $alias = self::PLAYER_ALIASES[$understatName];
            foreach ($clubPlayers as $fp) {
                if (strcasecmp($fp['web_name'], $alias) === 0) {
                    return $fp;
                }
            }
        }

        // Try exact web_name match
        foreach ($clubPlayers as $fp) {
            if (strcasecmp($fp['web_name'], $understatName) === 0) {
                return $fp;
            }
        }

        // Try last name match
        $parts = explode(' ', $understatName);
        $lastName = end($parts);

        foreach ($clubPlayers as $fp) {
            if (strcasecmp($fp['web_name'], $lastName) === 0) {
                return $fp;
            }
        }

        // Try second_name match
        foreach ($clubPlayers as $fp) {
            if (strcasecmp($fp['second_name'] ?? '', $lastName) === 0) {
                return $fp;
            }
        }

        // Try first + second name match
        if (count($parts) >= 2) {
            $firstName = $parts[0];
            foreach ($clubPlayers as $fp) {
                if (
                    strcasecmp($fp['first_name'] ?? '', $firstName) === 0
                    && strcasecmp($fp['second_name'] ?? '', $lastName) === 0
                ) {
                    return $fp;
                }
            }
        }

        // Fuzzy: web_name contains last name
        foreach ($clubPlayers as $fp) {
            if (str_contains(strtolower($fp['web_name']), strtolower($lastName))) {
                return $fp;
            }
        }

        // Accent-stripped matching: normalize both sides and compare
        $strippedLast = $this->stripAccents($lastName);
        foreach ($clubPlayers as $fp) {
            if (strcasecmp($this->stripAccents($fp['web_name']), $strippedLast) === 0) {
                return $fp;
            }
            if (strcasecmp($this->stripAccents($fp['second_name'] ?? ''), $strippedLast) === 0) {
                return $fp;
            }
        }

        return null;
    }

    /**
     * Strip accents/diacritics from a string.
     */
    private function stripAccents(string $str): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        if ($transliterated === false) {
            return $str;
        }
        // Remove any remaining non-alphanumeric chars from transliteration artifacts
        return preg_replace("/[^a-zA-Z0-9 '-]/", '', $transliterated);
    }

    /**
     * Update a player's Understat data in the DB.
     */
    private function updatePlayer(int $playerId, array $understatData): void
    {
        $this->db->query(
            'UPDATE players SET
                understat_id = ?,
                npxg = ?,
                npg = ?,
                understat_shots = ?,
                understat_key_passes = ?,
                xg_chain = ?,
                xg_buildup = ?
             WHERE id = ?',
            [
                (int) $understatData['id'],
                round((float) ($understatData['npxG'] ?? 0), 4),
                (int) ($understatData['npg'] ?? 0),
                (int) ($understatData['shots'] ?? 0),
                (int) ($understatData['key_passes'] ?? 0),
                round((float) ($understatData['xGChain'] ?? 0), 4),
                round((float) ($understatData['xGBuildup'] ?? 0), 4),
                $playerId,
            ]
        );
    }
}
