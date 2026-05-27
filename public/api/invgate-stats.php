<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\InvgateStatsRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\InvgateStatsService;

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

    $periodRaw = isset($_GET['period']) ? trim((string) $_GET['period']) : '30';
    $periodDays = null;
    if ($periodRaw === '7') {
        $periodDays = 7;
    } elseif ($periodRaw === '30') {
        $periodDays = 30;
    } elseif ($periodRaw !== 'all') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'period debe ser 7, 30 o all']);
        exit;
    }

    $staleDays = isset($_GET['stale_days']) ? (int) $_GET['stale_days'] : 3;
    if ($staleDays < 1 || $staleDays > 90) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'stale_days debe estar entre 1 y 90']);
        exit;
    }

    $service = new InvgateStatsService(new InvgateStatsRepository($pdo));
    $data = $service->statsByTeam($teamId, [
        'period_days' => $periodDays,
        'stale_days' => $staleDays,
    ]);

    echo json_encode(
        array_merge(['ok' => true], $data),
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
