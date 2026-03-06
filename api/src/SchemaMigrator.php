<?php

declare(strict_types=1);

namespace SuperFPL\Api;

use Maia\Orm\Connection;
use PDOException;
use RuntimeException;

final class SchemaMigrator
{
    private const SNAPSHOT_PRIMARY_KEY = ['player_id', 'gameweek', 'is_pre_deadline'];

    public static function createConnection(string $dbPath, string $schemaPath, string $performanceIndexPath): Connection
    {
        $connection = Connection::sqlite($dbPath, [
            'foreign_keys' => true,
            'busy_timeout' => 5000,
            'synchronous' => 'NORMAL',
        ]);
        self::configurePragmas($connection);
        self::initialize($connection, $schemaPath, $performanceIndexPath);

        return $connection;
    }

    public static function configurePragmas(Connection $connection): void
    {
        try {
            $connection->configureSqlite(['journal_mode' => 'WAL']);
        } catch (\Throwable) {
            // Keep default journal mode when WAL is unavailable on the filesystem.
        }
    }

    public static function initialize(Connection $connection, string $schemaPath, string $performanceIndexPath): void
    {
        if (!self::tableExists($connection, 'clubs')) {
            self::applySchema($connection, $schemaPath);
        }

        self::applyIncrementalMigrations($connection, $performanceIndexPath);
    }

    private static function tableExists(Connection $connection, string $table): bool
    {
        $rows = $connection->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1",
            [$table]
        );

