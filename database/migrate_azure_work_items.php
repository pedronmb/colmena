<?php

declare(strict_types=1);

/**
 * Crea azure_work_items para sync de Azure DevOps.
 * php database/migrate_azure_work_items.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$exists = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='azure_work_items'")->fetchColumn();
if (!$exists) {
    $pdo->exec(
        'CREATE TABLE azure_work_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        azure_id INTEGER NOT NULL UNIQUE,
        person_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
        title TEXT NOT NULL,
        work_item_type TEXT,
        state TEXT NOT NULL,
        assigned_to TEXT,
        assigned_unique_name TEXT,
        url TEXT,
        created_at TEXT,
        changed_at TEXT NOT NULL DEFAULT \'0\',
        synced_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        removed_at TEXT
    )'
    );
    $pdo->exec('CREATE INDEX idx_azure_work_items_person ON azure_work_items(person_id)');
    $pdo->exec('CREATE INDEX idx_azure_work_items_state ON azure_work_items(state)');
    $pdo->exec('CREATE INDEX idx_azure_work_items_changed ON azure_work_items(changed_at DESC)');
    echo "OK: tabla azure_work_items creada.\n";
} else {
    echo "OK: azure_work_items ya existe.\n";
}
