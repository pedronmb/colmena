<?php

declare(strict_types=1);

/**
 * Añade reports_to_id a team_people (dependencia jerárquica / organigrama)
 * php database/migrate_team_people_reports_to.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = $pdo->query('PRAGMA table_info(team_people)')->fetchAll(PDO::FETCH_ASSOC);
$names = [];
foreach ($cols as $c) {
    $names[$c['name'] ?? ''] = true;
}

if (!isset($names['reports_to_id'])) {
    $pdo->exec(
        'ALTER TABLE team_people ADD COLUMN reports_to_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL'
    );
    echo "Añadida columna reports_to_id.\n";
} else {
    echo "Columna reports_to_id ya existe.\n";
}

$indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_team_people_reports_to'")->fetchAll(PDO::FETCH_ASSOC);
if (count($indexes) === 0) {
    $pdo->exec('CREATE INDEX idx_team_people_reports_to ON team_people(reports_to_id)');
    echo "Creado índice idx_team_people_reports_to.\n";
}

echo "OK: team_people organigrama listo.\n";
