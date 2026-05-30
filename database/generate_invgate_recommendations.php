<?php

declare(strict_types=1);

/**
 * Genera resumen y recomendación IA por ticket abierto de InvGate.
 * php database/generate_invgate_recommendations.php
 */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

use App\Database\Connection;
use App\Repositories\InvgateTicketCommentRepository;
use App\Repositories\InvgateTicketRecommendationRepository;
use App\Repositories\InvgateTicketRepository;
use App\Services\InvgateRecommendationService;

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
    $service = InvgateRecommendationService::fromConfig(
        $config,
        new InvgateTicketRepository($pdo),
        new InvgateTicketCommentRepository($pdo),
        new InvgateTicketRecommendationRepository($pdo)
    );
    $result = $service->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "InvGate recommendations\n";
echo '  Tickets abiertos:     ' . $result['tickets_open'] . "\n";
echo '  Omitidos (sin cambios): ' . $result['tickets_skipped'] . "\n";
echo '  Procesados:           ' . $result['tickets_total'] . "\n";
echo '  Recomendaciones OK:   ' . $result['tickets_ok'] . "\n";
echo '  Recomendaciones error:' . $result['tickets_failed'] . "\n";

if ($result['errors'] !== []) {
    echo "  Errores por ticket:\n";
    foreach ($result['errors'] as $err) {
        echo '    - ticket #' . $err['ticket_id'] . ' (request ' . $err['request_id'] . '): '
            . $err['error'] . "\n";
    }
}

if ($result['tickets_open'] === 0) {
    echo "  (no hay tickets abiertos para procesar)\n";
}

exit($result['ok'] ? 0 : 1);
