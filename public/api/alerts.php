<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\AlertRepository;
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

    $teams = new TeamRepository($pdo);
    $repo = new AlertRepository($pdo);

    if ($method === 'GET') {
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
        $list = $repo->listForTeam($teamId);
        echo json_encode(['ok' => true, 'alerts' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
            exit;
        }
        $teamId = isset($data['team_id']) ? (int) $data['team_id'] : 0;
        $title = isset($data['title']) ? trim((string) $data['title']) : '';
        $body = isset($data['body']) ? trim((string) $data['body']) : null;
        if ($body === '') {
            $body = null;
        }
        $dueDate = isset($data['due_date']) ? trim((string) $data['due_date']) : '';

        if ($teamId < 1 || $title === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'team_id y title son obligatorios']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'due_date debe ser YYYY-MM-DD']);
            exit;
        }
        if (!$teams->isMember($teamId, $userId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
            exit;
        }

        $id = $repo->create($teamId, $userId, $title, $body, $dueDate);
        $list = $repo->listForTeam($teamId);
        $created = null;
        foreach ($list as $a) {
            if ($a['id'] === $id) {
                $created = $a;
                break;
            }
        }
        echo json_encode(['ok' => true, 'alert' => $created], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
            exit;
        }
        $teamId = isset($data['team_id']) ? (int) $data['team_id'] : 0;
        $alertId = isset($data['alert_id']) ? (int) $data['alert_id'] : 0;
        if ($teamId < 1 || $alertId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'team_id y alert_id son obligatorios']);
            exit;
        }
        if (!$teams->isMember($teamId, $userId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
            exit;
        }
        $ok = $repo->delete($alertId, $teamId);
        if (!$ok) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Alerta no encontrada']);
            exit;
        }
        echo json_encode(['ok' => true, 'deleted' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
