<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!file_exists($config['db']['path'])) {
        throw new RuntimeException('Base de datos no inicializada.');
    }
    $pdo = Connection::get($config);
    $usersRepo = new UserRepository($pdo);
    $auth = new AuthService($usersRepo);
    $userId = $auth->userId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión']);
        exit;
    }

    $current = $auth->currentUser();
    if ($current === null || $current['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo los administradores pueden gestionar usuarios']);
        exit;
    }

    if ($method === 'GET') {
        $list = $usersRepo->listAll();
        echo json_encode(['ok' => true, 'users' => $list], JSON_UNESCAPED_UNICODE);
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

    $email = isset($data['email']) ? trim((string) $data['email']) : '';
    $displayName = isset($data['display_name']) ? trim((string) $data['display_name']) : '';
    $password = isset($data['password']) ? (string) $data['password'] : '';
    $role = isset($data['role']) ? trim((string) $data['role']) : 'member';
    $availability = isset($data['availability']) ? trim((string) $data['availability']) : 'available';
    $teamId = isset($data['team_id']) ? (int) $data['team_id'] : 0;
    $roleInTeam = isset($data['role_in_team']) ? trim((string) $data['role_in_team']) : 'member';

    $allowedRoles = ['admin', 'lead', 'member', 'viewer'];
    $allowedAvail = ['available', 'busy', 'away', 'offline'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'member';
    }
    if (!in_array($availability, $allowedAvail, true)) {
        $availability = 'available';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Email no válido']);
        exit;
    }
    if ($displayName === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'El nombre es obligatorio']);
        exit;
    }
    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres']);
        exit;
    }

    if ($usersRepo->emailExists($email)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Ya existe un usuario con ese email']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $allowedTeamRoles = ['owner', 'lead', 'member'];
    if (!in_array($roleInTeam, $allowedTeamRoles, true)) {
        $roleInTeam = 'member';
    }

    $teams = new TeamRepository($pdo);
    $addToTeam = $teamId > 0 && $teams->teamExists($teamId);

    try {
        $pdo->beginTransaction();
        $newId = $usersRepo->create($email, $displayName, $hash, $role, $availability);
        if ($addToTeam) {
            $teams->addMember($teamId, $newId, $roleInTeam);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e->getMessage();
        $code = $e->getCode();
        if (
            stripos($msg, 'UNIQUE') !== false
            || $code === '23000'
            || $code === 19
        ) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Ya existe un usuario con ese email']);
            exit;
        }
        throw $e;
    }

    $created = $usersRepo->findById($newId);
    echo json_encode(['ok' => true, 'user' => $created], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
