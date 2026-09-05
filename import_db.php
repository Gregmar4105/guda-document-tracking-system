<?php
// Script to import initial database schema from if0_42343630_dts.sql
// Can be executed via: php import_db.php or by visiting /import_db.php

if (php_sapi_name() !== 'cli') {
    echo "<pre>";
}

echo "=== Document Tracking System - Database Initializer ===\n\n";

require_once __DIR__ . '/db_connect.php';

$sql_file = __DIR__ . '/if0_42343630_dts.sql';
if (!file_exists($sql_file)) {
    die("Error: SQL file 'if0_42343630_dts.sql' not found.\n");
}

echo "Reading SQL file (" . basename($sql_file) . ")...\n";
$sql = file_get_contents($sql_file);
if (empty($sql)) {
    die("Error: SQL file is empty.\n");
}

echo "Importing database tables and initial data...\n";

// Disable foreign key checks during import to ensure smooth table creation
$conn->query("SET FOREIGN_KEY_CHECKS = 0;");

if ($conn->multi_query($sql)) {
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    if ($conn->errno) {
        echo "Finished with warning: " . $conn->error . "\n";
    } else {
        echo "Database imported successfully!\n";
    }
} else {
    echo "Import failed: " . $conn->error . "\n";
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1;");

if (php_sapi_name() !== 'cli') {
    echo "</pre>";
}
