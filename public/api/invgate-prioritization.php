<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\InvgateTicketPriorityRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\InvgatePrioritizationPdf;

try {
    if (!is_file($config['db']['path'])) {
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
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'PUT'], true)) {
        http_response_code(405);
        header('Allow: GET, PUT');
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }
    $data = $_GET;
    if ($method === 'PUT') {
        $data = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
            exit;
        }
    }
    $teamId = filter_var($data['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($teamId === false) {
        throw new InvalidArgumentException('team_id es obligatorio.');
    }
    if (!(new TeamRepository($pdo))->isMember($teamId, $userId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No perteneces a ese equipo']);
        exit;
    }
    $repo = new InvgateTicketPriorityRepository($pdo);
    $format = $data['format'] ?? 'json';
    if ($method === 'PUT' || $format === 'pdf') {
        $personId = filter_var($data['person_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            throw new InvalidArgumentException('person_id es obligatorio.');
        }
        if ($method === 'PUT') {
            if (!isset($data['priorities']) || !is_array($data['priorities'])) {
                throw new InvalidArgumentException('priorities es obligatorio.');
            }
            $repo->saveForPerson($teamId, $personId, $data['priorities']);
        }
        $group = $repo->findGroup($teamId, $personId);
        if ($group === null) {
            throw new OutOfBoundsException('Persona no encontrada.');
        }
        if ($method === 'GET') {
            $pdf = (new InvgatePrioritizationPdf())->render($group['person'], $group['tickets']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="priorizacion-persona-' . $personId . '.pdf"');
            header('Content-Length: ' . strlen($pdf));
            header('X-Content-Type-Options: nosniff');
            echo $pdf;
        } else {
            echo json_encode(['ok' => true, 'group' => $group], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    if ($format !== 'json') {
        throw new InvalidArgumentException('Formato inválido.');
    }
    echo json_encode(['ok' => true, 'groups' => $repo->listGroupedByTeam($teamId)], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
