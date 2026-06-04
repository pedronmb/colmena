<?php

declare(strict_types=1);

/**
 * Sincroniza tickets abiertos desde InvGate hacia invgate_tickets.
 * php database/sync_invgate_tickets.php
 */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

use App\Database\Connection;
use App\Repositories\InvgateTicketRepository;
use App\Repositories\TeamPersonRepository;
use App\Services\InvgateTicketSyncService;

use App\Support\ConfigLoader;

$config = ConfigLoader::load($base);
$dbPath = $config['db']['path'] ?? ($base . '/database/app.sqlite');
if (!is_string($dbPath) || !file_exists($dbPath)) {
    fwrite(STDERR, "No existe la base de datos configurada.\n");
    exit(1);
}

try {
    $pdo = Connection::get($config);
    $sync = InvgateTicketSyncService::fromConfig(
        $config,
        new TeamPersonRepository($pdo),
        new InvgateTicketRepository($pdo)
    );
    $result = $sync->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'InvGate sync' . "\n";
echo '  Personas con invgate_id: ' . $result['people_total'] . "\n";
echo '  Personas sincronizadas:  ' . $result['people_ok'] . "\n";
echo '  Tickets guardados:       ' . $result['tickets_upserted'] . "\n";
echo '  Tickets status actualizados: ' . $result['tickets_status_updated'] . "\n";
echo '  Tickets omitidos:        ' . $result['tickets_skipped'] . "\n";

if ($result['errors'] !== []) {
    echo "  Errores por persona:\n";
    foreach ($result['errors'] as $err) {
        echo '    - #' . $err['person_id'] . ' ' . $err['display_name'] . ': ' . $err['error'] . "\n";
    }
}

if ($result['people_total'] === 0) {
    echo "  (ninguna persona tiene invgate_id cargado)\n";
}

exit($result['ok'] ? 0 : 1);
