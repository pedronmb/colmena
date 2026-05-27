<?php

declare(strict_types=1);

/**
 * Sincroniza catálogos lookup desde InvGate:
 * - categorías (/categories)
 * - tipos (/incident.attributes.type)
 * - estados (/incident.attributes.status)
 *
 * php database/sync_invgate_catalog.php
 */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

use App\Database\Connection;
use App\Repositories\InvgateCatalogRepository;
use App\Services\InvgateCatalogSyncService;

$configPath = $base . '/config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "No existe config/config.php. Copiá config.php.default.\n");
    exit(1);
}

$config = require $configPath;
$dbPath = $config['db']['path'] ?? ($base . '/database/app.sqlite');
if (!is_string($dbPath) || !file_exists($dbPath)) {
    fwrite(STDERR, "No existe la base de datos configurada.\n");
    exit(1);
}

try {
    $pdo = Connection::get($config);
    $sync = InvgateCatalogSyncService::fromConfig(
        $config,
        new InvgateCatalogRepository($pdo)
    );
    $result = $sync->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'InvGate catalog sync' . "\n";
echo '  Categorías: fetched ' . $result['categories']['fetched'] . ' · upserted ' . $result['categories']['upserted'] . ' · skipped ' . $result['categories']['skipped'] . "\n";
echo '  Tipos:      fetched ' . $result['types']['fetched'] . ' · upserted ' . $result['types']['upserted'] . ' · skipped ' . $result['types']['skipped'] . "\n";
echo '  Estados:    fetched ' . $result['statuses']['fetched'] . ' · upserted ' . $result['statuses']['upserted'] . ' · skipped ' . $result['statuses']['skipped'] . "\n";

if ($result['errors'] !== []) {
    echo "  Errores:\n";
    foreach ($result['errors'] as $err) {
        echo '    - ' . $err['scope'] . ': ' . $err['error'] . "\n";
    }
}

exit($result['ok'] ? 0 : 1);

