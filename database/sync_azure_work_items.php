<?php

declare(strict_types=1);

/**
 * Sincroniza work items desde Azure DevOps hacia azure_work_items.
 * php database/sync_azure_work_items.php
 */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

use App\Database\Connection;
use App\Repositories\AzureWorkItemRepository;
use App\Repositories\TeamPersonRepository;
use App\Services\AzureDevOpsWorkItemSyncService;

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
    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='azure_work_items'"
    )->fetchColumn();
    if (!$tableExists) {
        fwrite(STDERR, "Falta la tabla azure_work_items. Ejecutá: php database/migrate_azure_work_items.php\n");
        exit(1);
    }

    $sync = AzureDevOpsWorkItemSyncService::fromConfig(
        $config,
        new AzureWorkItemRepository($pdo),
        new TeamPersonRepository($pdo)
    );
    $result = $sync->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Azure DevOps sync' . "\n";
echo '  Ítems guardados/actualizados:     ' . $result['items_upserted'] . "\n";
echo '  Actualizados a estado final:    ' . $result['items_updated_to_final'] . "\n";
echo '  Omitidos (final, sin fila local): ' . $result['items_skipped_final_new'] . "\n";
echo '  Omitidos (ya final local):        ' . $result['items_skipped_already_final'] . "\n";
echo '  Reconciliados:                    ' . $result['items_reconciled'] . "\n";
echo '  Marcados eliminados (404):        ' . $result['items_removed'] . "\n";

if ($result['errors'] !== []) {
    echo "  Errores:\n";
    foreach ($result['errors'] as $err) {
        echo '    - #' . $err['azure_id'] . ': ' . $err['error'] . "\n";
    }
}

exit($result['ok'] ? 0 : 1);
