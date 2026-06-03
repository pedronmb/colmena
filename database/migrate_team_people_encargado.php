<?php

declare(strict_types=1);

/**
 * Añade is_encargado a team_people (encargado del equipo directo)
 * php database/migrate_team_people_encargado.php
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

if (!isset($names['is_encargado'])) {
    $pdo->exec(
        'ALTER TABLE team_people ADD COLUMN is_encargado INTEGER NOT NULL DEFAULT 0'
    );
    echo "Añadida columna is_encargado.\n";
} else {
    echo "Columna is_encargado ya existe.\n";
}

echo "OK: team_people encargado listo.\n";
