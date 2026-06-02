<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\PersonManagementRecommendationRepository;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\ManagementPeriod;

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
    if (!$teams->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }

    $periodKey = isset($_GET['period']) ? trim((string) $_GET['period']) : 'current';
    if (!in_array($periodKey, ['current', 'previous'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'period debe ser current o previous']);
        exit;
    }

    $period = ManagementPeriod::resolve($periodKey === 'previous' ? 'previous' : 'current');
    $peopleRepo = new TeamPersonRepository($pdo);
    $recRepo = new PersonManagementRecommendationRepository($pdo);

    $people = $peopleRepo->listByTeam($teamId);
    $summaries = $recRepo->listSummariesForTeamPeriod($teamId, $period['period_start']);

    /** @var array<int, array<string, mixed>> */
    $byPersonId = [];
    foreach ($summaries as $row) {
        $byPersonId[(int) $row['person_id']] = $row;
    }

    $cards = [];
    foreach ($people as $person) {
        $personId = (int) ($person['id'] ?? 0);
        if ($personId < 1) {
            continue;
        }
        $rec = $byPersonId[$personId] ?? null;
        $cards[] = [
            'person_id' => $personId,
            'display_name' => (string) ($person['display_name'] ?? ''),
            'role' => $person['role'] ?? null,
            'is_direct_team' => !empty($person['is_direct_team']),
            'has_recommendation' => $rec !== null && ($rec['status'] ?? '') === 'ok',
            'summary_preview' => $rec !== null && ($rec['summary'] ?? '') !== ''
                ? (static function (string $text): string {
                    $text = trim($text);
                    if (strlen($text) <= 160) {
                        return $text;
                    }

                    return rtrim(substr($text, 0, 159)) . '…';
                })((string) $rec['summary'])
                : null,
            'risk_level' => $rec['risk_level'] ?? null,
            'status' => $rec['status'] ?? null,
        ];
    }

    echo json_encode([
        'ok' => true,
        'people' => $cards,
        'meta' => [
            'period_key' => $periodKey,
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
