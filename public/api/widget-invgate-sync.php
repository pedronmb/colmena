<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\InvgateSyncCoordinator;

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    if (!is_file($config['db']['path'])) {
        $respond(503, ['ok' => false, 'error' => 'Base de datos no disponible']);
        exit;
    }

    $auth = new AuthService(new UserRepository(Connection::get($config)));
    $user = $auth->currentUser();
    if ($user === null) {
        $respond(401, ['ok' => false, 'error' => 'Debes iniciar sesión']);
        exit;
    }

    $sessionToken = $_SESSION['widget_invgate_sync_csrf'] ?? null;
    $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($user['role'] !== 'admin' || !InvgateSyncCoordinator::isEnabled($config)
        || !is_string($sessionToken) || !hash_equals($sessionToken, $requestToken)) {
        $respond(403, ['ok' => false, 'error' => 'Sin permiso para solicitar la sincronización']);
        exit;
    }
    session_write_close();

    $state = (new InvgateSyncCoordinator($config['db']['path']))->request(time());
    $respond($state === 'queued' ? 202 : 200, ['ok' => true, 'state' => $state]);
} catch (Throwable $error) {
    error_log('Colmena widget InvGate: ' . $error->getMessage());
    $respond(500, ['ok' => false, 'error' => 'No se pudo solicitar la sincronización']);
}
