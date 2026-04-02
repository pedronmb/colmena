<?php

declare(strict_types=1);

/**
 * Crea team_alerts para recordatorios con fecha.
 * php database/migrate_team_alerts.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$exists = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='team_alerts'")->fetchColumn();
if ($exists) {
    echo "OK: team_alerts ya existe.\n";
    exit(0);
}

$pdo->exec(
    'CREATE TABLE team_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES users(id),
    title TEXT NOT NULL,
    body TEXT,
    due_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
)'
);
$pdo->exec('CREATE INDEX idx_team_alerts_team ON team_alerts(team_id)');
$pdo->exec('CREATE INDEX idx_team_alerts_due ON team_alerts(due_date)');

echo "OK: tabla team_alerts creada.\n";
