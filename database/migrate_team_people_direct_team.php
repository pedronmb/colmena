<?php

declare(strict_types=1);

/**
 * Añade is_direct_team a team_people (equipo directo vs colaborador)
 * php database/migrate_team_people_direct_team.php
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

if (!isset($names['is_direct_team'])) {
    $pdo->exec(
        'ALTER TABLE team_people ADD COLUMN is_direct_team INTEGER NOT NULL DEFAULT 0'
    );
    echo "Añadida columna is_direct_team.\n";
} else {
    echo "Columna is_direct_team ya existe.\n";
}

echo "OK: team_people equipo directo listo.\n";
