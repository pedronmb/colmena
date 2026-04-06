<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\BirthdayNormalizer;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!file_exists($config['db']['path'])) {
        throw new RuntimeException('Base de datos no inicializada.');
    }
    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    $actorId = $auth->userId();
    if ($actorId === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión']);
        exit;
    }

    $teams = new TeamRepository($pdo);
    $peopleRepo = new TeamPersonRepository($pdo);

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
        if ($id < 1 || $teamId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'id y team_id son obligatorios']);
            exit;
        }
        if (!$teams->isMember($teamId, $actorId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
            exit;
        }
        $person = $peopleRepo->findById($id);
        if ($person === null || (int) $person['team_id'] !== $teamId) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Persona no encontrada']);
            exit;
        }
        echo json_encode(['ok' => true, 'person' => $person], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'PUT') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
        exit;
    }

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $teamId = isset($data['team_id']) ? (int) $data['team_id'] : 0;
    $displayName = isset($data['display_name']) ? trim((string) $data['display_name']) : '';
    $email = isset($data['email']) ? trim((string) $data['email']) : '';
    if ($email === '') {
        $email = null;
    }
    $extraInfo = isset($data['extra_info']) ? trim((string) $data['extra_info']) : '';
    if ($extraInfo === '') {
        $extraInfo = null;
    }

    $role = isset($data['role']) ? trim((string) $data['role']) : '';
    if ($role === '') {
        $role = null;
    }

    if ($id < 1 || $teamId < 1 || $displayName === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'id, team_id y display_name son obligatorios']);
        exit;
    }

    if (!$teams->isMember($teamId, $actorId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }

    if (!$peopleRepo->belongsToTeam($id, $teamId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Persona no encontrada']);
        exit;
    }

    $bRaw = $data['birthday'] ?? null;
    $birthday = BirthdayNormalizer::optional($bRaw);
    if ($birthday === false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Fecha de cumpleaños inválida (usa MM-DD, mes y día)']);
        exit;
    }

    $peopleRepo->update($id, $displayName, $email, $role, $birthday, $extraInfo);
    $updated = $peopleRepo->findById($id);

    echo json_encode(['ok' => true, 'person' => $updated], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
