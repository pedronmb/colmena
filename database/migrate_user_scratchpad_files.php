<?php

declare(strict_types=1);

/**
 * Crea user_scratchpad y user_files para el bloc personal.
 * php database/migrate_user_scratchpad_files.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$scratch = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='user_scratchpad'")->fetchColumn();
if (!$scratch) {
    $pdo->exec(
        'CREATE TABLE user_scratchpad (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    content TEXT NOT NULL DEFAULT \'\',
    updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
)'
    );
    echo "OK: tabla user_scratchpad creada.\n";
} else {
    echo "OK: user_scratchpad ya existe.\n";
}

$files = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='user_files'")->fetchColumn();
if (!$files) {
    $pdo->exec(
        'CREATE TABLE user_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    mime_type TEXT,
    size_bytes INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
)'
    );
    $pdo->exec('CREATE INDEX idx_user_files_user ON user_files(user_id)');
    echo "OK: tabla user_files creada.\n";
} else {
    echo "OK: user_files ya existe.\n";
}
