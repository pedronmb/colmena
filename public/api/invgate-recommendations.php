<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\InvgateTicketRecommendationRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

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

    $teams = new TeamRepository($pdo);
    if (!$teams->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }

    $repo = new InvgateTicketRecommendationRepository($pdo);
    $tickets = $repo->listForTeam($teamId);
    $withRecommendation = 0;
    foreach ($tickets as $ticket) {
        if (!empty($ticket['has_recommendation'])) {
            $withRecommendation++;
        }
    }

    echo json_encode([
        'ok' => true,
        'tickets' => $tickets,
        'meta' => [
            'ticket_total' => count($tickets),
            'with_recommendation' => $withRecommendation,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
