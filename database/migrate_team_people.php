<?php

declare(strict_types=1);

/**
 * Ejecutar una vez si ya tenías app.sqlite sin la tabla team_people:
 * php database/migrate_team_people.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite. Usa database/init.php primero.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$hasTeamPeople = $pdo->query(
    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='team_people'"
)->fetch();
if (!$hasTeamPeople) {
    $pdo->exec(
        'CREATE TABLE team_people (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
            display_name TEXT NOT NULL,
            email TEXT,
            birthday TEXT,
            extra_info TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )'
    );
    $pdo->exec('CREATE INDEX idx_team_people_team ON team_people(team_id)');
}

$cols = $pdo->query('PRAGMA table_info(topics)')->fetchAll(PDO::FETCH_ASSOC);
$hasPersonId = false;
foreach ($cols as $col) {
    if (($col['name'] ?? '') === 'person_id') {
        $hasPersonId = true;
        break;
    }
}
if (!$hasPersonId) {
    $pdo->exec('ALTER TABLE topics ADD COLUMN person_id INTEGER');
}

if ($hasTeamPeople) {
    $tpCols = $pdo->query('PRAGMA table_info(team_people)')->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    foreach ($tpCols as $c) {
        $names[$c['name'] ?? ''] = true;
    }
    if (empty($names['birthday'])) {
        $pdo->exec('ALTER TABLE team_people ADD COLUMN birthday TEXT');
    }
    if (empty($names['extra_info'])) {
        $pdo->exec('ALTER TABLE team_people ADD COLUMN extra_info TEXT');
    }
}

echo "OK: migración aplicada.\n";
