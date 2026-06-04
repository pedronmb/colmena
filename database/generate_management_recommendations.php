<?php

declare(strict_types=1);

/**
 * Genera recomendaciones del Copiloto de Management por equipo y persona.
 * php database/generate_management_recommendations.php
 * php database/generate_management_recommendations.php --team-id=1
 */
$base = dirname(__DIR__);
require_once $base . '/src/Bootstrap.php';

\App\Bootstrap::registerAutoload($base);

use App\Database\Connection;
use App\Repositories\AlertRepository;
use App\Repositories\ManagementRecommendationRepository;
use App\Repositories\PersonManagementRecommendationRepository;
use App\Repositories\TeamHealthRepository;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TopicRepository;
use App\Services\InvgateRecommendationService;
use App\Services\ManagementContextBuilder;
use App\Services\ManagementRecommendationService;
use App\Services\TeamHealthService;
use App\Support\ConfigLoader;

$config = ConfigLoader::load($base);
if (!is_file($base . '/config/config.php')) {
    fwrite(STDERR, "Aviso: no existe config/config.php; usando config.php.default.\n");
}
$dbPath = $config['db']['path'] ?? ($base . '/database/app.sqlite');
if (!is_string($dbPath) || !file_exists($dbPath)) {
    fwrite(STDERR, "No existe la base de datos configurada.\n");
    exit(1);
}

$teamId = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--team-id=(\d+)$/', (string) $arg, $m) === 1) {
        $teamId = (int) $m[1];
    }
}

try {
    $pdo = Connection::get($config);
    $healthRepo = new TeamHealthRepository($pdo);
    $contextBuilder = new ManagementContextBuilder(
        new TeamHealthService($healthRepo),
        $healthRepo,
        new TopicRepository($pdo),
        new AlertRepository($pdo),
        new TeamPersonRepository($pdo),
        $pdo
    );
    $ollamaCfg = InvgateRecommendationService::parseOllamaConfig($config);
    fwrite(
        STDERR,
        '[Ollama] model=' . $ollamaCfg['model']
        . ' num_predict=' . $ollamaCfg['num_predict']
        . ' think=' . ($ollamaCfg['think'] ? 'true' : 'false')
        . ' timeout=' . $ollamaCfg['timeout'] . "\n"
    );

    $service = ManagementRecommendationService::fromConfig(
        $config,
        $contextBuilder,
        new ManagementRecommendationRepository($pdo),
        new PersonManagementRecommendationRepository($pdo),
        new TeamRepository($pdo)
    );
    $result = $service->run([
        'team_id' => $teamId > 0 ? $teamId : null,
        'stale_days' => 3,
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Management Copilot recommendations\n";
echo '  Equipos totales:      ' . $result['teams_total'] . "\n";
echo '  Equipos omitidos:     ' . $result['teams_skipped'] . "\n";
echo '  Equipos procesados:   ' . $result['teams_processed'] . "\n";
echo '  Equipos OK:           ' . $result['teams_ok'] . "\n";
echo '  Equipos error:        ' . $result['teams_failed'] . "\n";
echo '  Personas OK:          ' . $result['people_ok'] . "\n";
echo '  Personas omitidas:    ' . $result['people_skipped'] . "\n";
echo '  Personas error:       ' . $result['people_failed'] . "\n";

if ($result['errors'] !== []) {
    echo "  Errores:\n";
    foreach ($result['errors'] as $err) {
        $scope = (string) ($err['scope'] ?? 'team');
        $line = '    - equipo #' . ($err['team_id'] ?? '?');
        if (isset($err['person_id'])) {
            $line .= ' persona #' . $err['person_id'];
        }
        $line .= " ({$scope}): " . ($err['error'] ?? '');
        echo $line . "\n";
    }
}

exit($result['ok'] ? 0 : 1);
