<?php

declare(strict_types=1);

/**
 * Crea invgate_tickets e invgate_ticket_comments para sync de InvGate.
 * php database/migrate_invgate_tickets.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ticketsExists = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='invgate_tickets'")->fetchColumn();
if (!$ticketsExists) {
    $pdo->exec(
        'CREATE TABLE invgate_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
        invgate_incident_id INTEGER NOT NULL UNIQUE,
        user_id INTEGER,
        title TEXT NOT NULL,
        description TEXT,
        category_id INTEGER,
        created_at TEXT NOT NULL,
        last_update TEXT NOT NULL,
        priority INTEGER
    )'
    );
    $pdo->exec('CREATE INDEX idx_invgate_tickets_person ON invgate_tickets(person_id)');
    $pdo->exec('CREATE INDEX idx_invgate_tickets_last_update ON invgate_tickets(last_update DESC)');
    echo "OK: tabla invgate_tickets creada.\n";
} else {
    echo "OK: invgate_tickets ya existe.\n";
}

$commentsExists = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='invgate_ticket_comments'")->fetchColumn();
if (!$commentsExists) {
    $pdo->exec(
        'CREATE TABLE invgate_ticket_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        incident_id INTEGER NOT NULL REFERENCES invgate_tickets(id) ON DELETE CASCADE,
        author_id INTEGER,
        message TEXT NOT NULL,
        created_at TEXT NOT NULL,
        msg_num INTEGER NOT NULL,
        is_solution INTEGER NOT NULL DEFAULT 0,
        UNIQUE(incident_id, msg_num)
    )'
    );
    $pdo->exec('CREATE INDEX idx_invgate_ticket_comments_incident ON invgate_ticket_comments(incident_id)');
    echo "OK: tabla invgate_ticket_comments creada.\n";
} else {
    echo "OK: invgate_ticket_comments ya existe.\n";
}
