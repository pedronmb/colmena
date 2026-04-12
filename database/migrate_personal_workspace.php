<?php

declare(strict_types=1);

/**
 * Añade users.personal_team_id y rellena espacio de trabajo por usuario.
 * php database/migrate_personal_workspace.php
 */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

$config = require $base . '/config/config.php';
$dbPath = $config['db']['path'] ?? ($base . '/database/app.sqlite');
if (!is_string($dbPath) || !file_exists($dbPath)) {
    fwrite(STDERR, "No existe la base de datos configurada.\n");
    exit(1);
}

$pdo = \App\Database\Connection::get($config);

$hasColumn = false;
foreach ($pdo->query('PRAGMA table_info(users)') as $col) {
    if (($col['name'] ?? '') === 'personal_team_id') {
        $hasColumn = true;
        break;
    }
}

if (!$hasColumn) {
    $pdo->exec('ALTER TABLE users ADD COLUMN personal_team_id INTEGER REFERENCES teams(id)');
    echo "OK: columna personal_team_id añadida a users.\n";
}

$usersStmt = $pdo->query('SELECT id, display_name, personal_team_id FROM users ORDER BY id ASC');
$rows = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$fixed = 0;
foreach ($rows as $row) {
    $uid = (int) $row['id'];
    $pt = $row['personal_team_id'] ?? null;
    if ($pt !== null && $pt !== '' && (int) $pt > 0) {
        continue;
    }
    \App\Services\PersonalWorkspaceService::ensureForUser($pdo, $uid);
    $fixed++;
}

echo "OK: espacio personal verificado para " . count($rows) . " usuario(s); actualizados {$fixed}.\n";
