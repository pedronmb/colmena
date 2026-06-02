<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\PersonManagementRecommendationRepository;
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
    $people = new TeamPersonRepository($pdo);

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }

    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    $personId = isset($_GET['person_id']) ? (int) $_GET['person_id'] : 0;
    if ($teamId < 1 || $personId < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'team_id y person_id son obligatorios']);
        exit;
    }
    if (!$teams->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }

    $person = $people->findById($personId);
    if ($person === null || (int) ($person['team_id'] ?? 0) !== $teamId) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Persona no encontrada en el equipo']);
        exit;
    }

    $periodKey = isset($_GET['period']) ? trim((string) $_GET['period']) : 'current';
    if (!in_array($periodKey, ['current', 'previous'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'period debe ser current o previous']);
        exit;
    }

    $period = ManagementPeriod::resolve($periodKey === 'previous' ? 'previous' : 'current');
    $repo = new PersonManagementRecommendationRepository($pdo);
    $row = $repo->findForPersonPeriod($teamId, $personId, $period['period_start']);

    $meta = [
        'period_key' => $periodKey,
        'period_start' => $period['period_start'],
        'period_end' => $period['period_end'],
        'has_recommendation' => false,
        'message' => 'Se generará en la próxima ejecución del cron.',
        'dashboard_url' => 'dashboard.php?panel=copiloto',
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
        $meta['message'] = $row['error_message'] ?? 'Error al generar.';
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
