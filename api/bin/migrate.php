#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Database migration script
 * Applies schema.sql to create any missing tables
 */

$dbPath = __DIR__ . '/../data/superfpl.db';
$schemaPath = __DIR__ . '/../data/schema.sql';

if (!file_exists($schemaPath)) {
    echo "Schema file not found: $schemaPath\n";
    exit(1);
}

// Ensure data directory exists
$dataDir = dirname($dbPath);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get existing tables
$existingTables = [];
$result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
foreach ($result as $row) {
    $existingTables[] = $row['name'];
}

// Read and parse schema
$schema = file_get_contents($schemaPath);

// Split into statements and execute
$statements = array_filter(
    array_map('trim', explode(';', $schema)),
    fn($s) => !empty($s)
);

$created = [];
$skipped = [];

foreach ($statements as $statement) {
    // Extract table name from CREATE TABLE statements
    if (preg_match('/CREATE TABLE (?:IF NOT EXISTS\s+)?(\w+)/i', $statement, $matches)) {
        $tableName = $matches[1];
        if (in_array($tableName, $existingTables)) {
            $skipped[] = $tableName;
            continue;
        }

        try {
            $pdo->exec($statement);
            $created[] = $tableName;
        } catch (PDOException $e) {
            echo "Error creating table $tableName: " . $e->getMessage() . "\n";
        }
    } elseif (preg_match('/CREATE (?:UNIQUE )?INDEX/i', $statement)) {
        // Try to create index, ignore if exists
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            // Index likely exists, ignore
        }
    }
}

if (empty($created) && empty($skipped)) {
    echo "No tables to migrate.\n";
} else {
    if (!empty($created)) {
        echo "Created tables: " . implode(', ', $created) . "\n";
    }
    if (!empty($skipped)) {
        echo "Existing tables (skipped): " . implode(', ', $skipped) . "\n";
    }
}
