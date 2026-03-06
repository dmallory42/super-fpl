<?php

declare(strict_types=1);

namespace SuperFPL\Api\Support;

use Maia\Orm\Connection;
use PDO;

trait ConnectionSql
{
    abstract protected function connection(): Connection;

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->connection()->query($sql, $params);
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->connection()->query($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param array<int, mixed> $params
     */
    protected function execute(string $sql, array $params = []): int
    {
        return $this->connection()->execute($sql, $params);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function insert(string $table, array $data): int
    {
        $this->assertSafeIdentifier($table);

        $columns = array_keys($data);
        foreach ($columns as $column) {
            $this->assertSafeIdentifier($column);
        }

        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $this->connection()->execute(
            "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})",
            array_values($data)
        );

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $conflictKeys
     */
    protected function upsert(string $table, array $data, array $conflictKeys): int
    {
        $this->assertSafeIdentifier($table);

        $columns = array_keys($data);
        foreach ($columns as $column) {
            $this->assertSafeIdentifier($column);
        }
        foreach ($conflictKeys as $key) {
            $this->assertSafeIdentifier($key);
        }

        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $conflictList = implode(', ', $conflictKeys);

        $updateColumns = array_values(array_diff($columns, $conflictKeys));
        if ($updateColumns === []) {
            $this->connection()->execute(
                "INSERT OR IGNORE INTO {$table} ({$columnList}) VALUES ({$placeholders})",
                array_values($data)
            );

            return (int) $this->connection()->lastInsertId();
        }

        $updateList = implode(', ', array_map(
            static fn(string $column): string => "{$column} = excluded.{$column}",
            $updateColumns
        ));

        $this->connection()->execute(
            "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})
             ON CONFLICT ({$conflictList}) DO UPDATE SET {$updateList}",
            array_values($data)
        );

        return (int) $this->connection()->lastInsertId();
    }

    protected function pdo(): PDO
    {
        return $this->connection()->pdo();
    }

    private function assertSafeIdentifier(string $value): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$value}");
        }
    }
}

