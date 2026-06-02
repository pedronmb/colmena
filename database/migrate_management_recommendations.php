<?php

declare(strict_types=1);

/**
 * Crea tablas del Copiloto de Management.
 * php database/migrate_management_recommendations.php
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
    'management_recommendations' => 'CREATE TABLE management_recommendations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
        period_start TEXT NOT NULL,
        period_end TEXT NOT NULL,
        summary TEXT NOT NULL DEFAULT \'\',
        risks_json TEXT NOT NULL DEFAULT \'[]\',
        actions_json TEXT NOT NULL DEFAULT \'[]\',
        people_focus_json TEXT NOT NULL DEFAULT \'[]\',
        topics_focus_json TEXT NOT NULL DEFAULT \'[]\',
        delegations_json TEXT NOT NULL DEFAULT \'[]\',
        one_on_one_json TEXT NOT NULL DEFAULT \'[]\',
        executive_bullets_json TEXT NOT NULL DEFAULT \'[]\',
        generated_at TEXT NOT NULL,
        model TEXT,
        status TEXT NOT NULL DEFAULT \'ok\' CHECK (status IN (\'ok\', \'error\')),
        error_message TEXT,
        context_hash TEXT,
        UNIQUE(team_id, period_start)
    )',
    'person_management_recommendations' => 'CREATE TABLE person_management_recommendations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
        person_id INTEGER NOT NULL REFERENCES team_people(id) ON DELETE CASCADE,
        period_start TEXT NOT NULL,
        period_end TEXT NOT NULL,
        summary TEXT NOT NULL DEFAULT \'\',
        risk_level TEXT CHECK (risk_level IS NULL OR risk_level IN (\'low\', \'medium\', \'high\')),
        situation_json TEXT NOT NULL DEFAULT \'{}\',
        risks_json TEXT NOT NULL DEFAULT \'[]\',
        suggested_actions_json TEXT NOT NULL DEFAULT \'[]\',
        one_on_one_questions_json TEXT NOT NULL DEFAULT \'[]\',
        blockers_json TEXT NOT NULL DEFAULT \'[]\',
        pentagon_note TEXT,
        generated_at TEXT NOT NULL,
        model TEXT,
        status TEXT NOT NULL DEFAULT \'ok\' CHECK (status IN (\'ok\', \'error\')),
        error_message TEXT,
        context_hash TEXT,
        UNIQUE(team_id, person_id, period_start)
    )',
];

foreach ($tables as $name => $sql) {
    $exists = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($name)
    )->fetchColumn();
    if ($exists) {
        echo "OK: {$name} ya existe.\n";
        continue;
    }
    $pdo->exec($sql);
    echo "OK: tabla {$name} creada.\n";
}

$pdo->exec('CREATE INDEX IF NOT EXISTS idx_management_recommendations_team ON management_recommendations(team_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_person_management_rec_team ON person_management_recommendations(team_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_person_management_rec_person ON person_management_recommendations(person_id)');

echo "Migración management recommendations completada.\n";
