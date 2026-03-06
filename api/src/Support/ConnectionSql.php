<?php

declare(strict_types=1);

namespace SuperFPL\Api\Support;

use Maia\Orm\Connection;
use Maia\Orm\QueryBuilder;
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

        foreach (array_keys($data) as $column) {
            $this->assertSafeIdentifier($column);
        }

        return QueryBuilder::table($table, $this->connection())->insert($data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $conflictKeys
     */
    protected function upsert(string $table, array $data, array $conflictKeys): int
    {
        $this->assertSafeIdentifier($table);

        foreach (array_keys($data) as $column) {
            $this->assertSafeIdentifier($column);
        }
        foreach ($conflictKeys as $key) {
            $this->assertSafeIdentifier($key);
        }

        return QueryBuilder::table($table, $this->connection())->upsert($data, $conflictKeys);
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
