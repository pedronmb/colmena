<?php

declare(strict_types=1);

/**
 * Crea invgate_ticket_recommendations para recomendaciones IA por ticket.
 * php database/migrate_invgate_recommendations.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$exists = (bool) $pdo->query(
    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='invgate_ticket_recommendations'"
)->fetchColumn();
if ($exists) {
    echo "OK: invgate_ticket_recommendations ya existe.\n";
    exit(0);
}

$pdo->exec(
    'CREATE TABLE invgate_ticket_recommendations (
        ticket_id INTEGER PRIMARY KEY REFERENCES invgate_tickets(id) ON DELETE CASCADE,
        summary TEXT NOT NULL,
        recommendation TEXT NOT NULL,
        generated_at TEXT NOT NULL,
        model TEXT,
        error TEXT
    )'
);

echo "OK: tabla invgate_ticket_recommendations creada.\n";
