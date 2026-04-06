<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\AzureDevOpsClient;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!file_exists($config['db']['path'])) {
        throw new RuntimeException('Base de datos no inicializada.');
    }
    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    if ($auth->userId() === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $az = is_array($config['azure_devops'] ?? null) ? $config['azure_devops'] : [];
    $org = trim((string) ($az['organization'] ?? ''));
    $project = trim((string) ($az['project'] ?? ''));
    $pat = trim((string) ($az['pat'] ?? ''));
    $maxItems = isset($az['max_items']) ? (int) $az['max_items'] : 200;
    $wiqlConfig = $az['wiql'] ?? null;
    $wiql = is_string($wiqlConfig) && trim($wiqlConfig) !== '' ? trim($wiqlConfig) : null;

    if ($org === '' || $project === '' || $pat === '') {
        echo json_encode([
            'ok' => true,
            'configured' => false,
            'columns' => [],
            'hint' => 'Configurá organization, project y pat (token PAT) en config/config.php, clave azure_devops.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $client = new AzureDevOpsClient($org, $project, $pat, $maxItems);
    $result = $client->fetchGroupedByState($wiql);
    echo json_encode([
        'ok' => true,
        'configured' => true,
        'organization' => $org,
        'project' => $project,
        'columns' => $result['columns'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
