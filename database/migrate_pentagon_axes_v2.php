<?php

declare(strict_types=1);

/**
 * Renombra columnas del pentágono v1 → v2 en team_people.
 * php database/migrate_pentagon_axes_v2.php
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

$renames = [
    'axis_strategic_vision' => 'axis_autonomy_problem_solving',
    'axis_technical_execution' => 'axis_impact_scope',
    'axis_team_management' => 'axis_influence_mentorship',
    'axis_data_risk' => 'axis_business_communication',
    'axis_innovation' => 'axis_technical_competence',
];

foreach ($renames as $old => $new) {
    if (isset($names[$old]) && !isset($names[$new])) {
        $pdo->exec('ALTER TABLE team_people RENAME COLUMN ' . $old . ' TO ' . $new);
        echo "Renombrada {$old} → {$new}.\n";
        unset($names[$old]);
        $names[$new] = true;
    }
}

$newColumns = array_values($renames);
foreach ($newColumns as $col) {
    if (!isset($names[$col])) {
        $pdo->exec('ALTER TABLE team_people ADD COLUMN ' . $col . ' INTEGER');
        echo "Añadida columna {$col}.\n";
    }
}

echo "OK: ejes del pentágono v2 listos.\n";
