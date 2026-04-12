<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Repositories\UserScratchpadRepository;
use App\Services\AuthService;

const MAX_SCRATCHPAD_CHARS = 200000;

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
        echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $repo = new UserScratchpadRepository($pdo);

    if ($method === 'GET') {
        $content = $repo->getContent($userId);
        echo json_encode(['ok' => true, 'content' => $content], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT' || $method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $content = isset($data['content']) ? (string) $data['content'] : '';
        if (strlen($content) > MAX_SCRATCHPAD_CHARS) {
            http_response_code(422);
            echo json_encode(
                ['ok' => false, 'error' => 'El texto supera el límite permitido (' . MAX_SCRATCHPAD_CHARS . ' caracteres).'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        $repo->saveContent($userId, $content);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
