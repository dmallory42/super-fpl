<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use Maia\Orm\Connection;
use Maia\Orm\QueryBuilder;
use SuperFPL\Api\Clients\OddsApiClient;

/**
 * Syncs odds data from The Odds API to the database.
 */
class OddsSync
{
    /**
     * Team name aliases for matching API names to FPL names.
     */
    private const TEAM_ALIASES = [
        'tottenham' => 'spurs',
        'tottenhamhotspur' => 'spurs',
        'wolverhampton' => 'wolves',
        'wolverhamptonwanderers' => 'wolves',
        'manchester' => 'manutd',
        'manchesterunited' => 'manutd',
        'manchestercity' => 'mancity',
        'nottingham' => 'nottmforest',
        'nottinghamforest' => 'nottmforest',
        'brightonandhovealbion' => 'brighton',
        'westhamunited' => 'westham',
        'westham' => 'westham',
        'newcastleunited' => 'newcastle',
        'crystalpalace' => 'crystalpalace',
        'astonvilla' => 'astonvilla',
        'leeds' => 'leeds',
        'leedsunited' => 'leeds',
    ];

    /**
     * Player name aliases (bookmaker name => FPL web_name).
     */
    private const PLAYER_ALIASES = [
        'Francisco Evanilson de Lima Barbosa' => 'Evanilson',
        'Joao Pedro Junqueira de Jesus' => 'João Pedro',
        'Igor Thiago Nascimento Rodrigues' => 'Igor Thiago',
        'Norberto Bercique Gomes Betuncal' => 'Beto',
        'Erling Braut Haaland' => 'Haaland',
        'Dominic Calvert-Lewin' => 'Calvert-Lewin',
        'Trent Alexander-Arnold' => 'Alexander-Arnold',
        'Ben Chilwell' => 'Chilwell',
        'Benjamin Sesko' => 'Šeško',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly OddsApiClient $client
    ) {
    }

