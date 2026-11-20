<?php

declare(strict_types=1);

/**
 * Sincroniza tickets abiertos desde InvGate hacia invgate_tickets.
 * php database/sync_invgate_tickets.php
 * php database/sync_invgate_tickets.php --widget-worker
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

use App\Database\Connection;
use App\Repositories\InvgateTicketRepository;
use App\Repositories\TeamPersonRepository;
use App\Services\InvgateSyncCoordinator;
use App\Services\InvgateTicketSyncService;

use App\Support\ConfigLoader;

$config = ConfigLoader::load($base);
$widgetWorker = in_array('--widget-worker', $argv, true);
if ($widgetWorker && !InvgateSyncCoordinator::isEnabled($config)) {
    exit(0);
}
$dbPath = $config['db']['path'] ?? ($base . '/database/app.sqlite');
if (!is_string($dbPath) || !file_exists($dbPath)) {
    fwrite(STDERR, "No existe la base de datos configurada.\n");
    exit(1);
}

try {
    $result = (new InvgateSyncCoordinator($dbPath))->run($widgetWorker, static function () use ($config): array {
        $pdo = Connection::get($config);
        return InvgateTicketSyncService::fromConfig(
            $config,
            new TeamPersonRepository($pdo),
            new InvgateTicketRepository($pdo)
        )->run();
    });
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result === null) {
    if (!$widgetWorker) {
        echo "Ya hay otra sincronización de InvGate en curso.\n";
    }
    exit(0);
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
