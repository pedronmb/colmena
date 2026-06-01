<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\AzureWorkItemRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\AzureDevOpsFinalStates;

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

    $az = is_array($config['azure_devops'] ?? null) ? $config['azure_devops'] : [];
    $org = trim((string) ($az['organization'] ?? ''));
    $project = trim((string) ($az['project'] ?? ''));
    $pat = trim((string) ($az['pat'] ?? ''));

    if ($org === '' || $project === '' || $pat === '') {
        echo json_encode([
            'ok' => true,
            'configured' => false,
            'groups' => [],
            'others_work_items' => [],
            'hint' => 'Configurá organization, project y pat en config/config.php, clave azure_devops.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $teams = new TeamRepository($pdo);
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    if ($teamId < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'team_id es obligatorio'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$teams->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='azure_work_items'"
    )->fetchColumn();
    if (!$tableExists) {
        echo json_encode([
            'ok' => true,
            'configured' => true,
            'groups' => [],
            'others_work_items' => [],
            'empty' => true,
            'hint' => 'Ejecutá php database/migrate_azure_work_items.php y php database/sync_azure_work_items.php',
            'meta' => ['work_item_total' => 0, 'people_count' => 0],
            'sync_meta' => ['last_synced_at' => null, 'item_count' => 0],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finalStatesConfig = isset($az['final_states']) && is_array($az['final_states']) ? $az['final_states'] : null;
    $finalStates = new AzureDevOpsFinalStates($finalStatesConfig);
    $repo = new AzureWorkItemRepository($pdo);
    $data = $repo->listGroupedByTeam($teamId, $finalStates);

    $payload = [
        'ok' => true,
        'configured' => true,
        'groups' => $data['groups'],
        'others_work_items' => $data['others_work_items'],
        'meta' => $data['meta'],
        'sync_meta' => $repo->getSyncMeta(),
    ];

    if (!$repo->hasAnyRows()) {
        $payload['empty'] = true;
        $payload['hint'] = 'Ejecutá php database/sync_azure_work_items.php para sincronizar work items.';
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
