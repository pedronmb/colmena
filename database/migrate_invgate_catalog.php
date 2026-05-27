<?php

declare(strict_types=1);

/**
 * Crea tablas lookup de InvGate para resolver nombres:
 * - invgate_categories
 * - invgate_types
 * - invgate_statuses
 *
 * php database/migrate_invgate_catalog.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = [
    'invgate_categories' => 'CREATE TABLE invgate_categories (
        invgate_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        parent_category_id INTEGER
    )',
    'invgate_types' => 'CREATE TABLE invgate_types (
        invgate_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    )',
    'invgate_statuses' => 'CREATE TABLE invgate_statuses (
        invgate_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    )',
];

foreach ($tables as $name => $sql) {
    $exists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($name))
        ->fetchColumn();
    if ($exists) {
        echo "OK: {$name} ya existe.\n";
        continue;
    }
    $pdo->exec($sql);
    echo "OK: tabla {$name} creada.\n";
}

