<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Support;

use Maia\Orm\Connection;
use PDO;
use SuperFPL\Api\SchemaMigrator;

class TestDatabase extends Connection
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_MUTATION_TABLES = [
        'clubs',
        'players',
        'fixtures',
        'player_gameweek_history',
        'managers',
        'manager_picks',
        'manager_history',
        'leagues',
        'league_members',
        'player_predictions',
        'seasons',
        'player_season_history',
        'fixture_odds',
        'player_goalscorer_odds',
        'player_assist_odds',
        'prediction_snapshots',
        'sample_picks',
        'understat_season_history',
        'understat_team_season',
    ];

    public function __construct(string $path = ':memory:')
    {
        parent::__construct('sqlite:' . $path);
        $this->execute('PRAGMA foreign_keys = ON');
        $this->execute('PRAGMA busy_timeout = 5000');
    }

    public function init(): void
    {
        SchemaMigrator::initialize(
            $this,
            dirname(__DIR__, 2) . '/data/schema.sql',
            dirname(__DIR__, 2) . '/data/migrations/add-performance-indexes.sql'
        );
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $this->assertValidMutationTable($table);

        $columns = array_keys($data);
        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $this->execute(
            "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})",
            array_values($data)
        );

        return (int) $this->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $conflictKeys
     */
    public function upsert(string $table, array $data, array $conflictKeys): int
    {
        $this->assertValidMutationTable($table);

        $columns = array_keys($data);
        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $conflictList = implode(', ', $conflictKeys);

        $updateColumns = array_values(array_diff($columns, $conflictKeys));
        $updateClause = implode(', ', array_map(
            static fn(string $column): string => "{$column} = excluded.{$column}",
            $updateColumns
        ));

        $this->execute(
            "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})
             ON CONFLICT ({$conflictList}) DO UPDATE SET {$updateClause}",
            array_values($data)
        );

        return (int) $this->lastInsertId();
    }

    public function getPdo(): PDO
    {
        return $this->pdo();
    }

    private function assertValidMutationTable(string $table): void
    {
        if (!in_array($table, self::ALLOWED_MUTATION_TABLES, true)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
    }
}

