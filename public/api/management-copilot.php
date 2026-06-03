<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\ManagementRecommendationRepository;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\ManagementPeriod;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!file_exists($config['db']['path'])) {
        throw new RuntimeException('Base de datos no inicializada.');
    }
    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    $userId = $auth->userId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión']);
        exit;
    }

    $teams = new TeamRepository($pdo);

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }

    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    if ($teamId < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'team_id es obligatorio']);
        exit;
    }
    if (!$teams->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }

    $periodKey = isset($_GET['period']) ? trim((string) $_GET['period']) : 'current';
    if (!in_array($periodKey, ['current', 'previous'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'period debe ser current o previous']);
        exit;
    }

    $period = ManagementPeriod::resolve($periodKey === 'previous' ? 'previous' : 'current');
    $repo = new ManagementRecommendationRepository($pdo);
    $row = $repo->findForTeamPeriod($teamId, $period['period_start']);

    $encargados = [];
    foreach ((new TeamPersonRepository($pdo))->listByTeam($teamId) as $person) {
        if (empty($person['is_encargado'])) {
            continue;
        }
        $name = trim((string) ($person['display_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $encargados[] = [
            'person_id' => (int) ($person['id'] ?? 0),
            'name' => $name,
            'role' => isset($person['role']) && $person['role'] !== '' ? (string) $person['role'] : null,
        ];
    }

    $meta = [
        'period_key' => $periodKey,
        'period_start' => $period['period_start'],
        'period_end' => $period['period_end'],
        'encargados' => $encargados,
        'scope' => 'direct',
        'has_recommendation' => false,
        'message' => 'Se generará en la próxima ejecución del cron (php database/generate_management_recommendations.php).',
    ];

    if ($row === null) {
        echo json_encode([
            'ok' => true,
            'recommendation' => null,
            'meta' => $meta,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $meta['has_recommendation'] = ($row['status'] ?? '') === 'ok';
    $meta['generated_at'] = $row['generated_at'] ?? null;
    $meta['model'] = $row['model'] ?? null;
    if (($row['status'] ?? '') === 'error') {
        $meta['message'] = $row['error_message'] ?? 'Error al generar la recomendación.';
    } elseif ($meta['has_recommendation']) {
        $meta['message'] = null;
    }

    echo json_encode([
        'ok' => true,
        'recommendation' => $row,
        'meta' => $meta,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
