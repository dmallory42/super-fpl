#!/usr/bin/env php
<?php

declare(strict_types=1);

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

SchemaMigrator::createConnection($dbPath, $schemaPath, $performanceIndexPath);

echo "Database migrations completed.\n";
