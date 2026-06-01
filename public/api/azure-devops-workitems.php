<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\AzureWorkItemRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\AzureDevOpsBoards;
use App\Support\AzureDevOpsFinalStates;

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

    if ($org === '' || $project === '' || $pat === '') {
        echo json_encode([
            'ok' => true,
            'configured' => false,
            'columns' => [],
            'hint' => 'Configurá organization, project y pat (token PAT) en config/config.php, clave azure_devops.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finalStatesConfig = isset($az['final_states']) && is_array($az['final_states']) ? $az['final_states'] : null;
    $finalStates = new AzureDevOpsFinalStates($finalStatesConfig);
    $boards = AzureDevOpsBoards::resolve($az);
    $boardParam = isset($_GET['board']) && is_string($_GET['board']) ? trim($_GET['board']) : '';
    $boardId = $boards->resolveBoardId($boardParam !== '' ? $boardParam : null);

    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='azure_work_items'"
    )->fetchColumn();
    if (!$tableExists) {
        echo json_encode([
            'ok' => true,
            'configured' => true,
            'source' => 'database',
            'organization' => $org,
            'project' => $project,
            'columns' => [],
            'boards' => $boards->boardSummaries(),
            'board' => $boards->boardMeta($boardId),
            'empty' => true,
            'hint' => 'Ejecutá la migración: php database/migrate_azure_work_items.php y luego php database/sync_azure_work_items.php',
            'sync_meta' => ['last_synced_at' => null, 'item_count' => 0],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $repo = new AzureWorkItemRepository($pdo);
    $board = $repo->listGroupedByBoard($boardId, $finalStates, $boards);
    $syncMeta = $board['sync_meta'];
    $empty = !$repo->hasAnyRows();

    $payload = [
        'ok' => true,
        'configured' => true,
        'source' => 'database',
        'organization' => $org,
        'project' => $project,
        'board' => $board['board'],
        'boards' => $boards->boardSummaries(),
        'columns' => $board['columns'],
        'sync_meta' => $syncMeta,
    ];

    if ($empty) {
        $payload['empty'] = true;
        $payload['hint'] = 'Aún no hay work items en la base local. Ejecutá: php database/sync_azure_work_items.php';
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