    /**
     * Sync match odds for upcoming fixtures.
     *
     * @return array{fixtures: int, matched: int}
     */
    public function syncMatchOdds(): array
    {
        $matchOdds = $this->client->getMatchOdds();
        $fixtures = $this->getUpcomingFixtures();

        $matched = 0;

        foreach ($matchOdds as $odds) {
            $fixture = $this->matchFixture($odds['home_team'], $odds['away_team'], $fixtures);
            if ($fixture === null) {
                continue;
            }

            $this->upsert('fixture_odds', [
                'fixture_id' => $fixture['id'],
                'home_win_prob' => $odds['home_win_prob'],
                'draw_prob' => $odds['draw_prob'],
                'away_win_prob' => $odds['away_win_prob'],
                'home_cs_prob' => $odds['home_cs_prob'] ?? $this->estimateCleanSheetProb($odds, true),
                'away_cs_prob' => $odds['away_cs_prob'] ?? $this->estimateCleanSheetProb($odds, false),
                'expected_total_goals' => $odds['expected_total_goals'] ?? 2.5,
                'line_count' => (int) ($odds['line_count'] ?? 0),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['fixture_id']);

            $matched++;
        }

        return [
            'fixtures' => count($matchOdds),
            'matched' => $matched,
        ];
    }

    /**
     * Sync all anytime goalscorer odds.
     *
     * @return array{fixtures: int, players: int}
     */
    public function syncAllGoalscorerOdds(): array
    {
        $goalscorerData = $this->client->getGoalscorerOdds();
        $fixtures = $this->getUpcomingFixtures();

        $totalPlayers = 0;
        $matchedFixtures = 0;

        foreach ($goalscorerData as $event) {
            $fixture = $this->matchFixture(
                $event['home_team'],
                $event['away_team'],
                $fixtures
            );

            if ($fixture === null) {
                continue;
            }

            $matchedFixtures++;

            foreach ($event['players'] as $playerName => $market) {
                $player = $this->matchPlayerByName($playerName);
                if ($player === null) {
                    continue;
                }

                if (is_array($market)) {
                    $prob = (float) ($market['probability'] ?? 0);
                    $lineCount = (int) ($market['line_count'] ?? 0);
                } else {
                    $prob = (float) $market;
                    $lineCount = 0;
                }

                $this->upsert('player_goalscorer_odds', [
                    'player_id' => $player['id'],
                    'fixture_id' => $fixture['id'],
                    'anytime_scorer_prob' => $prob,
                    'line_count' => $lineCount,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['player_id', 'fixture_id']);

                $totalPlayers++;
            }
        }

        return [
            'fixtures' => $matchedFixtures,
            'players' => $totalPlayers,
        ];
    }

    /**
     * Sync all anytime assist odds.
     *
     * @return array{fixtures: int, players: int}
     */
    public function syncAllAssistOdds(): array
    {
        $assistData = $this->client->getAssistOdds();
        $fixtures = $this->getUpcomingFixtures();

        $totalPlayers = 0;
        $matchedFixtures = 0;

        foreach ($assistData as $event) {
            $fixture = $this->matchFixture(
                $event['home_team'],
                $event['away_team'],
                $fixtures
            );

            if ($fixture === null) {
                continue;
            }

            $matchedFixtures++;

            foreach ($event['players'] as $playerName => $market) {
                $player = $this->matchPlayerByName($playerName);
                if ($player === null) {
                    continue;
                }

                if (is_array($market)) {
                    $prob = (float) ($market['probability'] ?? 0);
                    $lineCount = (int) ($market['line_count'] ?? 0);
                } else {
                    $prob = (float) $market;
                    $lineCount = 0;
                }

                $this->upsert('player_assist_odds', [
                    'player_id' => $player['id'],
                    'fixture_id' => $fixture['id'],
                    'anytime_assist_prob' => $prob,
                    'line_count' => $lineCount,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['player_id', 'fixture_id']);

                $totalPlayers++;
            }
        }

        return [
            'fixtures' => $matchedFixtures,
            'players' => $totalPlayers,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getUpcomingFixtures(): array
    {
        return $this->fetchAll(
            "SELECT f.*, h.name as home_name, a.name as away_name
            FROM fixtures f
            JOIN clubs h ON f.home_club_id = h.id
            JOIN clubs a ON f.away_club_id = a.id
            WHERE f.finished = 0
            ORDER BY f.kickoff_time"
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fixtures
     * @return array<string, mixed>|null
     */
    private function matchFixture(string $homeTeam, string $awayTeam, array $fixtures): ?array
    {
        $homeTeam = $this->normalizeTeamName($homeTeam);
        $awayTeam = $this->normalizeTeamName($awayTeam);

        foreach ($fixtures as $fixture) {
            $fixtureHome = $this->normalizeTeamName($fixture['home_name']);
            $fixtureAway = $this->normalizeTeamName($fixture['away_name']);

            if ($this->teamsMatch($homeTeam, $fixtureHome) && $this->teamsMatch($awayTeam, $fixtureAway)) {
                return $fixture;
            }
        }

        return null;
    }

    private function normalizeTeamName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]/', '', $name);

        if (isset(self::TEAM_ALIASES[$name])) {
            return self::TEAM_ALIASES[$name];
        }

        $simplified = preg_replace('/(fc|afc|city|united|town|wanderers|rovers|albion|hotspur)$/', '', $name);

        if (isset(self::TEAM_ALIASES[$simplified])) {
            return self::TEAM_ALIASES[$simplified];
        }

        return $simplified ?: $name;
    }

    private function teamsMatch(string $name1, string $name2): bool
    {
        if ($name1 === $name2) {
            return true;
        }

        if (str_contains($name1, $name2) || str_contains($name2, $name1)) {
            return true;
        }

        $distance = levenshtein($name1, $name2);
        $maxLen = max(strlen($name1), strlen($name2));
        return $maxLen > 0 && ($distance / $maxLen) < 0.3;
    }

    /**
     * Strip diacritics/accents from a string (e.g. "Fernández" → "Fernandez").
     * SQLite LOWER() only handles ASCII, so accent folding must happen in PHP.
     * Uses explicit char map because musl iconv (Alpine) transliterates é → 'e.
     */
    private function stripAccents(string $str): string
    {
        return strtr($str, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O', 'Ø' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
            'ç' => 'c', 'Ç' => 'C',
            'š' => 's', 'Š' => 'S', 'ž' => 'z', 'Ž' => 'Z',
            'ć' => 'c', 'Ć' => 'C', 'č' => 'c', 'Č' => 'C',
            'đ' => 'd', 'Đ' => 'D',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I',
            'ş' => 's', 'Ş' => 'S',
            'ý' => 'y', 'Ý' => 'Y', 'ÿ' => 'y',
            'ř' => 'r', 'Ř' => 'R',
            'ł' => 'l', 'Ł' => 'L',
            'ą' => 'a', 'Ą' => 'A', 'ę' => 'e', 'Ę' => 'E',
            'ő' => 'o', 'Ő' => 'O', 'ű' => 'u', 'Ű' => 'U',
            'ß' => 'ss',
            'æ' => 'ae', 'Æ' => 'AE',
            'ð' => 'd', 'Ð' => 'D',
            'þ' => 'th', 'Þ' => 'Th',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchPlayerByName(string $name): ?array
    {
        // Check known aliases first
        if (isset(self::PLAYER_ALIASES[$name])) {
            $player = $this->fetchOne(
                'SELECT id FROM players WHERE LOWER(web_name) = LOWER(?)',
                [self::PLAYER_ALIASES[$name]]
            );
            if ($player !== null) {
                return $player;
            }
        }

        // Try exact web_name match
        $player = $this->fetchOne(
            'SELECT id FROM players WHERE LOWER(web_name) = LOWER(?)',
            [$name]
        );
        if ($player !== null) {
            return $player;
        }

        $parts = explode(' ', $name);
        $lastName = end($parts);
        $strippedLastName = strtolower($this->stripAccents($lastName));

        // Try first + last name combination (accent-insensitive) — most precise,
        // avoids collisions between e.g. "Bruno Fernandes" and "Mateus Fernandes".
        // DB first_name can be multi-word ("Mateus Gonçalo") so check if it starts
        // with the API first name. DB second_name can also be multi-word
        // ("Borges Fernandes") so search with %lastName%.
        if (count($parts) >= 2) {
            $firstName = $parts[0];
            $strippedFirstName = strtolower($this->stripAccents($firstName));
            $candidates = $this->fetchAll(
                'SELECT id, first_name, second_name FROM players WHERE second_name LIKE ?',
                ['%' . $lastName . '%']
            );
            foreach ($candidates as $candidate) {
                $candidateSecond = strtolower($this->stripAccents($candidate['second_name']));
                $candidateFirst = strtolower($this->stripAccents($candidate['first_name']));
                if (
                    str_contains($candidateSecond, $strippedLastName)
                    && str_starts_with($candidateFirst, $strippedFirstName)
                ) {
                    return $candidate;
                }
            }
        }

        // Try exact last-name match on web_name
        $player = $this->fetchOne(
            'SELECT id FROM players WHERE LOWER(web_name) = LOWER(?)',
            [$lastName]
        );
        if ($player !== null) {
            return $player;
        }

        // Try second_name match (accent-insensitive) — only if unique
        $prefix = mb_substr($lastName, 0, 3);
        $candidates = $this->fetchAll(
            'SELECT id, second_name FROM players WHERE second_name LIKE ?',
            [$prefix . '%']
        );
        $secondNameMatches = [];
        foreach ($candidates as $candidate) {
            $strippedSecondName = strtolower($this->stripAccents($candidate['second_name']));
            if ($strippedSecondName === $strippedLastName) {
                $secondNameMatches[] = $candidate;
            }
        }
        if (count($secondNameMatches) === 1) {
            return $secondNameMatches[0];
        }

        // Try fuzzy matching with LIKE — only if unique
        $likeMatches = $this->fetchAll(
            'SELECT id FROM players WHERE LOWER(web_name) LIKE LOWER(?)',
            ['%' . $lastName . '%']
        );
        if (count($likeMatches) === 1) {
            return $likeMatches[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $odds
     */
    private function estimateCleanSheetProb(array $odds, bool $isHome): float
    {
        $winProb = $isHome ? ($odds['home_win_prob'] ?? 0.33) : ($odds['away_win_prob'] ?? 0.33);
        $drawProb = $odds['draw_prob'] ?? 0.25;

        $baseCs = 0.25;
        $adjustment = $winProb * 0.3 + $drawProb * 0.5;

        return min(0.5, max(0.1, $baseCs + $adjustment));
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        return $this->connection->query($sql, $params);
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->connection->query($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $conflictKeys
     */
    private function upsert(string $table, array $data, array $conflictKeys): void
    {
        QueryBuilder::table($table, $this->connection)->upsert($data, $conflictKeys);
    }
}
