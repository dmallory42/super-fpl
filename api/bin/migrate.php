#!/usr/bin/env php
<?php

declare(strict_types=1);

use Maia\Orm\Connection;
use SuperFPL\Api\SchemaMigrator;

require __DIR__ . '/../vendor/autoload.php';

$dbPath = __DIR__ . '/../data/superfpl.db';
$schemaPath = __DIR__ . '/../data/schema.sql';
$performanceIndexPath = __DIR__ . '/../data/migrations/add-performance-indexes.sql';

if (!file_exists($schemaPath)) {
    echo "Schema file not found: {$schemaPath}\n";
    exit(1);
}

$dataDir = dirname($dbPath);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$connection = new Connection('sqlite:' . $dbPath);
$connection->execute('PRAGMA foreign_keys = ON');
$connection->execute('PRAGMA busy_timeout = 5000');
try {
    $connection->execute('PRAGMA journal_mode = WAL');
} catch (Throwable) {
    // Keep default journal mode when WAL is unavailable.
}
$connection->execute('PRAGMA synchronous = NORMAL');

SchemaMigrator::initialize($connection, $schemaPath, $performanceIndexPath);

echo "Database migrations completed.\n";
