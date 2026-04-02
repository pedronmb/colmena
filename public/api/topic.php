<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TopicRepository;
use App\Repositories\UserRepository;
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

    $teams = new TeamRepository($pdo);
    $repo = new TopicRepository($pdo);

    if ($method === 'GET') {
        $topicId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
        if ($topicId < 1 || $teamId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'id y team_id son obligatorios']);
            exit;
        }
        if (!$teams->isMember($teamId, $userId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
            exit;
        }
        $topicTeamId = $repo->getTeamIdForTopic($topicId);
        if ($topicTeamId === null || $topicTeamId !== $teamId) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Tema no encontrado']);
            exit;
        }
        $topic = $repo->findById($topicId);
        echo json_encode(['ok' => true, 'topic' => $topic->toArray()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'PATCH') {
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

    $topicId = isset($data['topic_id']) ? (int) $data['topic_id'] : 0;
    $teamId = isset($data['team_id']) ? (int) $data['team_id'] : 0;

    if ($topicId < 1 || $teamId < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'topic_id y team_id son obligatorios']);
        exit;
    }

    if (!$teams->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }

    $topicTeamId = $repo->getTeamIdForTopic($topicId);
    if ($topicTeamId === null || $topicTeamId !== $teamId) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Tema no encontrado']);
        exit;
    }

    if (isset($data['title'])) {
        $title = trim((string) $data['title']);
        $body = isset($data['body']) ? trim((string) $data['body']) : '';
        if ($body === '') {
            $body = null;
        }
        $priority = isset($data['priority']) ? (string) $data['priority'] : 'medium';
        $importance = isset($data['importance']) ? (string) $data['importance'] : 'medium';
        $personId = isset($data['person_id']) ? (int) $data['person_id'] : 0;

        if (!in_array($priority, TopicScales::PRIORITY_LEVELS, true)) {
            $priority = 'medium';
        }
        if (!in_array($importance, TopicScales::IMPORTANCE_LEVELS, true)) {
            $importance = 'medium';
        }

        if ($title === '' || $personId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'title y person_id son obligatorios']);
            exit;
        }

        $peopleRepo = new TeamPersonRepository($pdo);
        if (!$peopleRepo->belongsToTeam($personId, $teamId)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'La persona no pertenece a este equipo']);
            exit;
        }

        $topic = $repo->updateContent($topicId, $teamId, $personId, $title, $body, $priority, $importance);
        echo json_encode(['ok' => true, 'topic' => $topic->toArray()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = null;
    if (array_key_exists('completed', $data)) {
        $completed = $data['completed'];
        if (is_bool($completed)) {
            $status = $completed ? 'done' : 'open';
        } elseif ($completed === 1 || $completed === '1' || $completed === 'true') {
            $status = 'done';
        } elseif ($completed === 0 || $completed === '0' || $completed === 'false') {
            $status = 'open';
        }
    }
    if ($status === null && isset($data['status'])) {
        $status = (string) $data['status'];
    }

    if ($status === null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Indica los campos del tema (title) o completed/status']);
        exit;
    }

    $topic = $repo->updateStatus($topicId, $status);
    echo json_encode(['ok' => true, 'topic' => $topic->toArray()], JSON_UNESCAPED_UNICODE);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
