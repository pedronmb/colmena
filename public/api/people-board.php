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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

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

    $peopleRepo = new TeamPersonRepository($pdo);
    $cards = $peopleRepo->listByTeam($teamId);

    $topicRepo = new TopicRepository($pdo);
    $allTopics = $topicRepo->listByTeam($teamId);

    $byPerson = [];
    $unassigned = [];
    foreach ($allTopics as $t) {
        $row = $t->toArray();
        $pid = isset($row['person_id']) && $row['person_id'] !== null
            ? (int) $row['person_id']
            : 0;
        if ($pid < 1) {
            $unassigned[] = $row;
            continue;
        }
        if (!isset($byPerson[$pid])) {
            $byPerson[$pid] = [];
        }
        $byPerson[$pid][] = $row;
    }

    $people = [];
    foreach ($cards as $person) {
        $people[] = [
            'person' => $person,
            'topics' => $byPerson[$person['id']] ?? [],
        ];
    }

    echo json_encode([
        'ok' => true,
        'people' => $people,
        'unassigned_topics' => $unassigned,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
