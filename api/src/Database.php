<?php

declare(strict_types=1);

namespace SuperFPL\Api;

use PDO;
use PDOStatement;

class Database
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
        $this->pdo = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Enable foreign keys
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Initialize database schema if tables don't exist.
     */
    public function init(): void
    {
        // Check if tables already exist
        $result = $this->fetchOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='clubs'"
        );

        if ($result !== null) {
            return;
        }

        // Read and execute schema
        $schemaPath = dirname(__DIR__) . '/data/schema.sql';
        $schema = file_get_contents($schemaPath);

        if ($schema === false) {
            throw new \RuntimeException("Failed to read schema file: {$schemaPath}");
        }

        $this->pdo->exec($schema);
    }

    /**
     * Execute a query and return the PDOStatement.
     *
     * @param string $sql SQL query with placeholders
     * @param array<string|int, mixed> $params Parameters to bind
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows from a query.
     *
     * @param string $sql SQL query with placeholders
     * @param array<string|int, mixed> $params Parameters to bind
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single row from a query.
     *
     * @param string $sql SQL query with placeholders
     * @param array<string|int, mixed> $params Parameters to bind
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Insert a row into a table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert or update a row on conflict.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     * @param array<int, string> $conflictKeys Columns that form the unique constraint
     */
    public function upsert(string $table, array $data, array $conflictKeys): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        // Build UPDATE clause for non-conflict columns
        $updateColumns = array_diff($columns, $conflictKeys);
        $updateClause = implode(', ', array_map(
            fn(string $col): string => "{$col} = excluded.{$col}",
            $updateColumns
        ));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $conflictKeys),
            $updateClause
        );

        $this->query($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Get the underlying PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
