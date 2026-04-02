<?php

declare(strict_types=1);

/**
 * Añade team_people.role
 * php database/migrate_team_people_role.php
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
    if (($c['name'] ?? '') === 'role') {
        $has = true;
        break;
    }
}
if (!$has) {
    $pdo->exec('ALTER TABLE team_people ADD COLUMN role TEXT');
}

echo "OK: team_people.role listo.\n";