        return $rows !== [];
    }

    private static function applySchema(Connection $connection, string $schemaPath): void
    {
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new RuntimeException("Failed to read schema file: {$schemaPath}");
        }

        $connection->pdo()->exec($schema);
    }

    private static function applyIncrementalMigrations(Connection $connection, string $performanceIndexPath): void
    {
        $migrations = [
            'ALTER TABLE players ADD COLUMN appearances INTEGER DEFAULT 0',
            'ALTER TABLE players ADD COLUMN yellow_cards INTEGER DEFAULT 0',
            'ALTER TABLE players ADD COLUMN red_cards INTEGER DEFAULT 0',
            'ALTER TABLE players ADD COLUMN own_goals INTEGER DEFAULT 0',
            'ALTER TABLE players ADD COLUMN penalties_missed INTEGER DEFAULT 0',
            'ALTER TABLE players ADD COLUMN penalties_saved INTEGER DEFAULT 0',
            'ALTER TABLE players ADD COLUMN goals_conceded INTEGER DEFAULT 0',
            'ALTER TABLE players ADD COLUMN xmins_override INTEGER DEFAULT NULL',
            'ALTER TABLE players ADD COLUMN penalty_order INTEGER DEFAULT NULL',
            'ALTER TABLE player_season_history ADD COLUMN expected_goals_conceded REAL',
            'ALTER TABLE player_season_history ADD COLUMN starts INTEGER',
            'ALTER TABLE players ADD COLUMN understat_xa REAL',
            'ALTER TABLE prediction_snapshots ADD COLUMN snapshot_source TEXT DEFAULT "legacy"',
            'ALTER TABLE prediction_snapshots ADD COLUMN is_pre_deadline INTEGER DEFAULT 0',
            'ALTER TABLE player_predictions ADD COLUMN breakdown_json TEXT DEFAULT "{}"',
            'ALTER TABLE player_predictions ADD COLUMN if_fit_breakdown_json TEXT DEFAULT "{}"',
            'ALTER TABLE fixture_odds ADD COLUMN line_count INTEGER DEFAULT 0',
            'ALTER TABLE player_goalscorer_odds ADD COLUMN line_count INTEGER DEFAULT 0',
            'ALTER TABLE player_assist_odds ADD COLUMN line_count INTEGER DEFAULT 0',
        ];

        foreach ($migrations as $sql) {
            try {
                $connection->execute($sql);
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'duplicate column name')) {
                    throw $exception;
                }
            }
        }

        $connection->execute("
            CREATE TABLE IF NOT EXISTS player_assist_odds (
                player_id INTEGER,
                fixture_id INTEGER,
                anytime_assist_prob REAL,
                line_count INTEGER DEFAULT 0,
                updated_at TIMESTAMP,
                PRIMARY KEY (player_id, fixture_id)
            )
        ");

        $connection->execute("
            CREATE TABLE IF NOT EXISTS prediction_snapshots (
                player_id INTEGER,
                gameweek INTEGER,
                predicted_points REAL,
                confidence REAL,
                breakdown TEXT,
                model_version TEXT,
                snapshot_source TEXT DEFAULT 'legacy',
                is_pre_deadline INTEGER DEFAULT 0,
                snapped_at TIMESTAMP,
                PRIMARY KEY (player_id, gameweek, is_pre_deadline)
            )
        ");

        self::rebuildPredictionSnapshotsTableIfNeeded($connection);

        $connection->execute("
            CREATE TABLE IF NOT EXISTS understat_season_history (
                understat_id INTEGER,
                season INTEGER,
                minutes INTEGER,
                npxg REAL,
                xa REAL,
                goals INTEGER,
                assists INTEGER,
                shots INTEGER,
                key_passes INTEGER,
                PRIMARY KEY (understat_id, season)
            )
        ");

        $connection->execute("
            CREATE TABLE IF NOT EXISTS understat_team_season (
                team_name TEXT,
                club_id INTEGER,
                season INTEGER,
                games INTEGER,
                xgf REAL,
                xga REAL,
                npxgf REAL,
                npxga REAL,
                scored INTEGER,
                missed INTEGER,
                PRIMARY KEY (team_name, season)
            )
        ");

        if (!file_exists($performanceIndexPath)) {
            return;
        }

        $sql = file_get_contents($performanceIndexPath);
        if ($sql === false || trim($sql) === '') {
            return;
        }

        $connection->pdo()->exec($sql);
    }

    private static function rebuildPredictionSnapshotsTableIfNeeded(Connection $connection): void
    {
        if (!self::tableExists($connection, 'prediction_snapshots')) {
            return;
        }

        $columns = $connection->query('PRAGMA table_info(prediction_snapshots)');
        $primaryKey = [];

        foreach ($columns as $column) {
            $pkOrder = (int) ($column['pk'] ?? 0);
            if ($pkOrder <= 0) {
                continue;
            }

            $primaryKey[$pkOrder] = (string) ($column['name'] ?? '');
        }

        ksort($primaryKey);
        if (array_values($primaryKey) === self::SNAPSHOT_PRIMARY_KEY) {
            return;
        }

        $connection->execute('ALTER TABLE prediction_snapshots RENAME TO prediction_snapshots_legacy');
        $connection->execute("
            CREATE TABLE prediction_snapshots (
                player_id INTEGER,
                gameweek INTEGER,
                predicted_points REAL,
                confidence REAL,
                breakdown TEXT,
                model_version TEXT,
                snapshot_source TEXT DEFAULT 'legacy',
                is_pre_deadline INTEGER DEFAULT 0,
                snapped_at TIMESTAMP,
                PRIMARY KEY (player_id, gameweek, is_pre_deadline)
            )
        ");
        $connection->execute("
            INSERT INTO prediction_snapshots (
                player_id,
                gameweek,
                predicted_points,
                confidence,
                breakdown,
                model_version,
                snapshot_source,
                is_pre_deadline,
                snapped_at
            )
            SELECT
                player_id,
                gameweek,
                predicted_points,
                confidence,
                breakdown,
                model_version,
                COALESCE(snapshot_source, 'legacy'),
                COALESCE(is_pre_deadline, 0),
                snapped_at
            FROM prediction_snapshots_legacy
        ");
        $connection->execute('DROP TABLE prediction_snapshots_legacy');
    }
}
