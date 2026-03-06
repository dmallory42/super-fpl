<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use Maia\Orm\Connection;
use SuperFPL\FplClient\FplClient;

class FixtureSync
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FplClient $fplClient
    ) {
    }

    /**
     * Sync fixtures from FPL API.
     * Always bypasses cache to get fresh data.
     */
    public function sync(): int
    {
        // Bypass cache to always get fresh fixture data
        $fixtures = $this->fplClient->fixtures()->getRaw(gameweek: null, useCache: false);
        $count = 0;

        foreach ($fixtures as $fixture) {
            $this->upsert('fixtures', [
                'id' => $fixture['id'],
                'gameweek' => $fixture['event'],
                'home_club_id' => $fixture['team_h'],
                'away_club_id' => $fixture['team_a'],
                'kickoff_time' => $fixture['kickoff_time'],
                'home_score' => $fixture['team_h_score'],
                'away_score' => $fixture['team_a_score'],
                'home_difficulty' => $fixture['team_h_difficulty'],
                'away_difficulty' => $fixture['team_a_difficulty'],
                'finished' => $fixture['finished'] ? 1 : 0,
            ], ['id']);

            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $conflictKeys
     */
    private function upsert(string $table, array $data, array $conflictKeys = ['id']): void
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', $columns);
        $conflictList = implode(', ', $conflictKeys);

        $updateColumns = array_diff($columns, $conflictKeys);
        $updateList = implode(', ', array_map(
            static fn(string $col): string => "{$col} = excluded.{$col}",
            array_values($updateColumns)
        ));

        $this->connection->execute(
            "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})
             ON CONFLICT ({$conflictList}) DO UPDATE SET {$updateList}",
            array_values($data)
        );
    }
}
