<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Repositories\TopicRepository;
use App\Services\AuthService;
use App\Support\TopicScales;

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

    if ($method === 'GET') {
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
        $includeDone = isset($_GET['include_done']) && $_GET['include_done'] === '1';
        $repo = new TopicRepository($pdo);
        $topics = $repo->listByTeam($teamId, $includeDone);
        $list = [];
        foreach ($topics as $t) {
            $list[] = $t->toArray();
        }
        echo json_encode(['ok' => true, 'topics' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
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

    $teamId = isset($data['team_id']) ? (int) $data['team_id'] : 0;
    $personId = isset($data['person_id']) ? (int) $data['person_id'] : 0;
    $title = isset($data['title']) ? trim((string) $data['title']) : '';
    $body = isset($data['body']) ? trim((string) $data['body']) : null;
    if ($body === '') {
        $body = null;
    }
    $priority = isset($data['priority']) ? (string) $data['priority'] : 'medium';
    $importance = isset($data['importance']) ? (string) $data['importance'] : 'medium';

    if (!in_array($priority, TopicScales::PRIORITY_LEVELS, true)) {
        $priority = 'medium';
    }
    if (!in_array($importance, TopicScales::IMPORTANCE_LEVELS, true)) {
        $importance = 'medium';
    }

    if ($teamId < 1 || $personId < 1 || $title === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'team_id, person_id y title son obligatorios']);
        exit;
    }

    $teams = new TeamRepository($pdo);
    if (!$teams->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }

    $peopleRepo = new TeamPersonRepository($pdo);
    if (!$peopleRepo->belongsToTeam($personId, $teamId)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'La persona no pertenece a este equipo']);
        exit;
    }

    $repo = new TopicRepository($pdo);
    $topic = $repo->create($teamId, $userId, $personId, $title, $body, $priority, $importance);
    echo json_encode(['ok' => true, 'topic' => $topic->toArray()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
