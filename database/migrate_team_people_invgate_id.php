<?php

declare(strict_types=1);

/**
 * Añade team_people.invgate_id
 * php database/migrate_team_people_invgate_id.php
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
$has = false;
foreach ($cols as $c) {
    if (($c['name'] ?? '') === 'invgate_id') {
        $has = true;
        break;
    }
}
if (!$has) {
    $pdo->exec('ALTER TABLE team_people ADD COLUMN invgate_id INTEGER');
}

echo "OK: team_people.invgate_id listo.\n";
