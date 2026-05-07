<?php

declare(strict_types=1);

/**
 * Añade ejes del perfil pentágono (0–10, nullable) a team_people
 * php database/migrate_team_people_pentagon.php
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

$add = static function (string $col) use ($pdo, $names): void {
    if (!isset($names[$col])) {
        $pdo->exec('ALTER TABLE team_people ADD COLUMN ' . $col . ' INTEGER');
        echo "Añadida columna {$col}.\n";
    }
};

$add('axis_strategic_vision');
$add('axis_technical_execution');
$add('axis_team_management');
$add('axis_data_risk');
$add('axis_innovation');

echo "OK: team_people pentágono listo.\n";
