<?php

declare(strict_types=1);

/** Prioridades manuales locales, independientes del sync de InvGate. */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';
\App\Bootstrap::registerAutoload($base);
$config = \App\Support\ConfigLoader::load($base);
if (!is_file($config['db']['path'])) {
    fwrite(STDERR, "No existe la base de datos configurada.\n");
    exit(1);
}
$pdo = \App\Database\Connection::get($config);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS invgate_ticket_priorities (
        ticket_id INTEGER PRIMARY KEY REFERENCES invgate_tickets(id) ON DELETE CASCADE,
        priority INTEGER NOT NULL CHECK (typeof(priority) = \'integer\' AND priority BETWEEN 1 AND 2147483647)
    )'
);
echo "OK: tabla invgate_ticket_priorities disponible.\n";
