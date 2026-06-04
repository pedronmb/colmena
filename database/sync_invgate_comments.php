<?php

declare(strict_types=1);

/**
 * Sincroniza comentarios de tickets desde InvGate hacia invgate_ticket_comments.
 * php database/sync_invgate_comments.php
 */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

use App\Database\Connection;
use App\Repositories\InvgateTicketCommentRepository;
use App\Repositories\InvgateTicketRepository;
use App\Services\InvgateCommentSyncService;
use App\Support\ConfigLoader;

$config = ConfigLoader::load($base);
$dbPath = $config['db']['path'] ?? ($base . '/database/app.sqlite');
if (!is_string($dbPath) || !file_exists($dbPath)) {
    fwrite(STDERR, "No existe la base de datos configurada.\n");
    exit(1);
}

try {
    $pdo = Connection::get($config);
    $sync = InvgateCommentSyncService::fromConfig(
        $config,
        new InvgateTicketRepository($pdo),
        new InvgateTicketCommentRepository($pdo)
    );
    $result = $sync->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'InvGate comments sync' . "\n";
echo '  Tickets en base:         ' . $result['tickets_total'] . "\n";
echo '  Tickets sincronizados:   ' . $result['tickets_ok'] . "\n";
echo '  Comentarios nuevos:      ' . $result['comments_inserted'] . "\n";
echo '  Comentarios omitidos:    ' . $result['comments_skipped'] . "\n";

if ($result['errors'] !== []) {
    echo "  Errores por ticket:\n";
    foreach ($result['errors'] as $err) {
        echo '    - ticket #' . $err['ticket_id'] . ' (request ' . $err['request_id'] . '): '
            . $err['error'] . "\n";
    }
}

if ($result['tickets_total'] === 0) {
    echo "  (no hay tickets; ejecutá primero database/sync_invgate_tickets.php)\n";
}

exit($result['ok'] ? 0 : 1);
