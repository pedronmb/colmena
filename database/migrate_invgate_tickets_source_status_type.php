<?php

declare(strict_types=1);

/**
 * Añade source_id, status_id y type_id a invgate_tickets.
 * php database/migrate_invgate_tickets_source_status_type.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$exists = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='invgate_tickets'")->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "No existe la tabla invgate_tickets. Ejecutá database/migrate_invgate_tickets.php primero.\n");
    exit(1);
}

$cols = $pdo->query('PRAGMA table_info(invgate_tickets)')->fetchAll(PDO::FETCH_ASSOC);
$existing = [];
foreach ($cols as $c) {
    $existing[(string) ($c['name'] ?? '')] = true;
}

$additions = [
    'source_id' => 'ALTER TABLE invgate_tickets ADD COLUMN source_id INTEGER',
    'status_id' => 'ALTER TABLE invgate_tickets ADD COLUMN status_id INTEGER',
    'type_id' => 'ALTER TABLE invgate_tickets ADD COLUMN type_id INTEGER',
];

foreach ($additions as $name => $sql) {
    if (!isset($existing[$name])) {
        $pdo->exec($sql);
        echo "OK: columna invgate_tickets.{$name} añadida.\n";
    } else {
        echo "OK: invgate_tickets.{$name} ya existe.\n";
    }
}
